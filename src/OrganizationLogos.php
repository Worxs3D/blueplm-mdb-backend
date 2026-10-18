<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use RuntimeException;

final class OrganizationLogoFailure extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class OrganizationLogos
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Routes are scoped exclusively by the organization on the trusted principal. */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($path !== '/organizations/current/logo') return;

        if ($method === 'GET') self::read($db, $principal);
        if ($method === 'PUT') self::write($db, $principal);
        if ($method === 'DELETE') self::delete($db, $principal);
    }

    /** @return array{bytes:string,mime:string} */
    private static function readRequestImage(): array
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null) {
            if (!is_string($contentLength) || preg_match('/^[0-9]+$/D', $contentLength) !== 1) {
                throw new OrganizationLogoFailure(400, 'INVALID_LOGO', 'The organization logo request length is invalid.');
            }
            $normalizedLength = ltrim($contentLength, '0');
            $normalizedLength = $normalizedLength === '' ? '0' : $normalizedLength;
            if (strlen($normalizedLength) > 7 || (int)$normalizedLength > self::MAX_BYTES) {
                throw new OrganizationLogoFailure(413, 'LOGO_TOO_LARGE', 'The organization logo exceeds the 2 MiB limit.');
            }
        }

        $stream = @fopen('php://input', 'rb');
        if ($stream === false) throw new OrganizationLogoFailure(400, 'INVALID_LOGO', 'The organization logo request body is unavailable.');
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false) throw new OrganizationLogoFailure(400, 'INVALID_LOGO', 'The organization logo request body could not be read.');
        if (strlen($bytes) > self::MAX_BYTES) throw new OrganizationLogoFailure(413, 'LOGO_TOO_LARGE', 'The organization logo exceeds the 2 MiB limit.');
        if ($contentLength !== null && strlen($bytes) !== (int)$contentLength) {
            throw new OrganizationLogoFailure(400, 'INVALID_LOGO', 'The organization logo request body is truncated.');
        }

        $declaredMime = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        try {
            $mime = UserAvatars::validateImage($bytes, $declaredMime);
        } catch (UserAvatarFailure $error) {
            $status = $error->httpStatus;
            throw new OrganizationLogoFailure(
                $status,
                $status === 413 ? 'LOGO_TOO_LARGE' : 'INVALID_LOGO',
                $status === 413 ? 'The organization logo exceeds the 2 MiB limit.' : 'The organization logo must be a complete supported raster image.',
                previous: $error,
            );
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** @param array<string, mixed> $principal */
    private static function requireManageRole(array $principal): void
    {
        if (!in_array($principal['role'] ?? null, ['owner', 'admin'], true)) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        }
    }

    /** @param array<string, mixed> $principal */
    private static function read(PDO $db, array $principal): never
    {
        $query = $db->prepare(
            'SELECT asset_ref, content_type, image_bytes
             FROM organization_logos WHERE organization_id = ?'
        );
        $query->execute([(string)$principal['organizationId']]);
        $logo = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($logo)) {
            $legacy = $db->prepare('SELECT logo_storage_path FROM organizations WHERE id = ?');
            $legacy->execute([(string)$principal['organizationId']]);
            $legacyReference = $legacy->fetchColumn();
            if (is_string($legacyReference) && trim($legacyReference) !== '') {
                Runtime::respond(409, [
                    'error' => 'LEGACY_LOGO_UNAVAILABLE',
                    'message' => 'The organization has a legacy logo reference that is not available as a stored image.',
                ]);
            }
            Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization logo not found.']);
        }

        $mime = $logo['content_type'] ?? null;
        $assetRef = $logo['asset_ref'] ?? null;
        $bytes = $logo['image_bytes'] ?? null;
        $allowedMime = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
        if (!is_string($mime) || !in_array($mime, $allowedMime, true)
            || !is_string($assetRef) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $assetRef)
            || !is_string($bytes) || strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Stored organization logo metadata is invalid.');
        }

        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('ETag: "' . hash('sha256', $bytes) . '"');
        header('X-BluePLM-Asset-Ref: ' . $assetRef);
        echo $bytes;
        exit;
    }

    /** @param array<string, mixed> $principal */
    private static function write(PDO $db, array $principal): never
    {
        self::requireManageRole($principal);
        $image = self::readRequestImage();
        $organizationId = (string)$principal['organizationId'];
        $assetRef = '';
        $updatedAt = '';

        $db->beginTransaction();
        try {
            $organization = $db->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
            $organization->execute([$organizationId]);
            if (!$organization->fetchColumn()) {
                $db->rollBack();
                Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization not found.']);
            }

            $assetRef = Runtime::uuid();

            $upsert = $db->prepare(
                'INSERT INTO organization_logos (organization_id, asset_ref, content_type, image_bytes, updated_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE asset_ref = VALUES(asset_ref), content_type = VALUES(content_type), image_bytes = VALUES(image_bytes), updated_at = UTC_TIMESTAMP(3)'
            );
            $upsert->bindValue(1, $organizationId);
            $upsert->bindValue(2, $assetRef);
            $upsert->bindValue(3, $image['mime']);
            $upsert->bindValue(4, $image['bytes'], PDO::PARAM_LOB);
            $upsert->execute();

            $readback = $db->prepare(
                "SELECT asset_ref, content_type, DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%s.%fZ') AS updated_at
                 FROM organization_logos WHERE organization_id = ?"
            );
            $readback->execute([$organizationId]);
            $stored = $readback->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored) || !is_string($stored['asset_ref'] ?? null)
                || !is_string($stored['content_type'] ?? null) || !is_string($stored['updated_at'] ?? null)) {
                throw new RuntimeException('Stored organization logo metadata is invalid.');
            }
            $assetRef = $stored['asset_ref'];
            $image['mime'] = $stored['content_type'];
            $updatedAt = $stored['updated_at'];
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        Runtime::respond(200, ['logo' => [
            'assetRef' => $assetRef,
            'contentType' => $image['mime'],
            'updatedAt' => $updatedAt,
        ]]);
    }

    /** @param array<string, mixed> $principal */
    private static function delete(PDO $db, array $principal): never
    {
        self::requireManageRole($principal);
        $organizationId = (string)$principal['organizationId'];
        $db->beginTransaction();
        try {
            $organization = $db->prepare('SELECT id FROM organizations WHERE id = ? FOR UPDATE');
            $organization->execute([$organizationId]);
            if (!$organization->fetchColumn()) {
                $db->rollBack();
                Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization not found.']);
            }
            $db->prepare('DELETE FROM organization_logos WHERE organization_id = ?')->execute([$organizationId]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }

        Runtime::respond(204);
    }
}
