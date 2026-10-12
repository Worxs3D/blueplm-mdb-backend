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

    public static function acquireOrganizationCheckinLock(PDO $db, string $organizationId): ?string
    {
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new \RuntimeException('Database name is unavailable for check-in quota locking.');
        $lockName = 'blueplm:' . substr(hash('sha256', implode("\0", [$database, 'checkin-quota', strtolower($organizationId)])), 0, 56);
        $query = $db->prepare('SELECT GET_LOCK(?, 5)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1 ? $lockName : null;
    }

    public static function acquireOrganizationCheckoutLock(PDO $db, string $organizationId): ?string
    {
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new \RuntimeException('Database name is unavailable for checkout quota locking.');
        $lockName = 'blueplm:' . substr(hash('sha256', implode("\0", [$database, 'checkout-quota', strtolower($organizationId)])), 0, 56);
        $query = $db->prepare('SELECT GET_LOCK(?, 5)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1 ? $lockName : null;
    }

    /**
     * Terminalize a bounded set of expired checkout state in this user's and
     * organization scope. Candidate expiry is only a hint: file then checkout
     * rows are locked and the live lease is revalidated before any mutation.
     */
    public static function terminalizeExpiredRequests(PDO $db, string $userId, string $organizationId, int $limit = 500): void
    {
        if ($limit < 2 || $limit > 500) throw new \InvalidArgumentException('Expired checkout sweep limit is outside its bound.');
        // Split the bounded budget across two index-backed streams so either
        // an expired lease or an orphaned due journal can trigger cleanup.
        $leaseLimit = intdiv($limit, 2);
        $requestLimit = $limit - $leaseLimit;
        $checkoutCandidates = $db->prepare(
            "SELECT c.file_id FROM checkouts c JOIN files f ON f.id = c.file_id
             WHERE c.expires_at <= UTC_TIMESTAMP(3)
               AND (c.user_id = ? OR f.organization_id = ?)
             ORDER BY c.expires_at, c.file_id LIMIT {$leaseLimit}"
        );
        $checkoutCandidates->execute([$userId, $organizationId]);
        $requestCandidates = $db->prepare(
            "SELECT r.file_id FROM checkout_acquisition_requests r
             WHERE r.status = 'active' AND r.expires_at <= UTC_TIMESTAMP(3)
               AND (r.user_id = ? OR r.organization_id = ?)
             ORDER BY r.expires_at, r.file_id LIMIT {$requestLimit}"
        );
        $requestCandidates->execute([$userId, $organizationId]);
        $fileIds = array_values(array_unique([
            ...array_column($checkoutCandidates->fetchAll(PDO::FETCH_ASSOC), 'file_id'),
            ...array_column($requestCandidates->fetchAll(PDO::FETCH_ASSOC), 'file_id'),
        ]));
        sort($fileIds, SORT_STRING);
        if ($fileIds === []) return;

        try {
            $db->beginTransaction();
            $fileQuery = $db->prepare('SELECT id, organization_id FROM files WHERE id = ? FOR UPDATE');
            $checkoutQuery = $db->prepare('SELECT user_id, token_hash, expires_at FROM checkouts WHERE file_id = ? FOR UPDATE');
            $expiredRequestsQuery = $db->prepare("SELECT token_hash FROM checkout_acquisition_requests WHERE file_id = ? AND status = 'active' AND expires_at <= UTC_TIMESTAMP(3) AND (user_id = ? OR organization_id = ?) FOR UPDATE");
            $deleteExpiredCheckout = $db->prepare('DELETE FROM checkouts WHERE file_id = ? AND token_hash = ? AND expires_at <= UTC_TIMESTAMP(3)');
            $now = $db->query('SELECT UTC_TIMESTAMP(3)')->fetchColumn();
            foreach ($fileIds as $fileId) {
                $fileQuery->execute([$fileId]);
                $file = $fileQuery->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($file === null) continue;

                // All checkout writers use file -> checkout -> journal order.
                $checkoutQuery->execute([$fileId]);
                $checkout = $checkoutQuery->fetch(PDO::FETCH_ASSOC) ?: null;
                $expiredRequestsQuery->execute([$fileId, $userId, $organizationId]);
                $expiredRequests = $expiredRequestsQuery->fetchAll(PDO::FETCH_COLUMN);

                $checkoutInScope = $checkout !== null
                    && ($checkout['user_id'] === $userId || $file['organization_id'] === $organizationId);
                if ($checkoutInScope && $checkout['expires_at'] <= $now) {
                    self::markTerminalByHash($db, $fileId, $checkout['token_hash'], 'expired');
                    $deleteExpiredCheckout->execute([$fileId, $checkout['token_hash']]);
                    $checkout = null;
                }

                foreach ($expiredRequests as $tokenHash) {
                    // A concurrent renew updates checkout and journal in one
                    // transaction. If that exact token still owns a live lease,
                    // preserve its encrypted replay receipt even if the stale
                    // candidate snapshot suggested expiry.
                    if ($checkout !== null && hash_equals($checkout['token_hash'], $tokenHash)
                        && $checkout['expires_at'] > $now) {
                        continue;
                    }
                    self::markTerminalByHash($db, $fileId, $tokenHash, 'expired');
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'Expired checkout cleanup is temporarily unavailable.');
        }
    }

    public static function acquireUserCheckinLock(PDO $db, string $userId): ?string
    {
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') throw new \RuntimeException('Database name is unavailable for check-in user quota locking.');
        $lockName = 'blueplm:' . substr(hash('sha256', implode("\0", [$database, 'checkin-user-quota', strtolower($userId)])), 0, 56);
        $query = $db->prepare('SELECT GET_LOCK(?, 5)');
        $query->execute([$lockName]);
        return (int)$query->fetchColumn() === 1 ? $lockName : null;
    }

    /** @param array<string, string> $env @param array<string, mixed> $principal @return array<string, mixed> */
    public static function acquireWithRequestId(PDO $db, array $env, array $principal, string $fileId, string $vaultId, string $clientWorkingPath, string $requestId): array
    {
        $requestId = strtolower($requestId);
        try {
            $retention = Runtime::checkoutRequestRetention($env);
        } catch (\RuntimeException) {
            throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'Durable checkout retention is temporarily unavailable.');
        }
        if (!self::supportsRequestIds($db)) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'This server has not enabled durable checkout recovery.');
        $requestLock = null;
        try {
            $requestLock = self::acquireRequestIdLock($db, 'checkout', $principal['organizationId'], $requestId);
        } catch (Throwable) {
            throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'Durable checkout recovery is temporarily unavailable.');
        }
        if ($requestLock === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_BUSY', 'Durable checkout recovery is busy; retry the same requestId.');
        $lifecycleLock = null;
        $organizationQuotaLock = null;
        $operationFailure = null;
        try {
            try {
                $lifecycleLock = self::acquireUserLifecycleLock($db, $principal['userId']);
            } catch (Throwable) {
                throw new CheckoutLeaseFailure(503, 'CHECKOUT_BUSY', 'Checkout is temporarily busy; retry the same requestId.');
            }
            if ($lifecycleLock === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_BUSY', 'Checkout is temporarily busy; retry the same requestId.');
            self::assertCheckoutPrincipalActive($db, $principal);
            try {
                $organizationQuotaLock = self::acquireOrganizationCheckoutLock($db, $principal['organizationId']);
            } catch (Throwable) {
                throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_UNAVAILABLE', 'Checkout request quota enforcement is temporarily unavailable.');
            }
            if ($organizationQuotaLock === null) throw new CheckoutLeaseFailure(503, 'CHECKOUT_RECOVERY_BUSY', 'Checkout request quota enforcement is busy; retry the same requestId.');
            // This is a separate maintenance transaction: only already-expired
            // leases can be terminalized, before the acquisition transaction
            // and its quota checks begin.
            self::terminalizeExpiredRequests($db, $principal['userId'], $principal['organizationId']);
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

            $cutoff = 'DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ' . $retention['ttlDays'] . ' DAY)';
            $requestQuery = $db->prepare('SELECT user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status, updated_at FROM checkout_acquisition_requests WHERE organization_id = ? AND request_id = ?');
            $requestQuery->execute([$principal['organizationId'], $requestId]);
            $request = $requestQuery->fetch();
            $expiredRequestId = $request
                && $request['status'] !== 'active'
                && $request['updated_at'] <= $db->query('SELECT ' . $cutoff)->fetchColumn();
            if ($request && !$expiredRequestId) {
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

            $activeOrRetainedTerminal = "(status = 'active' OR (status <> 'active' AND updated_at > {$cutoff}))";
            $counts = [
                ['SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id = ? AND ' . $activeOrRetainedTerminal, [$fileId], $retention['perFile'], 'file'],
                ['SELECT COUNT(*) FROM checkout_acquisition_requests WHERE user_id = ? AND ' . $activeOrRetainedTerminal, [$principal['userId']], $retention['perUser'], 'user'],
                ['SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id = ? AND ' . $activeOrRetainedTerminal, [$principal['organizationId']], $retention['perOrganization'], 'organization'],
            ];
            foreach ($counts as [$sql, $parameters, $limit, $scope]) {
                $countQuery = $db->prepare($sql);
                $countQuery->execute($parameters);
                if ((int)$countQuery->fetchColumn() >= $limit) {
                    throw new CheckoutLeaseFailure(429, 'CHECKOUT_REQUEST_QUOTA_EXCEEDED', 'The ' . $scope . ' checkout request limit has been reached; retry after terminal requests expire.');
                }
            }

            // Retain active requests regardless of row age: they hold recovery
            // tokens for live leases. Only terminal rows past their retention
            // window are eligible for bounded cleanup, after quota acceptance.
            $db->exec("DELETE FROM checkout_acquisition_requests WHERE status <> 'active' AND updated_at <= {$cutoff} ORDER BY updated_at ASC LIMIT 500");
            if ($expiredRequestId) {
                $deleteExpiredRequest = $db->prepare('DELETE FROM checkout_acquisition_requests WHERE organization_id = ? AND request_id = ? AND status <> \'active\' AND updated_at <= ' . $cutoff);
                $deleteExpiredRequest->execute([$principal['organizationId'], $requestId]);
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
            foreach ([$organizationQuotaLock, $lifecycleLock, $requestLock] as $lock) {
                if ($lock === null) continue;
                try {
                    if (!self::releaseRequestIdLock($db, $lock)) $releaseFailed = true;
                } catch (Throwable) {
                    $releaseFailed = true;
                }
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
            $query = $db->prepare('SELECT COUNT(DISTINCT m.migration) FROM schema_migrations m JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = \'checkout_acquisition_requests\' WHERE m.migration IN (?, ?)');
            $query->execute(['036_checkout_request_idempotency.sql', '039_checkout_request_retention_indexes.sql']);
            return (int)$query->fetchColumn() === 2;
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
