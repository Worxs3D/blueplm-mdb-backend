<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

final class FileRevisionHistory
{
    /** @param array<string,mixed> $principal @return list<array<string,mixed>> */
    public static function list(PDO $db, array $principal, string $fileId): array
    {
        $file = $db->prepare('SELECT id, vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $file->execute([$fileId, $principal['organizationId']]);
        $row = $file->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']);
        Runtime::requireVault($db, $principal, $row['vault_id']);

        $query = $db->prepare(
            'SELECT r.id, r.revision_number AS revisionNumber, r.content_hash AS contentHash,
                    r.storage_relative_path AS storageRelativePath, r.size_bytes AS sizeBytes,
                    r.comment, r.part_number AS partNumber, r.description,
                    r.revision_label AS revision, r.custom_properties AS customProperties,
                    r.created_at AS createdAt, r.checked_in_by AS checkedInBy,
                    author.display_name AS checkedInByName
             FROM file_revisions r
             LEFT JOIN organization_memberships author_membership
               ON author_membership.user_id = r.checked_in_by
              AND author_membership.organization_id = ?
             LEFT JOIN users author ON author.id = author_membership.user_id
             WHERE r.file_id = ?
             ORDER BY r.revision_number DESC'
        );
        $query->execute([$principal['organizationId'], $fileId]);
        $revisions = $query->fetchAll();
        foreach ($revisions as &$revisionRow) {
            $revisionRow['customProperties'] = $revisionRow['customProperties'] === null
                ? null
                : json_decode($revisionRow['customProperties'], false, 512, JSON_THROW_ON_ERROR);
        }
        unset($revisionRow);
        return $revisions;
    }
}
