<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use PDOException;
use Throwable;

final class JobTitles
{
    /** Dispatch the organization job-title catalog and its one-title-per-user assignments. */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/job-titles') self::list($db, $principal);
        if ($method === 'POST' && $path === '/job-titles') self::create($db, $principal);
        if ($method === 'PATCH' && preg_match('#^/job-titles/([0-9a-f-]{36})$#i', $path, $matches)) {
            self::update($db, $principal, strtolower($matches[1]));
        }
        if ($method === 'DELETE' && preg_match('#^/job-titles/([0-9a-f-]{36})$#i', $path, $matches)) {
            self::delete($db, $principal, strtolower($matches[1]));
        }
        if ($method === 'PUT' && preg_match('#^/users/([0-9a-f-]{36})/job-title$#i', $path, $matches)) {
            self::assign($db, $principal, strtolower($matches[1]));
        }
    }

    /** @param array<string, mixed> $principal */
    private static function list(PDO $db, array $principal): never
    {
        $organizationId = (string)$principal['organizationId'];
        $query = $db->prepare('SELECT id, name, color, icon FROM job_titles WHERE organization_id = ? ORDER BY name, id');
        $query->execute([$organizationId]);
        $titles = array_map(self::titleShape(...), $query->fetchAll(PDO::FETCH_ASSOC));

        $query = $db->prepare(
            'SELECT a.user_id, t.id, t.name, t.color, t.icon
             FROM user_job_titles a
             JOIN job_titles t ON t.organization_id = a.organization_id AND t.id = a.title_id
             WHERE a.organization_id = ? ORDER BY a.user_id'
        );
        $query->execute([$organizationId]);
        $assignments = array_map(static fn(array $row): array => [
            'userId' => (string)$row['user_id'],
            'jobTitle' => self::titleShape($row),
        ], $query->fetchAll(PDO::FETCH_ASSOC));

        Runtime::respond(200, ['jobTitles' => $titles, 'assignments' => $assignments]);
    }

    /** @param array<string, mixed> $principal */
    private static function create(PDO $db, array $principal): never
    {
        self::requireAdministrator($principal);
        $body = Runtime::jsonBody();
        [$name, $color, $icon] = self::titleInput($body);
        $assignTo = null;
        if (array_key_exists('assignToUserId', $body) && $body['assignToUserId'] !== null) {
            $assignTo = self::uuid($body['assignToUserId'], 'assignToUserId');
        }

        $id = Runtime::uuid();
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO job_titles (id, organization_id, name, color, icon, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $principal['organizationId'], $name, $color, $icon, $principal['userId']]);
            if ($assignTo !== null) {
                if (!self::isMember($db, (string)$principal['organizationId'], $assignTo)) {
                    $db->rollBack();
                    Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization member not found.']);
                }
                self::upsertAssignment($db, $principal, $assignTo, $id);
            }
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if (self::isDuplicate($error)) Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A job title with this name already exists.']);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        Runtime::respond(201, ['jobTitle' => self::findTitle($db, (string)$principal['organizationId'], $id)]);
    }

    /** @param array<string, mixed> $principal */
    private static function update(PDO $db, array $principal, string $id): never
    {
        self::requireAdministrator($principal);
        $body = Runtime::jsonBody();
        [$name, $color, $icon] = self::titleInput($body);
        if (!self::findTitle($db, (string)$principal['organizationId'], $id)) {
            Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Job title not found.']);
        }

        try {
            $db->prepare('UPDATE job_titles SET name = ?, color = ?, icon = ? WHERE id = ? AND organization_id = ?')
                ->execute([$name, $color, $icon, $id, $principal['organizationId']]);
        } catch (PDOException $error) {
            if (self::isDuplicate($error)) Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A job title with this name already exists.']);
            throw $error;
        }

        Runtime::respond(200, ['jobTitle' => self::findTitle($db, (string)$principal['organizationId'], $id)]);
    }

    /** @param array<string, mixed> $principal */
    private static function delete(PDO $db, array $principal, string $id): never
    {
        self::requireAdministrator($principal);
        $delete = $db->prepare('DELETE FROM job_titles WHERE id = ? AND organization_id = ?');
        $delete->execute([$id, $principal['organizationId']]);
        if ($delete->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Job title not found.']);
        Runtime::respond(204);
    }

    /** @param array<string, mixed> $principal */
    private static function assign(PDO $db, array $principal, string $userId): never
    {
        self::requireAdministrator($principal);
        $body = Runtime::jsonBody();
        if (!array_key_exists('jobTitleId', $body)) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'jobTitleId is required and may be null.']);
        }
        $titleId = $body['jobTitleId'] === null ? null : self::uuid($body['jobTitleId'], 'jobTitleId');
        $organizationId = (string)$principal['organizationId'];
        if (!self::isMember($db, $organizationId, $userId)) {
            Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization member not found.']);
        }

        if ($titleId === null) {
            $db->prepare('DELETE FROM user_job_titles WHERE organization_id = ? AND user_id = ?')
                ->execute([$organizationId, $userId]);
            Runtime::respond(200, ['userId' => $userId, 'jobTitle' => null]);
        }

        $title = self::findTitle($db, $organizationId, $titleId);
        if (!$title) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Job title not found.']);
        self::upsertAssignment($db, $principal, $userId, $titleId);
        Runtime::respond(200, ['userId' => $userId, 'jobTitle' => $title]);
    }

    /** @param array<string, mixed> $principal */
    private static function upsertAssignment(PDO $db, array $principal, string $userId, string $titleId): void
    {
        $db->prepare(
            'INSERT INTO user_job_titles (id, organization_id, user_id, title_id, assigned_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title_id = VALUES(title_id), assigned_by = VALUES(assigned_by), assigned_at = UTC_TIMESTAMP(3)'
        )->execute([Runtime::uuid(), $principal['organizationId'], $userId, $titleId, $principal['userId']]);
    }

    /** @param array<string, mixed> $principal */
    private static function requireAdministrator(array $principal): void
    {
        if (!in_array($principal['role'] ?? null, ['owner', 'admin'], true)) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        }
    }

    private static function isMember(PDO $db, string $organizationId, string $userId): bool
    {
        $query = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $query->execute([$organizationId, $userId]);
        return (bool)$query->fetchColumn();
    }

    private static function findTitle(PDO $db, string $organizationId, string $id): ?array
    {
        $query = $db->prepare('SELECT id, name, color, icon FROM job_titles WHERE organization_id = ? AND id = ?');
        $query->execute([$organizationId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? self::titleShape($row) : null;
    }

    /** @param array<string, mixed> $row @return array{id:string,name:string,color:string,icon:string} */
    private static function titleShape(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'color' => (string)$row['color'],
            'icon' => (string)$row['icon'],
        ];
    }

    /** @param array<string, mixed> $body @return array{string,string,string} */
    private static function titleInput(array $body): array
    {
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $color = $body['color'] ?? null;
        $icon = is_string($body['icon'] ?? null) ? trim($body['icon']) : '';
        if ($name === '' || strlen($name) > 200 || !is_string($color)
            || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1 || $icon === '' || strlen($icon) > 64) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A name (up to 200 characters), six-digit hex color, and icon (up to 64 characters) are required.']);
        }
        return [$name, $color, $icon];
    }

    private static function uuid(mixed $value, string $key): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) !== 1) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "$key must be a UUID."]);
        }
        return strtolower($value);
    }

    private static function isDuplicate(PDOException $error): bool
    {
        return (int)($error->errorInfo[1] ?? 0) === 1062;
    }
}
