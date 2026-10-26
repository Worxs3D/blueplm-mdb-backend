<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use Throwable;

final class VaultLifecycleFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $operationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class VaultLifecycle
{
    private const OPERATION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /** @return array<string, mixed> */
    public static function requestBody(): array
    {
        try {
            return Runtime::jsonBody();
        } catch (\JsonException|\InvalidArgumentException $error) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'A valid JSON object is required.', null, $error);
        }
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $body @return array<string, mixed> */
    public static function execute(PDO $db, array $principal, string $kind, string $vaultId, array $body): array
    {
        if (!in_array($kind, ['clear', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid Vault lifecycle operation.');
        }
        if (array_diff(array_keys($body), ['operationId', 'confirmationName']) !== []) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'Only operationId and confirmationName are accepted.');
        }
        $operationId = $body['operationId'] ?? null;
        $confirmationName = $body['confirmationName'] ?? null;
        if (!is_string($operationId) || !preg_match(self::OPERATION_ID_PATTERN, $operationId)) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'A UUID operationId is required.');
        }
        $operationId = strtolower($operationId);
        if (!is_string($confirmationName) || $confirmationName === '' || strlen($confirmationName) > 200) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'The exact current Vault name is required.');
        }
        if (!preg_match(self::OPERATION_ID_PATTERN, $vaultId)) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'A valid Vault ID is required.', $operationId);
        }
        $vaultId = strtolower($vaultId);
        $organizationId = (string)$principal['organizationId'];
        $userId = (string)$principal['userId'];
        $fingerprint = hash('sha256', json_encode(
            [$kind, $vaultId, $confirmationName, $userId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        try {
            $db->beginTransaction();

            // Organization first matches the default-Vault writer lock order.
            $organization = $db->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
            $organization->execute([$organizationId]);
            if (!$organization->fetchColumn()) {
                throw new VaultLifecycleFailure(404, 'NOT_FOUND', 'Organization not found.', $operationId);
            }
            self::requireFreshAdministrator($db, $organizationId, $userId, true, $operationId);

            // The org lock serializes concurrent same-key requests before lookup or insertion.
            $existingQuery = $db->prepare(
                'SELECT requested_by, operation_kind, vault_id, request_fingerprint, result_json
                 FROM vault_lifecycle_operations WHERE organization_id = ? AND operation_id = ? FOR UPDATE'
            );
            $existingQuery->execute([$organizationId, $operationId]);
            $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['requested_by'] !== $userId
                    || $existing['operation_kind'] !== $kind
                    || $existing['vault_id'] !== $vaultId
                    || !hash_equals($existing['request_fingerprint'], $fingerprint)) {
                    throw new VaultLifecycleFailure(409, 'IDEMPOTENCY_CONFLICT', 'operationId was already used for a different request.', $operationId);
                }
                $stored = json_decode($existing['result_json'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($stored)) {
                    throw new VaultLifecycleFailure(503, 'VAULT_OPERATION_UNAVAILABLE', 'The stored Vault operation result is unavailable.', $operationId);
                }
                $db->commit();
                return $stored;
            }

            $vaultQuery = $db->prepare('SELECT id, name FROM vaults WHERE id = ? AND organization_id = ? FOR UPDATE');
            $vaultQuery->execute([$vaultId, $organizationId]);
            $vault = $vaultQuery->fetch(PDO::FETCH_ASSOC);
            if (!$vault) throw new VaultLifecycleFailure(404, 'NOT_FOUND', 'Vault not found.', $operationId);
            if ($confirmationName !== $vault['name']) {
                throw new VaultLifecycleFailure(409, 'NAME_MISMATCH', 'The confirmation does not match the current Vault name.', $operationId);
            }

            // This set-to-self update obtains database row locks without materializing every file ID in PHP.
            // Explicitly assigning updated_at to itself prevents its ON UPDATE timestamp from changing.
            $fileLock = $db->prepare('UPDATE files SET updated_at = updated_at WHERE organization_id = ? AND vault_id = ?');
            $fileLock->execute([$organizationId, $vaultId]);

            // Current checkout writers lock file first, then checkout/request rows. The file locks above
            // serialize their completion; these bounded probes reject any live server lease or request.
            $checkoutLock = $db->prepare(
                'SELECT c.file_id FROM checkouts c
                 INNER JOIN files f ON f.id = c.file_id
                 WHERE f.organization_id = ? AND f.vault_id = ? AND c.expires_at > UTC_TIMESTAMP(3)
                 ORDER BY c.file_id LIMIT 1 FOR UPDATE'
            );
            $checkoutLock->execute([$organizationId, $vaultId]);
            $activeCheckout = $checkoutLock->fetchColumn();
            if ($activeCheckout !== false) {
                throw new VaultLifecycleFailure(409, 'ACTIVE_CHECKOUTS', 'Vault has an active checkout.', $operationId);
            }

            $requestLock = $db->prepare(
                "SELECT r.request_id FROM checkout_acquisition_requests r
                 INNER JOIN files f ON f.id = r.file_id
                 WHERE r.organization_id = ? AND r.vault_id = ? AND r.status = 'active' AND r.expires_at > UTC_TIMESTAMP(3)
                 ORDER BY r.file_id, r.request_id LIMIT 1 FOR UPDATE"
            );
            $requestLock->execute([$organizationId, $vaultId]);
            if ($requestLock->fetchColumn() !== false) {
                throw new VaultLifecycleFailure(409, 'ACTIVE_CHECKOUTS', 'Vault has an active checkout request.', $operationId);
            }

            $fileCount = self::countRows($db, 'files', $organizationId, $vaultId);
            $folderCount = self::countRows($db, 'folders', $organizationId, $vaultId);

            if ($kind === 'clear') {
                $db->prepare('DELETE FROM files WHERE organization_id = ? AND vault_id = ?')->execute([$organizationId, $vaultId]);
                $db->prepare('DELETE FROM folders WHERE organization_id = ? AND vault_id = ?')->execute([$organizationId, $vaultId]);
            } else {
                $deleteVault = $db->prepare('DELETE FROM vaults WHERE id = ? AND organization_id = ?');
                $deleteVault->execute([$vaultId, $organizationId]);
                if ($deleteVault->rowCount() !== 1) throw new VaultLifecycleFailure(404, 'NOT_FOUND', 'Vault not found.', $operationId);
            }

            $defaultQuery = $db->prepare('SELECT default_vault_id FROM organization_settings WHERE organization_id = ? FOR UPDATE');
            $defaultQuery->execute([$organizationId]);
            $defaultVaultId = $defaultQuery->fetchColumn();
            if ($defaultVaultId === false) $defaultVaultId = null;

            $response = [
                'operationId' => $operationId,
                'kind' => $kind,
                'state' => 'succeeded',
                'vaultId' => $vaultId,
                'vaultName' => $vault['name'],
                'defaultVaultId' => $defaultVaultId,
                'result' => ['deletedFiles' => $fileCount, 'deletedFolders' => $folderCount],
            ];
            $resultJson = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $db->prepare(
                'INSERT INTO vault_lifecycle_operations
                    (organization_id, operation_id, requested_by, operation_kind, vault_id, request_fingerprint, result_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$organizationId, $operationId, $userId, $kind, $vaultId, $fingerprint, $resultJson]);

            Runtime::emitEvent($db, $organizationId, 'vault.' . ($kind === 'clear' ? 'cleared' : 'deleted'), $vaultId, [
                'operationId' => $operationId,
                'userId' => $userId,
                'fileCount' => $fileCount,
                'folderCount' => $folderCount,
            ]);
            $db->commit();
            return $response;
        } catch (VaultLifecycleFailure $error) {
            $rollbackError = self::rollBack($db);
            if ($rollbackError !== null) {
                error_log('[BluePLM PHP API] Vault lifecycle rollback failed: ' . $rollbackError->getMessage());
                throw new VaultLifecycleFailure(
                    503,
                    'VAULT_OPERATION_UNKNOWN',
                    'The Vault operation outcome may be unknown; retain operationId. Status 404 is not proof of failure. Only a caller-directed repeat with the same request and operationId may resolve the outcome.',
                    $operationId,
                    $rollbackError,
                );
            }
            throw $error;
        } catch (Throwable $error) {
            $rollbackError = self::rollBack($db);
            error_log('[BluePLM PHP API] Vault lifecycle operation failed: ' . $error->getMessage());
            throw new VaultLifecycleFailure(
                503,
                'VAULT_OPERATION_UNKNOWN',
                'The Vault operation outcome may be unknown; retain operationId. Status 404 is not proof of failure. Only a caller-directed repeat with the same request and operationId may resolve the outcome.',
                $operationId,
                $rollbackError ?? $error,
            );
        }
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public static function status(PDO $db, array $principal, string $operationId): array
    {
        if (!preg_match(self::OPERATION_ID_PATTERN, $operationId)) {
            throw new VaultLifecycleFailure(400, 'INVALID_REQUEST', 'A UUID operationId is required.');
        }
        $operationId = strtolower($operationId);
        $organizationId = (string)$principal['organizationId'];
        $userId = (string)$principal['userId'];
        try {
            self::requireFreshAdministrator($db, $organizationId, $userId, false, $operationId);
            $query = $db->prepare(
                'SELECT result_json FROM vault_lifecycle_operations WHERE organization_id = ? AND operation_id = ?'
            );
            $query->execute([$organizationId, $operationId]);
            $result = $query->fetchColumn();
            if (!is_string($result)) {
                // 404 means only that no committed result is visible. It is not evidence that an
                // earlier transaction failed or is no longer running; callers retain unknown state.
                throw new VaultLifecycleFailure(404, 'NOT_FOUND', 'Vault operation result not found.', $operationId);
            }
            $decoded = json_decode($result, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new VaultLifecycleFailure(503, 'VAULT_OPERATION_STATUS_UNAVAILABLE', 'The Vault operation result is unavailable; retain operationId.', $operationId, $error);
        } catch (VaultLifecycleFailure $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new VaultLifecycleFailure(503, 'VAULT_OPERATION_STATUS_UNAVAILABLE', 'Vault operation status is unavailable; retain operationId.', $operationId, $error);
        }
        if (!is_array($decoded)) throw new VaultLifecycleFailure(503, 'VAULT_OPERATION_STATUS_UNAVAILABLE', 'The Vault operation result is unavailable; retain operationId.', $operationId);
        return $decoded;
    }

    private static function rollBack(PDO $db): ?Throwable
    {
        try {
            if ($db->inTransaction()) $db->rollBack();
            return null;
        } catch (Throwable $error) {
            return $error;
        }
    }

    private static function requireFreshAdministrator(PDO $db, string $organizationId, string $userId, bool $lock, ?string $operationId): void
    {
        $sql = 'SELECT m.role FROM users u
                INNER JOIN organization_memberships m ON m.user_id = u.id
                WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL';
        if ($lock) $sql .= ' FOR UPDATE';
        $query = $db->prepare($sql);
        $query->execute([$userId, $organizationId]);
        $role = $query->fetchColumn();
        if (!is_string($role)) throw new VaultLifecycleFailure(401, 'UNAUTHENTICATED', 'Session is invalid or expired.', $operationId);
        if (!in_array($role, ['owner', 'admin'], true)) {
            throw new VaultLifecycleFailure(403, 'FORBIDDEN', 'Administrator role required.', $operationId);
        }
    }

    private static function countRows(PDO $db, string $table, string $organizationId, string $vaultId): int
    {
        if (!in_array($table, ['files', 'folders'], true)) throw new \InvalidArgumentException('Invalid Vault lifecycle count table.');
        $query = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE organization_id = ? AND vault_id = ?");
        $query->execute([$organizationId, $vaultId]);
        return (int)$query->fetchColumn();
    }
}
