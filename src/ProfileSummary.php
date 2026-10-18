<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use RuntimeException;

/** Read-only, organization-scoped data for the standard user profile summary. */
final class ProfileSummary
{
    /** @var array<string, list<string>> */
    private const ACTOR_EVENT_TYPES = [
        'userId' => [
            'file.imported', 'file.revision_switch_requested', 'file.inspection_updated',
            'file.checked_out', 'file.checked_in', 'file.metadata_checked_in',
            'file.checkout_cancelled', 'file.moved', 'file.state_changed',
            'file.trashed', 'file.restored', 'file.references_synced',
            'file.workflow_review_requested', 'file.workflow_transition_executed',
            'review.rejected', 'account.totp_enabled', 'account.totp_disabled',
            'account.deleted', 'installation.completed',
        ],
        'createdBy' => ['user.created', 'workflow_role.created', 'team.created'],
        'changedBy' => ['user.credentials_updated'],
        'removedBy' => ['user.removed', 'team.member_removed'],
        'updatedBy' => [
            'workflow_role.updated', 'workflow_role.assignments_updated',
            'user.vault_access_updated', 'user.permissions_updated',
            'team.permissions_updated', 'team.module_defaults_updated',
            'team.updated', 'team.vault_access_updated', 'backup.config_updated',
            'vault.audit_repaired', 'organization.document_manager_license_updated',
            'organization.default_team_updated', 'organization.settings_updated',
            'organization.module_access_updated',
        ],
        'deletedBy' => ['workflow_role.deleted', 'team.deleted'],
        'addedBy' => ['team.reviewer_added', 'team.member_added'],
        'clearedBy' => ['team.module_defaults_cleared'],
        'approvedBy' => ['registration.approved'],
        'rejectedBy' => ['registration.rejected'],
        'allocatedBy' => ['organization.serial_number_allocated'],
    ];

    /** @param array<string, mixed> $principal */
    public static function respond(PDO $db, array $principal, string $targetId): never
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $targetId)) {
            Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        }
        $targetId = strtolower($targetId);
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        }

        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        try {
            $target = $db->prepare(
                'SELECT u.id FROM users u
                 JOIN organization_memberships m ON m.user_id = u.id
                 WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL'
            );
            $target->execute([$targetId, $principal['organizationId']]);
            if (!$target->fetchColumn()) {
                $db->rollBack();
                Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
            }

            $windowQuery = $db->query(
                "SELECT DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f') AS untilDatabaseTime,
                        DATE_FORMAT(DATE_SUB(NOW(3), INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s.%f') AS fromDatabaseTime"
            );
            $window = $windowQuery->fetch();
            if (!is_array($window) || !is_string($window['fromDatabaseTime'] ?? null) || !is_string($window['untilDatabaseTime'] ?? null)) {
                throw new RuntimeException('Could not capture profile summary time window.');
            }
            self::databaseDateTime($window['fromDatabaseTime']);
            self::databaseDateTime($window['untilDatabaseTime']);

            [$actorPredicate, $actorParameters] = self::actorPredicate($targetId);
            $visibilityPredicate = self::fileVisibilityPredicate($principal);
            $where = 'e.organization_id = ? AND e.created_at >= ? AND e.created_at <= ? AND ('
                . $actorPredicate . ') AND (' . $visibilityPredicate . ')';
            $parameters = [$principal['organizationId'], $window['fromDatabaseTime'], $window['untilDatabaseTime'], ...$actorParameters];
            if (!in_array($principal['role'], ['owner', 'admin'], true)) {
                array_push($parameters, $principal['userId'], $principal['userId']);
            }

            $countQuery = $db->prepare("SELECT CAST(COUNT(*) AS CHAR) FROM events e WHERE {$where}");
            $countQuery->execute($parameters);
            $totalCount = self::decimal($countQuery->fetchColumn());

            $dailyQuery = $db->prepare(
                "SELECT DATE_FORMAT(e.created_at, '%Y-%m-%d') AS day, CAST(COUNT(*) AS CHAR) AS eventCount
                 FROM events e WHERE {$where} GROUP BY DATE_FORMAT(e.created_at, '%Y-%m-%d') ORDER BY day"
            );
            $dailyQuery->execute($parameters);
            $dailyCounts = [];
            foreach ($dailyQuery->fetchAll() as $row) {
                if (!is_string($row['day'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['day'])) {
                    throw new RuntimeException('Profile summary returned an invalid database-local day.');
                }
                $dailyCounts[] = ['day' => $row['day'], 'count' => self::decimal($row['eventCount'] ?? null)];
            }

            $recentQuery = $db->prepare(
                "SELECT CAST(e.sequence_id AS CHAR) AS sequenceId, e.type,
                        DATE_FORMAT(e.created_at, '%Y-%m-%d %H:%i:%s.%f') AS createdAt,
                        CASE WHEN e.type LIKE 'file.%' OR e.type = 'review.rejected' THEN 'file'
                             WHEN e.type LIKE 'team.%' THEN 'team'
                             WHEN e.type LIKE 'user.%' OR e.type LIKE 'account.%' THEN 'user'
                             ELSE 'organization' END AS targetKind,
                        f.file_name AS targetLabel
                 FROM events e
                 LEFT JOIN files f ON f.id = " . self::eventFileIdExpression() . " AND f.organization_id = e.organization_id
                 WHERE {$where}
                 ORDER BY e.created_at DESC, e.sequence_id DESC LIMIT 15"
            );
            $recentQuery->execute($parameters);
            $recent = [];
            foreach ($recentQuery->fetchAll() as $row) {
                $sequenceId = self::decimal($row['sequenceId'] ?? null);
                if (!is_string($row['type'] ?? null) || !self::isAllowlistedType($row['type'])
                    || !is_string($row['createdAt'] ?? null)
                    || !in_array($row['targetKind'] ?? null, ['file', 'team', 'user', 'organization'], true)
                    || ($row['targetLabel'] !== null && !is_string($row['targetLabel']))) {
                    throw new RuntimeException('Profile summary returned an invalid recent activity item.');
                }
                self::databaseDateTime($row['createdAt']);
                $recent[] = [
                    'sequenceId' => $sequenceId,
                    'type' => $row['type'],
                    'createdAt' => $row['createdAt'],
                    'targetKind' => $row['targetKind'],
                    'targetLabel' => $row['targetLabel'],
                ];
            }

            $ecoQuery = $db->prepare(
                'SELECT id, eco_number AS ecoNumber, title, status,
                        DATE_FORMAT(created_at, \'%Y-%m-%d %H:%i:%s.%f\') AS createdAt
                 FROM ecos WHERE organization_id = ? AND created_by = ?
                 ORDER BY created_at DESC, id DESC LIMIT 5'
            );
            $ecoQuery->execute([$principal['organizationId'], $targetId]);
            $ownEcos = [];
            foreach ($ecoQuery->fetchAll() as $row) {
                if (!is_string($row['id'] ?? null) || !is_string($row['ecoNumber'] ?? null)
                    || !is_string($row['title'] ?? null) || !is_string($row['status'] ?? null)
                    || !is_string($row['createdAt'] ?? null)) {
                    throw new RuntimeException('Profile summary returned an invalid ECO item.');
                }
                self::databaseDateTime($row['createdAt']);
                $ownEcos[] = [
                    'id' => $row['id'],
                    'ecoNumber' => $row['ecoNumber'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'createdAt' => $row['createdAt'],
                ];
            }

            $deviceQuery = $db->prepare(
                "SELECT DATE_FORMAT(MAX(last_seen), '%Y-%m-%d %H:%i:%s.%f')
                 FROM device_sessions WHERE user_id = ? AND organization_id = ?"
            );
            $deviceQuery->execute([$targetId, $principal['organizationId']]);
            $lastSeen = $deviceQuery->fetchColumn();

            $result = [
                'timeBasis' => 'database-local-unverified',
                'window' => ['fromDatabaseTime' => $window['fromDatabaseTime'], 'untilDatabaseTime' => $window['untilDatabaseTime']],
                'activity' => ['totalCount' => $totalCount, 'dailyCounts' => $dailyCounts, 'recent' => $recent],
                'ownEcos' => $ownEcos,
                'deviceLastSeen' => $lastSeen === null || $lastSeen === false
                    ? ['status' => 'not-recorded']
                    : ['status' => 'recorded', 'at' => self::databaseDateTime($lastSeen)],
            ];
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        Runtime::respond(200, $result);
    }

    /** @return array{string, list<string>} */
    private static function actorPredicate(string $targetId): array
    {
        $branches = [];
        $parameters = [];
        foreach (self::ACTOR_EVENT_TYPES as $field => $types) {
            $branches[] = '(e.type IN (' . implode(', ', array_fill(0, count($types), '?'))
                . ") AND JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.{$field}')) = ?)";
            array_push($parameters, ...$types);
            $parameters[] = $targetId;
        }
        return [implode(' OR ', $branches), $parameters];
    }

    /** @param array<string, mixed> $principal */
    private static function fileVisibilityPredicate(array $principal): string
    {
        $fileId = self::eventFileIdExpression();
        $access = in_array($principal['role'], ['owner', 'admin'], true)
            ? '1 = 1'
            : 'EXISTS (SELECT 1 FROM vault_access va WHERE va.vault_id = f.vault_id AND va.user_id = ?)'
                . ' OR EXISTS (SELECT 1 FROM team_vault_access tva JOIN team_members tm ON tm.team_id = tva.team_id'
                . ' WHERE tva.vault_id = f.vault_id AND tm.user_id = ?)';
        return "((e.type NOT LIKE 'file.%' AND e.type <> 'review.rejected') OR EXISTS (
            SELECT 1 FROM files f WHERE f.id = {$fileId} AND f.organization_id = e.organization_id AND ({$access})
        ))";
    }

    private static function eventFileIdExpression(): string
    {
        return "CASE WHEN e.type = 'review.rejected' THEN JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.fileId')) ELSE e.aggregate_id END";
    }

    private static function isAllowlistedType(string $eventType): bool
    {
        foreach (self::ACTOR_EVENT_TYPES as $types) {
            if (in_array($eventType, $types, true)) return true;
        }
        return false;
    }

    private static function decimal(mixed $value): string
    {
        $decimal = (string)$value;
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $decimal)) {
            throw new RuntimeException('Profile summary returned a non-decimal value.');
        }
        return $decimal;
    }

    private static function databaseDateTime(string $databaseDateTime): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $databaseDateTime)) {
            throw new RuntimeException('Profile summary returned an invalid database-local DATETIME.');
        }
        return $databaseDateTime;
    }
}
