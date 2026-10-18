<?php
declare(strict_types=1);

namespace BluePlm;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Read-only contribution rows and exact counts for the standard profile history view. */
final class ProfileContributions
{
    private const FILTERS = ['all', 'checkins', 'checkouts', 'creates', 'reviews', 'approvals'];
    private const ROW_LIMIT = 100;
    private const RECENT_LIMIT = 20;

    /** @param array<string, mixed> $principal @param array<string, mixed> $query */
    public static function respond(PDO $db, array $principal, string $targetId, array $query): never
    {
        if (array_diff(array_keys($query), ['filter', 'day', 'limit', 'cursor']) !== []) self::invalidRequest();
        $filter = self::queryString($query, 'filter') ?? 'all';
        if (!in_array($filter, self::FILTERS, true)) self::invalidRequest();
        $day = self::queryString($query, 'day');
        if ($day !== null && !self::validDay($day)) self::invalidRequest();
        if ($day === null && array_key_exists('limit', $query)) self::invalidRequest();
        $limit = self::queryLimit($query['limit'] ?? null);
        $cursor = self::decodeCursor(self::queryString($query, 'cursor'), $principal, $targetId, $filter, $day);

        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        try {
            $targetId = self::authorizeTarget($db, $principal, $targetId);
            $windowQuery = $db->query(
                "SELECT DATE_FORMAT(NOW(3), '%Y-%m-%d %H:%i:%s.%f') AS untilDatabaseTime,
                        DATE_FORMAT(DATE_SUB(NOW(3), INTERVAL 1 YEAR), '%Y-%m-%d %H:%i:%s.%f') AS fromDatabaseTime"
            );
            $window = $windowQuery->fetch();
            if (!is_array($window) || !is_string($window['fromDatabaseTime'] ?? null) || !is_string($window['untilDatabaseTime'] ?? null)) {
                throw new RuntimeException('Could not capture profile contribution time window.');
            }
            ProfileSummary::databaseDateTime($window['fromDatabaseTime']);
            ProfileSummary::databaseDateTime($window['untilDatabaseTime']);

            [$rowsSql, $rowsParameters] = self::rowsQuery($principal, $targetId, $window);
            $counts = self::counts($db, $rowsSql, $rowsParameters);
            $dailyCounts = self::dailyCounts($db, $rowsSql, $rowsParameters, $filter);
            $recent = self::fetchRows($db, $rowsSql, $rowsParameters, $filter, null, self::RECENT_LIMIT, null);
            if (count($recent) > self::RECENT_LIMIT) array_pop($recent);
            $selectedDay = $day === null
                ? null
                : self::selectedDay($db, $rowsSql, $rowsParameters, $filter, $day, $limit, $cursor, $principal, $targetId);

            $result = [
                'timeBasis' => 'database-local-unverified',
                'window' => ['fromDatabaseTime' => $window['fromDatabaseTime'], 'untilDatabaseTime' => $window['untilDatabaseTime']],
                'filter' => $filter,
                'counts' => $counts,
                'dailyCounts' => $dailyCounts,
                'recent' => $recent,
                'selectedDay' => $selectedDay,
            ];
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        Runtime::respond(200, $result);
    }

    /** @param array<string, mixed> $principal */
    private static function authorizeTarget(PDO $db, array $principal, string $targetId): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $targetId)) {
            Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        }
        $targetId = strtolower($targetId);
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        }
        $target = $db->prepare(
            'SELECT u.id FROM users u
             JOIN organization_memberships m ON m.user_id = u.id
             WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL'
        );
        $target->execute([$targetId, $principal['organizationId']]);
        if (!$target->fetchColumn()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        return $targetId;
    }

    /** @param array<string, mixed> $query */
    private static function queryString(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) return null;
        if (!is_string($query[$key])) self::invalidRequest();
        return $query[$key];
    }

    private static function queryLimit(mixed $value): int
    {
        if ($value === null) return 20;
        if (!is_string($value) || !preg_match('/^(?:[1-9][0-9]?|100)$/', $value)) self::invalidRequest();
        $limit = (int)$value;
        if ($limit > self::ROW_LIMIT) self::invalidRequest();
        return $limit;
    }

    private static function validDay(string $day): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $matches)) return false;
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
    }

    private static function invalidRequest(): never
    {
        Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'The contribution view query is invalid.']);
    }

    /** @param array<string, mixed> $principal @return array<string, mixed>|null */
    private static function decodeCursor(?string $encoded, array $principal, string $targetId, string $filter, ?string $day): ?array
    {
        if ($encoded === null) return null;
        if ($day === null || strlen($encoded) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) self::invalidRequest();
        $base64 = strtr($encoded, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        if (!is_string($json) || rtrim(strtr(rtrim($base64, '='), '+/', '-_'), '=') !== $encoded) self::invalidRequest();
        try { $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (\JsonException) { self::invalidRequest(); }
        if (!is_array($value) || array_keys($value) !== ['v', 'organizationId', 'targetId', 'filter', 'day', 'createdAt', 'sourceKind', 'rowId']
            || ($value['v'] ?? null) !== 1
            || ($value['organizationId'] ?? null) !== $principal['organizationId']
            || ($value['targetId'] ?? null) !== $targetId
            || ($value['filter'] ?? null) !== $filter
            || ($value['day'] ?? null) !== $day
            || !is_string($value['createdAt'] ?? null)
            || !is_string($value['sourceKind'] ?? null)
            || !is_string($value['rowId'] ?? null)) self::invalidRequest();
        if (!self::validCursorDateTime($value['createdAt'])) self::invalidRequest();
        if ($value['sourceKind'] === 'event') {
            if (!preg_match('/^(0|[1-9][0-9]*)$/', $value['rowId'])) self::invalidRequest();
        } elseif (in_array($value['sourceKind'], ['review-request', 'review-decision'], true)) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value['rowId'])) self::invalidRequest();
        } else {
            self::invalidRequest();
        }
        return $value;
    }

    private static function validCursorDateTime(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\.\d{6}$/', $value, $matches)) return false;
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
            && (int)$matches[4] <= 23
            && (int)$matches[5] <= 59
            && (int)$matches[6] <= 59;
    }

    /** @param array<string, mixed> $principal @param array<string, string> $window @return array{string, list<mixed>} */
    private static function rowsQuery(array $principal, string $targetId, array $window): array
    {
        [$actorPredicate, $actorParameters] = ProfileSummary::actorPredicate($targetId);
        $access = ProfileSummary::fileAccessPredicate($principal);
        $eventAccessParameters = ProfileSummary::fileAccessParameters($principal);
        $reviewAccessParameters = ProfileSummary::fileAccessParameters($principal);
        $eventFile = ProfileSummary::eventFileIdExpression();
        $sql = "event_rows AS (
                    SELECT CONVERT(CAST(e.sequence_id AS CHAR(20)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rowId, 'event' AS sourceKind,
                           DATE_FORMAT(e.created_at, '%Y-%m-%d %H:%i:%s.%f') AS createdAt,
                           CASE WHEN e.type IN ('file.checked_in', 'file.metadata_checked_in') THEN 'checkins'
                                WHEN e.type = 'file.checked_out' THEN 'checkouts'
                                WHEN e.type = 'file.imported' THEN 'creates'
                                ELSE 'other' END AS category,
                           0 AS isApproval,
                           CASE WHEN e.type IN ('file.checked_in', 'file.metadata_checked_in') THEN 'checkin'
                                WHEN e.type = 'file.checked_out' THEN 'checkout'
                                WHEN e.type = 'file.imported' THEN 'create'
                                ELSE 'activity' END AS action,
                           f.file_name AS fileName
                    FROM events e
                    JOIN files f ON f.id = {$eventFile} AND f.organization_id = e.organization_id
                    WHERE e.organization_id = ? AND e.created_at >= ? AND e.created_at <= ?
                      AND e.type LIKE 'file.%'
                      AND e.type <> 'file.workflow_review_requested'
                      AND ({$actorPredicate}) AND ({$access})
                ), request_ranked AS (
                    SELECT CONVERT(pr.id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rowId, pr.file_id AS fileId, pr.requested_at AS occurredAt,
                           f.file_name AS fileName,
                           ROW_NUMBER() OVER (
                               PARTITION BY pr.organization_id, pr.file_id, pr.transition_id, pr.review_round_id
                               ORDER BY pr.requested_at ASC, pr.id ASC
                           ) AS roundRequestRank
                    FROM pending_reviews pr
                    JOIN files f ON f.id = pr.file_id AND f.organization_id = pr.organization_id
                    WHERE pr.organization_id = ? AND pr.requested_by = ?
                      AND pr.requested_at >= ? AND pr.requested_at <= ?
                      AND pr.review_round_id IS NOT NULL AND ({$access})
                ), request_rows AS (
                    SELECT rowId, 'review-request' AS sourceKind,
                           DATE_FORMAT(occurredAt, '%Y-%m-%d %H:%i:%s.%f') AS createdAt,
                           'reviews' AS category, 0 AS isApproval, 'review_requested' AS action, fileName
                    FROM request_ranked WHERE roundRequestRank = 1
                ), decision_ranked AS (
                    SELECT CONVERT(pr.id USING utf8mb4) COLLATE utf8mb4_unicode_ci AS rowId, pr.file_id AS fileId, pr.reviewed_at AS occurredAt,
                           pr.status, f.file_name AS fileName,
                           ROW_NUMBER() OVER (
                               PARTITION BY pr.organization_id, pr.file_id, pr.transition_id, pr.review_round_id, pr.gate_id, pr.reviewed_by
                               ORDER BY pr.reviewed_at DESC, pr.id DESC
                           ) AS reviewerDecisionRank
                    FROM pending_reviews pr
                    JOIN files f ON f.id = pr.file_id AND f.organization_id = pr.organization_id
                    WHERE pr.organization_id = ? AND pr.reviewed_by = ?
                      AND pr.status IN ('approved', 'rejected', 'kicked_back')
                      AND pr.reviewed_at >= ? AND pr.reviewed_at <= ?
                      AND pr.review_round_id IS NOT NULL AND ({$access})
                ), decision_rows AS (
                    SELECT rowId, 'review-decision' AS sourceKind,
                           DATE_FORMAT(occurredAt, '%Y-%m-%d %H:%i:%s.%f') AS createdAt,
                           'reviews' AS category, (status = 'approved') AS isApproval,
                           CASE status WHEN 'approved' THEN 'review_approved'
                                       WHEN 'rejected' THEN 'review_rejected'
                                       ELSE 'review_kicked_back' END AS action,
                           fileName
                    FROM decision_ranked WHERE reviewerDecisionRank = 1
                ), contribution_rows AS (
                    SELECT rowId, sourceKind, createdAt, category, isApproval, action, fileName FROM event_rows
                    UNION ALL
                    SELECT rowId, sourceKind, createdAt, category, isApproval, action, fileName FROM request_rows
                    UNION ALL
                    SELECT rowId, sourceKind, createdAt, category, isApproval, action, fileName FROM decision_rows
                )";
        $parameters = [
            $principal['organizationId'], $window['fromDatabaseTime'], $window['untilDatabaseTime'],
            ...$actorParameters, ...$eventAccessParameters,
            $principal['organizationId'], $targetId, $window['fromDatabaseTime'], $window['untilDatabaseTime'], ...$reviewAccessParameters,
            $principal['organizationId'], $targetId, $window['fromDatabaseTime'], $window['untilDatabaseTime'], ...ProfileSummary::fileAccessParameters($principal),
        ];
        return [$sql, $parameters];
    }

    /** @param list<mixed> $parameters @return array{all:string,checkins:string,checkouts:string,creates:string,reviews:string,approvals:string} */
    private static function counts(PDO $db, string $rowsSql, array $parameters): array
    {
        $query = $db->prepare("WITH {$rowsSql}
            SELECT CAST(COUNT(*) AS CHAR) AS allCount,
                   CAST(COALESCE(SUM(category = 'checkins'), 0) AS CHAR) AS checkins,
                   CAST(COALESCE(SUM(category = 'checkouts'), 0) AS CHAR) AS checkouts,
                   CAST(COALESCE(SUM(category = 'creates'), 0) AS CHAR) AS creates,
                   CAST(COALESCE(SUM(category = 'reviews'), 0) AS CHAR) AS reviews,
                   CAST(COALESCE(SUM(isApproval = 1), 0) AS CHAR) AS approvals
            FROM contribution_rows");
        $query->execute($parameters);
        $row = $query->fetch();
        if (!is_array($row)) throw new RuntimeException('Profile contribution counts were unavailable.');
        return [
            'all' => ProfileSummary::decimal($row['allCount'] ?? null),
            'checkins' => ProfileSummary::decimal($row['checkins'] ?? null),
            'checkouts' => ProfileSummary::decimal($row['checkouts'] ?? null),
            'creates' => ProfileSummary::decimal($row['creates'] ?? null),
            'reviews' => ProfileSummary::decimal($row['reviews'] ?? null),
            'approvals' => ProfileSummary::decimal($row['approvals'] ?? null),
        ];
    }

    /** @param list<mixed> $parameters @return list<array{day:string,count:string}> */
    private static function dailyCounts(PDO $db, string $rowsSql, array $parameters, string $filter): array
    {
        $filterSql = self::filterSql($filter);
        $query = $db->prepare("WITH {$rowsSql}
            SELECT DATE_FORMAT(createdAt, '%Y-%m-%d') AS day, CAST(COUNT(*) AS CHAR) AS rowCount
            FROM contribution_rows WHERE {$filterSql}
            GROUP BY DATE_FORMAT(createdAt, '%Y-%m-%d') ORDER BY day");
        $query->execute($parameters);
        $result = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_string($row['day'] ?? null) || !self::validDay($row['day'])) throw new RuntimeException('Profile contributions returned an invalid database-local day.');
            $result[] = ['day' => $row['day'], 'count' => ProfileSummary::decimal($row['rowCount'] ?? null)];
        }
        return $result;
    }

    /** @param list<mixed> $parameters @param array<string,mixed>|null $cursor @return list<array{id:string,action:string,createdAt:string,fileName:string}> */
    private static function fetchRows(PDO $db, string $rowsSql, array $parameters, string $filter, ?string $day, int $limit, ?array $cursor): array
    {
        $where = [self::filterSql($filter)];
        $extra = [];
        if ($day !== null) {
            [$dayStart, $dayEnd] = self::dayBounds($day);
            $where[] = 'createdAt >= ? AND createdAt < ?';
            $extra[] = $dayStart;
            $extra[] = $dayEnd;
        }
        if ($cursor !== null) {
            $where[] = '(createdAt < ? OR (createdAt = ? AND (sourceKind < ? OR (sourceKind = ? AND CAST(rowId AS BINARY) < CAST(? AS BINARY)))))';
            array_push($extra, $cursor['createdAt'], $cursor['createdAt'], $cursor['sourceKind'], $cursor['sourceKind'], $cursor['rowId']);
        }
        $query = $db->prepare("WITH {$rowsSql}
            SELECT rowId, sourceKind, createdAt, action, fileName
            FROM contribution_rows WHERE " . implode(' AND ', $where) . "
            ORDER BY createdAt DESC, sourceKind DESC, CAST(rowId AS BINARY) DESC LIMIT " . ($limit + 1));
        $query->execute([...$parameters, ...$extra]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            if (!is_string($row['rowId'] ?? null) || !is_string($row['sourceKind'] ?? null)
                || !is_string($row['createdAt'] ?? null) || !is_string($row['action'] ?? null)
                || !is_string($row['fileName'] ?? null)) throw new RuntimeException('Profile contribution returned an invalid activity row.');
            ProfileSummary::databaseDateTime($row['createdAt']);
            $rows[] = [
                'id' => $row['sourceKind'] . ':' . $row['rowId'],
                'action' => $row['action'],
                'createdAt' => $row['createdAt'],
                'fileName' => $row['fileName'],
            ];
        }
        return $rows;
    }

    /** @param list<mixed> $parameters @param array<string,mixed>|null $cursor @param array<string,mixed> $principal */
    private static function selectedDay(PDO $db, string $rowsSql, array $parameters, string $filter, string $day, int $limit, ?array $cursor, array $principal, string $targetId): array
    {
        [$dayStart, $dayEnd] = self::dayBounds($day);
        $where = self::filterSql($filter) . ' AND createdAt >= ? AND createdAt < ?';
        $countQuery = $db->prepare("WITH {$rowsSql} SELECT CAST(COUNT(*) AS CHAR) FROM contribution_rows WHERE {$where}");
        $countQuery->execute([...$parameters, $dayStart, $dayEnd]);
        $count = ProfileSummary::decimal($countQuery->fetchColumn());
        $rows = self::fetchRows($db, $rowsSql, $parameters, $filter, $day, $limit, $cursor);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            [$sourceKind, $rowId] = explode(':', $last['id'], 2);
            $nextCursor = self::encodeCursor([
                'v' => 1, 'organizationId' => $principal['organizationId'], 'targetId' => $targetId,
                'filter' => $filter, 'day' => $day, 'createdAt' => $last['createdAt'],
                'sourceKind' => $sourceKind, 'rowId' => $rowId,
            ]);
        }
        return ['day' => $day, 'count' => $count, 'rows' => $rows, 'hasMore' => $hasMore, 'nextCursor' => $nextCursor];
    }

    private static function filterSql(string $filter): string
    {
        return match ($filter) {
            'all' => '1 = 1',
            'checkins' => "category = 'checkins'",
            'checkouts' => "category = 'checkouts'",
            'creates' => "category = 'creates'",
            'reviews' => "category = 'reviews'",
            'approvals' => 'isApproval = 1',
            default => throw new RuntimeException('Unsupported profile contribution filter.'),
        };
    }

    /** @return array{string,string} */
    private static function dayBounds(string $day): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false) self::invalidRequest();
        return [$day . ' 00:00:00.000000', $date->modify('+1 day')->format('Y-m-d') . ' 00:00:00.000000'];
    }

    /** @param array<string, mixed> $payload */
    private static function encodeCursor(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
