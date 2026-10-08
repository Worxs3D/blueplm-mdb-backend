<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use PDOException;
use Throwable;

final class FileCheckinFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class FileCheckin
{
    private const PENDING_METADATA_KEYS = ['part_number', 'description', 'revision', 'config_tabs', 'config_descriptions'];

    /** @return array<string, mixed> */
    public static function execute(PDO $db, array $env, array $principal, string $fileId, array $body): array
    {
        $explicitMode = array_key_exists('mode', $body);
        $mode = $explicitMode ? $body['mode'] : (array_key_exists('storageRelativePath', $body) ? 'content' : null);
        if (!in_array($mode, ['content', 'metadata-only'], true)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'mode must be content or metadata-only.');
        $canonicalPath = null; $fileName = null;
        if ($explicitMode) {
            if (!is_string($body['canonicalPath'] ?? null) || !is_string($body['fileName'] ?? null)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'canonicalPath and fileName are required for explicit check-in.');
            $canonicalPath = $body['canonicalPath']; $fileName = $body['fileName'];
            if (!self::isSafeCanonicalPath($canonicalPath) || strlen($canonicalPath) > 2048 || strlen($fileName) > 512 || $fileName === '' || str_contains($fileName, '/') || !hash_equals(basename($canonicalPath), $fileName)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'canonicalPath must be a safe normalized vault-relative path and fileName must match its basename.');
        }
        $requestId = $body['requestId'] ?? null;
        if ($explicitMode && (!is_string($requestId) || !self::isUuid($requestId))) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'requestId must be a UUID when mode is specified.');
        if ($requestId !== null && (!is_string($requestId) || !self::isUuid($requestId))) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'requestId must be a UUID.');
        if (!is_string($body['checkoutToken'] ?? null) || $body['checkoutToken'] === '') throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'checkoutToken is required.');

        $relativePath = null; $hash = null; $size = null;
        if ($mode === 'content') {
            if (!is_string($body['storageRelativePath'] ?? null) || trim($body['storageRelativePath']) === '') throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'storageRelativePath is required for content check-in.');
            $relativePath = ltrim(str_replace('\\', '/', trim($body['storageRelativePath'])), '/');
            if ($relativePath === '' || str_contains($relativePath, '../')) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'storageRelativePath must be a normalized vault-relative path.');
            if (array_key_exists('contentHash', $body) && $body['contentHash'] !== null && (!is_string($body['contentHash']) || !preg_match('/^[a-f0-9]{64}$/i', $body['contentHash']))) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'contentHash must be a SHA-256 hex string or null.');
            if (array_key_exists('sizeBytes', $body) && $body['sizeBytes'] !== null && (!is_int($body['sizeBytes']) || $body['sizeBytes'] < 0)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'sizeBytes must be a non-negative integer or null.');
            $hash = is_string($body['contentHash'] ?? null) ? strtolower($body['contentHash']) : null;
            $size = is_int($body['sizeBytes'] ?? null) ? $body['sizeBytes'] : null;
        } elseif (array_key_exists('storageRelativePath', $body) || array_key_exists('contentHash', $body) || array_key_exists('sizeBytes', $body)) {
            throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'metadata-only check-in cannot include content fields.');
        }

        $pending = $body['pendingMetadata'] ?? null;
        if ($pending !== null) {
            if (!self::isObject($pending)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'pendingMetadata must be an object or null.');
            foreach ($pending as $key => $value) {
                if (!in_array($key, self::PENDING_METADATA_KEYS, true)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'pendingMetadata contains an unsupported field.');
                if (in_array($key, ['config_tabs', 'config_descriptions'], true)) {
                    if (!self::isObject($value) || count($value) > 1000) throw new FileCheckinFailure(400, 'INVALID_REQUEST', $key . ' must be an object with at most 1000 entries.');
                    foreach ($value as $configuration => $entry) if (!is_string($configuration) || trim($configuration) === '' || strlen($configuration) > 255 || !is_string($entry) || strlen($entry) > 20000) throw new FileCheckinFailure(400, 'INVALID_REQUEST', $key . ' contains an invalid entry.');
                } elseif ($value !== null && (!is_string($value) || strlen($value) > ($key === 'description' ? 20000 : 512))) {
                    throw new FileCheckinFailure(400, 'INVALID_REQUEST', $key . ' must be a bounded string or null.');
                }
            }
        }
        $committedProperties = $body['committedCustomProperties'] ?? null;
        if ($committedProperties !== null && (!self::isObject($committedProperties) || strlen(json_encode((object)$committedProperties, JSON_THROW_ON_ERROR)) > 200000)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'committedCustomProperties must be a bounded object or null.');
        if (array_key_exists('comment', $body) && $body['comment'] !== null && !is_string($body['comment'])) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'comment must be a string or null.');
        $comment = is_string($body['comment'] ?? null) ? substr($body['comment'], 0, 4000) : null;
        $partNumberPresent = array_key_exists('partNumber', $body) || (is_array($pending) && array_key_exists('part_number', $pending));
        $partNumber = is_array($pending) && array_key_exists('part_number', $pending) ? $pending['part_number'] : ($body['partNumber'] ?? null);
        if ($partNumber !== null && !is_string($partNumber)) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'part_number must be a string or null.');
        $partNumber = is_string($partNumber) && trim($partNumber) !== '' ? trim($partNumber) : null;
        if ($partNumber !== null && strlen($partNumber) > 512) throw new FileCheckinFailure(400, 'INVALID_REQUEST', 'part_number is too long.');

        $fingerprint = $requestId === null ? null : self::fingerprint($body);
        $db->beginTransaction();
        try {
            // Serialize requestId lookups within a tenant. Locking a missing
            // request row alone can deadlock when concurrent check-ins race to
            // insert the same primary key for different files.
            if ($requestId !== null) {
                $organizationLock = $db->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
                $organizationLock->execute([$principal['organizationId']]);
            }
            $fileQuery = $db->prepare('SELECT * FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE');
            $fileQuery->execute([$fileId, $principal['organizationId']]); $file = $fileQuery->fetch();
            if (!$file) throw new FileCheckinFailure(404, 'NOT_FOUND', 'File not found.');
            Runtime::requireVault($db, $principal, $file['vault_id']);

            if ($requestId !== null) {
                $prior = $db->prepare('SELECT file_id, user_id, request_fingerprint, response FROM file_checkin_requests WHERE organization_id = ? AND request_id = ? FOR UPDATE');
                $prior->execute([$principal['organizationId'], $requestId]); $existing = $prior->fetch();
                if ($existing) {
                    if ($existing['file_id'] !== $fileId || $existing['user_id'] !== $principal['userId'] || !hash_equals($existing['request_fingerprint'], $fingerprint)) throw new FileCheckinFailure(409, 'IDEMPOTENCY_KEY_REUSED', 'requestId was already used with a different check-in request.');
                    $db->commit(); $response = json_decode($existing['response'], true, 512, JSON_THROW_ON_ERROR); $response['replayed'] = true; return $response;
                }
            }

            $leaseQuery = $db->prepare('SELECT token_hash, user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE');
            $leaseQuery->execute([$fileId]); $lease = $leaseQuery->fetch();
            if (!$lease || $lease['user_id'] !== $principal['userId'] || !hash_equals($lease['token_hash'], Runtime::tokenHash($body['checkoutToken'], $env))) throw new FileCheckinFailure(409, 'INVALID_CHECKOUT', 'A valid, unexpired checkout from this client is required.');

            $currentProperties = json_decode($file['custom_properties'] ?? 'null', true);
            if (!self::isObject($currentProperties)) $currentProperties = [];
            if (is_array($committedProperties)) $currentProperties = $committedProperties;
            if (is_array($pending)) {
                foreach (['config_tabs' => '_config_tabs', 'config_descriptions' => '_config_descriptions'] as $pendingKey => $storedKey) {
                    if (array_key_exists($pendingKey, $pending)) {
                        $base = $currentProperties[$storedKey] ?? []; if (!self::isObject($base)) $base = [];
                        $currentProperties[$storedKey] = (object)array_replace($base, $pending[$pendingKey]);
                    }
                }
            }
            $propertiesJson = $currentProperties === [] && $file['custom_properties'] === null && $committedProperties === null && !is_array($pending) ? null : json_encode((object)$currentProperties, JSON_THROW_ON_ERROR);
            $description = is_array($pending) && array_key_exists('description', $pending) ? $pending['description'] : $file['description'];
            $revisionLabel = is_array($pending) && array_key_exists('revision', $pending) ? $pending['revision'] : $file['revision_label'];
            $nextRevision = (int)$file['current_revision'];
            $persistedCanonicalPath = $canonicalPath ?? $file['canonical_path'];
            $persistedFileName = $fileName ?? $file['file_name'];
            $canonicalLocationChanged = $persistedCanonicalPath !== $file['canonical_path'] || $persistedFileName !== $file['file_name'];

            if ($mode === 'content') {
                $nextRevision++;
                $storedPartNumber = $partNumberPresent ? $partNumber : $file['part_number'];
                $db->prepare('UPDATE files SET canonical_path = ?, file_name = ?, current_revision = ?, storage_relative_path = ?, content_hash = ?, size_bytes = ?, part_number = ?, description = ?, revision_label = ?, custom_properties = ? WHERE id = ?')->execute([$persistedCanonicalPath, $persistedFileName, $nextRevision, $relativePath, $hash, $size, $storedPartNumber, $description, $revisionLabel, $propertiesJson, $fileId]);
                $revisionId = Runtime::uuid();
                $db->prepare('INSERT INTO file_revisions (id, file_id, revision_number, content_hash, storage_relative_path, size_bytes, checked_in_by, comment, part_number, description, revision_label, custom_properties) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$revisionId, $fileId, $nextRevision, $hash, $relativePath, $size, $principal['userId'], $comment, $storedPartNumber, $description, $revisionLabel, $propertiesJson]);
                $db->prepare('INSERT INTO inspection_characteristic_versions (id, file_revision_id, organization_id, sort_order, balloon_number, char_id, zone, char_type, sub_type, nominal_value, unit, plus_tolerance, minus_tolerance, upper_limit, lower_limit, classification, inspection_method, operation, aql, sample_size, supplier_inspection_rate, internal_inspection_rate, reference, comments) SELECT UUID(), ?, organization_id, sort_order, balloon_number, char_id, zone, char_type, sub_type, nominal_value, unit, plus_tolerance, minus_tolerance, upper_limit, lower_limit, classification, inspection_method, operation, aql, sample_size, supplier_inspection_rate, internal_inspection_rate, reference, comments FROM inspection_characteristics WHERE file_id = ?')->execute([$revisionId, $fileId]);
            } else {
                $db->prepare('UPDATE files SET canonical_path = ?, file_name = ?, part_number = ?, description = ?, revision_label = ?, custom_properties = ? WHERE id = ?')->execute([$persistedCanonicalPath, $persistedFileName, $partNumberPresent ? $partNumber : $file['part_number'], $description, $revisionLabel, $propertiesJson, $fileId]);
            }

            $storedPartNumber = $partNumberPresent ? $partNumber : $file['part_number'];
            $response = [
                'fileId' => $fileId,
                'revision' => $nextRevision,
                'mode' => $mode,
                'contentChanged' => $mode === 'content',
                'metadataChanged' => $canonicalLocationChanged || $partNumberPresent || (is_array($pending) && $pending !== []) || $committedProperties !== null,
                'replayed' => false,
                'file' => [
                    'id' => $fileId,
                    'canonical_path' => $persistedCanonicalPath,
                    'file_name' => $persistedFileName,
                    'part_number' => $storedPartNumber,
                    'description' => $description,
                    'revision' => $revisionLabel,
                    'version' => $nextRevision,
                    'content_hash' => $mode === 'content' ? $hash : $file['content_hash'],
                    'storage_relative_path' => $mode === 'content' ? $relativePath : $file['storage_relative_path'],
                    'file_size' => $mode === 'content' ? $size : ($file['size_bytes'] === null ? null : (int)$file['size_bytes']),
                    'custom_properties' => $propertiesJson === null ? null : (object)$currentProperties,
                ],
            ];
            Runtime::emitEvent($db, $principal['organizationId'], $mode === 'content' ? 'file.checked_in' : 'file.metadata_checked_in', $fileId, ['revision' => $nextRevision, 'userId' => $principal['userId'], 'comment' => $comment, 'requestId' => $requestId]);
            $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]);
            if ($requestId !== null) $db->prepare('INSERT INTO file_checkin_requests (request_id, organization_id, file_id, user_id, request_fingerprint, response) VALUES (?, ?, ?, ?, ?, ?)')->execute([$requestId, $principal['organizationId'], $fileId, $principal['userId'], $fingerprint, json_encode($response, JSON_THROW_ON_ERROR)]);
            $db->commit(); return $response;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof FileCheckinFailure) throw $error;
            if ($error instanceof PDOException && $error->getCode() === '23000' && str_contains($error->getMessage(), 'uq_files_org_part_number')) throw new FileCheckinFailure(409, 'PART_NUMBER_EXISTS', 'This part number is already assigned to another file.');
            if ($error instanceof PDOException && $error->getCode() === '23000' && str_contains($error->getMessage(), 'uq_file_active_path')) throw new FileCheckinFailure(409, 'PATH_EXISTS', 'A file already exists at this vault path.');
            throw $error;
        }
    }

    private static function isUuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private static function isSafeCanonicalPath(string $value): bool
    {
        if ($value === '' || trim($value) !== $value || str_contains($value, '\\') || str_starts_with($value, '/') || preg_match('/[\x00-\x1f\x7f<>:"|?*]/', $value)) return false;
        foreach (explode('/', $value) as $segment) if ($segment === '' || $segment === '.' || $segment === '..' || rtrim($segment, ' .') !== $segment) return false;
        return true;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function fingerprint(array $body): string
    {
        unset($body['requestId']);
        $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
            if (!is_array($value)) return $value;
            if (array_is_list($value)) return array_map($canonicalize, $value);
            ksort($value, SORT_STRING); foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
            return $value;
        };
        return hash('sha256', json_encode($canonicalize($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
