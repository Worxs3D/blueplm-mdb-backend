<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

/**
 * The single seam for persisted CAD parent/child references.  The desktop
 * client only needs to sync an assembly and read either traversal direction;
 * path normalisation and access control stay on the server.
 */
final class FileReferences
{
    /** @param array<string,mixed> $principal @param array<string,mixed> $payload @return array<string,mixed> */
    public static function sync(PDO $db, array $principal, string $parentFileId, array $payload): array
    {
        $parent = self::file($db, $principal, $parentFileId);
        Runtime::requireVault($db, $principal, $parent['vault_id']);
        $references = $payload['references'] ?? null;
        if (!is_array($references) || count($references) > 2_000) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'references must be an array with at most 2000 entries.']);
        }

        $vaultRoot = is_string($payload['vaultRootPath'] ?? null) ? self::normalise($payload['vaultRootPath']) : '';
        $files = $db->prepare('SELECT id, canonical_path, file_name FROM files WHERE organization_id = ? AND vault_id = ? AND deleted_at IS NULL');
        $files->execute([$principal['organizationId'], $parent['vault_id']]);
        $byPath = [];
        $byName = [];
        foreach ($files->fetchAll() as $file) {
            $canonical = self::normalise($file['canonical_path']);
            $byPath[$canonical] = $file['id'];
            $name = strtolower($file['file_name']);
            $byName[$name] ??= [];
            $byName[$name][] = $file['id'];
        }

        $existing = $db->prepare('SELECT id, child_file_id, configuration FROM file_references WHERE parent_file_id = ? AND organization_id = ?');
        $existing->execute([$parentFileId, $principal['organizationId']]);
        $existingByKey = [];
        foreach ($existing->fetchAll() as $reference) {
            $existingByKey[$reference['child_file_id'] . '::' . $reference['configuration']] = $reference;
        }

        $inserted = 0; $updated = 0; $deleted = 0; $skipped = 0; $skippedReasons = []; $seen = [];
        $db->beginTransaction();
        try {
            foreach ($references as $reference) {
                if (!is_array($reference) || !is_string($reference['childFilePath'] ?? null) || trim($reference['childFilePath']) === '') {
                    $skipped++; $skippedReasons[] = ['swPath' => '', 'reason' => 'no_match', 'details' => 'Reference has no childFilePath.']; continue;
                }
                $sourcePath = $reference['childFilePath'];
                $normalised = self::normalise($sourcePath);
                $relative = $vaultRoot !== '' && str_starts_with($normalised, $vaultRoot . '/') ? substr($normalised, strlen($vaultRoot) + 1) : $normalised;
                $childFileId = $byPath[$relative] ?? self::suffixMatch($byPath, $normalised);
                if ($childFileId === null) {
                    $name = basename($normalised);
                    $matches = $byName[$name] ?? [];
                    $childFileId = count($matches) === 1 ? $matches[0] : null;
                }
                if ($childFileId === null) {
                    $skipped++; $skippedReasons[] = ['swPath' => $sourcePath, 'reason' => 'file_not_synced', 'details' => 'No unique active file in this vault matches the SolidWorks reference.']; continue;
                }
                if ($childFileId === $parentFileId) {
                    $skipped++; $skippedReasons[] = ['swPath' => $sourcePath, 'reason' => 'self_reference', 'details' => 'An assembly cannot reference itself.']; continue;
                }
                $configuration = is_string($reference['configuration'] ?? null) ? substr($reference['configuration'], 0, 255) : '';
                $quantity = is_numeric($reference['quantity'] ?? null) ? (float)$reference['quantity'] : 1.0;
                if ($quantity <= 0 || $quantity > 1_000_000) $quantity = 1.0;
                $type = is_string($reference['referenceType'] ?? null) && in_array($reference['referenceType'], ['component', 'derived', 'reference'], true) ? $reference['referenceType'] : 'component';
                $key = $childFileId . '::' . $configuration;
                $seen[$key] = true;
                if (isset($existingByKey[$key])) {
                    $update = $db->prepare('UPDATE file_references SET quantity = ?, reference_type = ? WHERE id = ?');
                    $update->execute([$quantity, $type, $existingByKey[$key]['id']]);
                    $updated++;
                } else {
                    $insert = $db->prepare('INSERT INTO file_references (id, organization_id, vault_id, parent_file_id, child_file_id, reference_type, quantity, configuration) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $insert->execute([Runtime::uuid(), $principal['organizationId'], $parent['vault_id'], $parentFileId, $childFileId, $type, $quantity, $configuration]);
                    $inserted++;
                }
            }
            foreach ($existingByKey as $key => $reference) {
                if (!isset($seen[$key])) {
                    $db->prepare('DELETE FROM file_references WHERE id = ?')->execute([$reference['id']]);
                    $deleted++;
                }
            }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'file.references_synced', $parentFileId, ['userId' => $principal['userId'], 'inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted]);
        return ['success' => true, 'inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted, 'skipped' => $skipped, 'skippedReasons' => $skippedReasons];
    }

    /** @param array<string,mixed> $principal @return array<int,array<string,mixed>> */
    public static function list(PDO $db, array $principal, string $fileId, string $direction): array
    {
        $file = self::file($db, $principal, $fileId);
        Runtime::requireVault($db, $principal, $file['vault_id']);
        $isWhereUsed = $direction === 'where-used';
        $joinColumn = $isWhereUsed ? 'parent_file_id' : 'child_file_id';
        $filterColumn = $isWhereUsed ? 'child_file_id' : 'parent_file_id';
        $query = $db->prepare("SELECT r.id, r.parent_file_id, r.child_file_id, r.quantity, r.configuration, r.reference_type, related.id AS related_id, related.file_name AS related_file_name, related.canonical_path AS related_file_path, related.current_revision AS related_revision, related.state AS related_state FROM file_references r JOIN files related ON related.id = r.$joinColumn WHERE r.organization_id = ? AND r.$filterColumn = ? AND related.deleted_at IS NULL ORDER BY related.file_name");
        $query->execute([$principal['organizationId'], $fileId]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            $related = ['id' => $row['related_id'], 'file_name' => $row['related_file_name'], 'file_path' => $row['related_file_path'], 'part_number' => null, 'revision' => (string)$row['related_revision'], 'state' => $row['related_state']];
            unset($row['related_id'], $row['related_file_name'], $row['related_file_path'], $row['related_revision'], $row['related_state']);
            $row[$isWhereUsed ? 'parent' : 'child'] = $related;
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed> $principal @return array<string,mixed> */
    private static function file(PDO $db, array $principal, string $fileId): array
    {
        $query = $db->prepare('SELECT id, vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $query->execute([$fileId, $principal['organizationId']]);
        $file = $query->fetch();
        if (!$file) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']);
        return $file;
    }

    /** @param array<string,string> $byPath */
    private static function suffixMatch(array $byPath, string $source): ?string
    {
        $matches = [];
        foreach ($byPath as $canonical => $fileId) if ($source === $canonical || str_ends_with($source, '/' . $canonical)) $matches[] = $fileId;
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function normalise(string $path): string
    {
        return trim(strtolower(str_replace('\\', '/', $path)), '/');
    }
}
