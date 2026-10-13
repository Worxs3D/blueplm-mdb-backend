<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

final class ColorSwatches
{
    /** Dispatch the small color-swatch API surface after principal resolution. */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/color-swatches') {
            self::list($db, $principal);
        }

        if ($method === 'POST' && $path === '/color-swatches') {
            self::create($db, $principal);
        }

        if ($method === 'DELETE' && preg_match('#^/color-swatches/([0-9a-f-]{36})$#i', $path, $matches)) {
            self::delete($db, $principal, strtolower($matches[1]));
        }
    }

    /** @param array<string, mixed> $principal */
    private static function list(PDO $db, array $principal): never
    {
        $query = $db->prepare(
            "SELECT id, color, user_id IS NULL AS isOrg,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt
             FROM color_swatches
             WHERE organization_id = ? AND (user_id IS NULL OR user_id = ?)
             ORDER BY created_at ASC, id ASC"
        );
        $query->execute([$principal['organizationId'], $principal['userId']]);
        $swatches = array_map(static function (array $row): array {
            return [
                'id' => (string)$row['id'],
                'color' => (string)$row['color'],
                'isOrg' => (bool)$row['isOrg'],
                'createdAt' => (string)$row['createdAt'],
            ];
        }, $query->fetchAll(PDO::FETCH_ASSOC));

        Runtime::respond(200, ['swatches' => $swatches]);
    }

    /** @param array<string, mixed> $principal */
    private static function create(PDO $db, array $principal): never
    {
        $body = Runtime::jsonBody();
        $color = $body['color'] ?? null;
        $isOrg = $body['isOrg'] ?? null;
        if (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1 || !is_bool($isOrg)) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A six-digit hex color and boolean isOrg value are required.']);
        }

        if ($isOrg && !in_array($principal['role'], ['owner', 'admin'], true)) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        }

        $id = Runtime::uuid();
        $userId = $isOrg ? null : $principal['userId'];
        $db->prepare(
            'INSERT INTO color_swatches (id, organization_id, user_id, color, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))'
        )->execute([$id, $principal['organizationId'], $userId, $color]);

        $query = $db->prepare(
            "SELECT id, color, user_id IS NULL AS isOrg,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS createdAt
             FROM color_swatches WHERE id = ? AND organization_id = ?"
        );
        $query->execute([$id, $principal['organizationId']]);
        $swatch = $query->fetch(PDO::FETCH_ASSOC);
        if (!$swatch) throw new \RuntimeException('The inserted color swatch could not be read back.');

        Runtime::respond(201, [
            'id' => (string)$swatch['id'],
            'color' => (string)$swatch['color'],
            'isOrg' => (bool)$swatch['isOrg'],
            'createdAt' => (string)$swatch['createdAt'],
        ]);
    }

    /** @param array<string, mixed> $principal */
    private static function delete(PDO $db, array $principal, string $id): never
    {
        $lookup = $db->prepare('SELECT user_id FROM color_swatches WHERE id = ? AND organization_id = ?');
        $lookup->execute([$id, $principal['organizationId']]);
        $swatch = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$swatch) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Color swatch not found.']);

        if ($swatch['user_id'] === null) {
            if (!in_array($principal['role'], ['owner', 'admin'], true)) {
                Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
            }
            $delete = $db->prepare('DELETE FROM color_swatches WHERE id = ? AND organization_id = ? AND user_id IS NULL');
            $delete->execute([$id, $principal['organizationId']]);
        } else {
            if ($swatch['user_id'] !== $principal['userId']) {
                Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Color swatch not found.']);
            }
            $delete = $db->prepare('DELETE FROM color_swatches WHERE id = ? AND organization_id = ? AND user_id = ?');
            $delete->execute([$id, $principal['organizationId'], $principal['userId']]);
        }

        if ($delete->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Color swatch not found.']);
        Runtime::respond(204);
    }
}
