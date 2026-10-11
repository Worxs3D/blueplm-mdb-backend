<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use PDOException;
use Throwable;

final class CheckoutLeaseFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class FileCheckout
{
    public static function acquireUserLifecycleLock(PDO $db, string $userId): ?string
    {
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new \RuntimeException('Database name is unavailable for checkout lifecycle locking.');
        $lockName = 'blueplm:' . substr(hash('sha256', implode("\0", [$database, 'checkout-user-lifecycle', strtolower($userId)])), 0, 56);
        $query = $db->prepare('SELECT GET_LOCK(?, 5)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1 ? $lockName : null;
    }

    public static function releaseUserLifecycleLock(PDO $db, string $lockName): bool
    {
        $query = $db->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1;
    }

    public static function finishUserLifecycleLock(PDO $db, string $lockName, bool $committed, ?Throwable $operationFailure): void
    {
        try {
            $released = self::releaseUserLifecycleLock($db, $lockName);
        } catch (Throwable) {
            $released = false;
        }
        if (!$released && !$committed && $operationFailure === null) throw new \RuntimeException('User checkout lifecycle lock was not released.');
    }

    /** @param array<string, mixed> $principal */
    public static function assertCheckoutPrincipalActive(PDO $db, array $principal): void
    {
        $query = $db->prepare('SELECT 1 FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL LIMIT 1');
        $query->execute([$principal['userId'], $principal['organizationId']]);
        if (!$query->fetchColumn()) throw new CheckoutLeaseFailure(401, 'UNAUTHENTICATED', 'Session is invalid or expired.');
    }

    public static function acquireRequestIdLock(PDO $db, string $operation, string $organizationId, string $requestId): ?string
    {
        if (!in_array($operation, ['checkout', 'checkin'], true)) throw new \InvalidArgumentException('Invalid request-lock operation.');
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new \RuntimeException('Database name is unavailable for request locking.');
        $lockName = 'blueplm:' . substr(hash('sha256', implode("\0", [$database, $operation, strtolower($organizationId), strtolower($requestId)])), 0, 56);
        $query = $db->prepare('SELECT GET_LOCK(?, 5)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1 ? $lockName : null;
    }

    public static function releaseRequestIdLock(PDO $db, string $lockName): bool
    {
        $query = $db->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1;
    }

    /** @param array<string, string> $env @param array<string, mixed> $principal @return array<string, mixed> */
    public static function acquireWithRequestId(PDO $db, array $env, array $principal, string $fileId, string $vaultId, string $clientWorkingPath, string $requestId): array
    {
        $requestId = strtolower($requestId);
        if (!self::supportsRequestIds($db)) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'This server has not enabled durable checkout recovery.');
        try {
            $lifecycleLock = self::acquireUserLifecycleLock($db, $principal['userId']);
        } catch (Throwable) {
            throw new CheckoutLeaseFailure(503, 'CHECKOUT_BUSY', 'Checkout is temporarily busy; retry the same requestId.');
        }
        if ($lifecycleLock === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_BUSY', 'Checkout is temporarily busy; retry the same requestId.');
        $operationFailure = null;
        $requestLock = null;
        try {
            self::assertCheckoutPrincipalActive($db, $principal);
            try {
                $requestLock = self::acquireRequestIdLock($db, 'checkout', $principal['organizationId'], $requestId);
            } catch (Throwable) {
                throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'Durable checkout recovery is temporarily unavailable.');
            }
            if ($requestLock === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_BUSY', 'Durable checkout recovery is busy; retry the same requestId.');
            $fingerprint = hash('sha256', json_encode([
                'fileId' => $fileId,
                'vaultId' => $vaultId,
                'userId' => $principal['userId'],
                'clientWorkingPath' => $clientWorkingPath,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $db->beginTransaction();
            $fileLock = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE');
            $fileLock->execute([$fileId, $principal['organizationId']]);
            $file = $fileLock->fetch();
            if (!$file || $file['vault_id'] !== $vaultId) throw new CheckoutLeaseFailure(404, 'NOT_FOUND', 'File not found.');

            $requestQuery = $db->prepare('SELECT user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status FROM checkout_acquisition_requests WHERE organization_id = ? AND request_id = ?');
            $requestQuery->execute([$principal['organizationId'], $requestId]);
            $request = $requestQuery->fetch();
            if ($request) {
                if ($request['user_id'] !== $principal['userId']) throw new CheckoutLeaseFailure(404, 'NOT_FOUND', 'File not found.');
                $sameRequest = $request['file_id'] === $fileId
                    && $request['vault_id'] === $vaultId
                    && $request['client_working_path'] === $clientWorkingPath
                    && hash_equals($request['request_fingerprint'], $fingerprint);
                if (!$sameRequest) throw new CheckoutLeaseFailure(409, 'IDEMPOTENCY_KEY_REUSED', 'requestId was already used for a different checkout request.');

                if ($request['status'] !== 'active') {
                    throw new CheckoutLeaseFailure(409, 'CHECKOUT_REQUEST_TERMINAL', 'This checkout request is no longer active.');
                }

                // All checkout mutations serialize on the file row; locking a missing
                // checkout row here would take a PK gap lock shared across files.
                $leaseQuery = $db->prepare('SELECT user_id, token_hash, client_working_path, expires_at, (expires_at > UTC_TIMESTAMP(3)) AS lease_active FROM checkouts WHERE file_id = ?');
                $leaseQuery->execute([$fileId]);
                $lease = $leaseQuery->fetch();
                $isActiveLease = $lease
                    && $lease['user_id'] === $principal['userId']
                    && $lease['client_working_path'] === $clientWorkingPath
                    && hash_equals($lease['token_hash'], $request['token_hash'])
                    && $lease['expires_at'] === $request['expires_at']
                    && (int)$lease['lease_active'] === 1;
                if (!$isActiveLease || !is_string($request['token_ciphertext']) || $request['token_ciphertext'] === '') {
                    self::markTerminalByHash($db, $fileId, $request['token_hash'], 'expired');
                    $db->commit();
                    throw new CheckoutLeaseFailure(409, 'CHECKOUT_REQUEST_TERMINAL', 'This checkout request is no longer active.');
                }

                try {
                    $token = Runtime::decryptSecret($request['token_ciphertext'], $env);
                } catch (Throwable) {
                    $db->rollBack();
                    throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'The checkout cannot be safely recovered.');
                }
                if (!hash_equals($request['token_hash'], Runtime::tokenHash($token, $env))) {
                    $db->rollBack();
                    throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'The checkout cannot be safely recovered.');
                }
                $db->commit();
                return [
                    'checkoutToken' => $token,
                    'expiresAt' => self::formatExpiry($lease['expires_at']),
                    'replayed' => true,
                ];
            }

            $leaseQuery = $db->prepare('SELECT user_id, token_hash, expires_at, (expires_at > UTC_TIMESTAMP(3)) AS lease_active FROM checkouts WHERE file_id = ?');
            $leaseQuery->execute([$fileId]);
            $lease = $leaseQuery->fetch();
            if ($lease && (int)$lease['lease_active'] === 1) {
                throw new CheckoutLeaseFailure(409, 'CHECKED_OUT', 'File is checked out by another client.');
            }
            if ($lease) {
                self::markTerminalByHash($db, $fileId, $lease['token_hash'], 'expired');
                $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]);
            }

            $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $tokenHash = Runtime::tokenHash($token, $env);
            $expiresAt = Runtime::checkoutExpiry($env);
            $ciphertext = Runtime::encryptSecret($token, $env);
            $db->prepare('INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES (?, ?, ?, ?, ?)')->execute([$fileId, $principal['userId'], $tokenHash, $clientWorkingPath, $expiresAt]);
            $db->prepare('INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')')->execute([
                $principal['organizationId'], $requestId, $principal['userId'], $fileId, $vaultId,
                $clientWorkingPath, $fingerprint, $tokenHash, $ciphertext, $expiresAt,
            ]);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.checked_out', $fileId, ['userId' => $principal['userId'], 'expiresAt' => str_replace(' ', 'T', $expiresAt) . 'Z']);
            $db->commit();
            return ['checkoutToken' => $token, 'expiresAt' => str_replace(' ', 'T', $expiresAt) . 'Z', 'replayed' => false];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            $operationFailure = $error;
            throw $error;
        } finally {
            $releaseFailed = false;
            try {
                if ($requestLock !== null && !self::releaseRequestIdLock($db, $requestLock)) $releaseFailed = true;
            } catch (Throwable) {
                $releaseFailed = true;
            }
            try {
                if (!self::releaseUserLifecycleLock($db, $lifecycleLock)) $releaseFailed = true;
            } catch (Throwable) {
                $releaseFailed = true;
            }
            if ($releaseFailed && $operationFailure === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'The checkout result should be retried with the same requestId.');
        }
    }

    public static function markRenewed(PDO $db, string $fileId, string $tokenHash, string $expiresAt): void
    {
        try {
            $db->prepare("UPDATE checkout_acquisition_requests SET expires_at = ?, updated_at = UTC_TIMESTAMP(3) WHERE file_id = ? AND token_hash = ? AND status = 'active'")
                ->execute([$expiresAt, $fileId, $tokenHash]);
        } catch (PDOException $error) {
            if (!self::isMissingRequestTable($error)) throw $error;
        }
    }

    public static function markTerminalByHash(PDO $db, string $fileId, string $tokenHash, string $status): void
    {
        if (!in_array($status, ['cancelled', 'consumed', 'expired'], true)) throw new \InvalidArgumentException('Invalid checkout request terminal state.');
        try {
            $db->prepare('UPDATE checkout_acquisition_requests SET status = ?, token_ciphertext = NULL, updated_at = UTC_TIMESTAMP(3) WHERE file_id = ? AND token_hash = ? AND status = \'active\'')
                ->execute([$status, $fileId, $tokenHash]);
        } catch (PDOException $error) {
            if (!self::isMissingRequestTable($error)) throw $error;
        }
    }

    /** @return list<string> File IDs still owned by the user and terminalized. */
    public static function markUserCheckoutsTerminal(PDO $db, string $userId, ?string $organizationId = null): array
    {
        if ($organizationId === null) {
            $query = $db->prepare('SELECT file_id FROM checkouts WHERE user_id = ? ORDER BY file_id');
            $query->execute([$userId]);
        } else {
            $query = $db->prepare('SELECT c.file_id FROM checkouts c JOIN files f ON f.id = c.file_id WHERE c.user_id = ? AND f.organization_id = ? ORDER BY c.file_id');
            $query->execute([$userId, $organizationId]);
        }
        $terminalizedFileIds = [];
        $fileQuery = $organizationId === null
            ? $db->prepare('SELECT id FROM files WHERE id = ? FOR UPDATE')
            : $db->prepare('SELECT id FROM files WHERE id = ? AND organization_id = ? FOR UPDATE');
        $checkoutQuery = $db->prepare('SELECT user_id, token_hash FROM checkouts WHERE file_id = ? FOR UPDATE');
        foreach ($query->fetchAll() as $candidate) {
            $fileId = $candidate['file_id'];
            // Existing checkout writers lock files before checkouts. New
            // writers must preserve that order; revalidate current ownership
            // after taking the file lock because the initial scan is unlocked.
            if ($organizationId === null) {
                $fileQuery->execute([$fileId]);
            } else {
                $fileQuery->execute([$fileId, $organizationId]);
            }
            if (!$fileQuery->fetchColumn()) continue;

            $checkoutQuery->execute([$fileId]);
            $checkout = $checkoutQuery->fetch();
            if (!$checkout || strcasecmp($checkout['user_id'], $userId) !== 0) continue;

            self::markTerminalByHash($db, $fileId, $checkout['token_hash'], 'cancelled');
            $terminalizedFileIds[] = $fileId;
        }
        return $terminalizedFileIds;
    }

    public static function supportsRequestIds(PDO $db): bool
    {
        try {
            $query = $db->prepare('SELECT 1 FROM schema_migrations m JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = \'checkout_acquisition_requests\' WHERE m.migration = ? LIMIT 1');
            $query->execute(['036_checkout_request_idempotency.sql']);
            return (bool)$query->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private static function formatExpiry(string $expiresAt): string
    {
        return str_replace(' ', 'T', $expiresAt) . 'Z';
    }

    private static function isMissingRequestTable(PDOException $error): bool
    {
        return $error->getCode() === '42S02'
            && (int)($error->errorInfo[1] ?? 0) === 1146
            && str_contains($error->getMessage(), 'checkout_acquisition_requests');
    }
}
