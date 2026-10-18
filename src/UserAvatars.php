<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use RuntimeException;

final class UserAvatarFailure extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class UserAvatars
{
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_DIMENSION = 4096;
    private const MAX_PIXELS = 16 * 1024 * 1024;

    /** @var array<int, string> */
    private const MIME_BY_IMAGE_TYPE = [
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /** Routes are dispatched only after Runtime has resolved the trusted principal. */
    public static function dispatch(PDO $db, array $principal, string $method, string $path): void
    {
        if ($method === 'PUT' && $path === '/users/current/avatar') {
            self::write($db, $principal);
        }
        if ($method === 'DELETE' && $path === '/users/current/avatar') {
            self::delete($db, $principal);
        }
        if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/avatar$#i', $path, $matches) === 1) {
            self::read($db, $principal, strtolower($matches[1]));
        }
    }

    /** @return array{bytes:string,mime:string} */
    private static function readRequestImage(): array
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($contentLength !== null) {
            if (!is_string($contentLength) || preg_match('/^[0-9]+$/D', $contentLength) !== 1) {
                throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar request length is invalid.');
            }
            $normalizedLength = ltrim($contentLength, '0');
            $normalizedLength = $normalizedLength === '' ? '0' : $normalizedLength;
            if (strlen($normalizedLength) > 7 || (int)$normalizedLength > self::MAX_BYTES) {
                throw new UserAvatarFailure(413, 'AVATAR_TOO_LARGE', 'The avatar exceeds the 2 MiB limit.');
            }
        }

        $stream = @fopen('php://input', 'rb');
        if ($stream === false) throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar request body is unavailable.');
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($bytes === false) throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar request body could not be read.');
        if (strlen($bytes) > self::MAX_BYTES) throw new UserAvatarFailure(413, 'AVATAR_TOO_LARGE', 'The avatar exceeds the 2 MiB limit.');
        if ($contentLength !== null && strlen($bytes) !== (int)$contentLength) {
            throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar request body is truncated.');
        }

        $declaredMime = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        return ['bytes' => $bytes, 'mime' => self::validateImage($bytes, $declaredMime)];
    }

    /** Validate signatures, image metadata and complete container boundaries; this does not fully decode pixels. */
    public static function validateImage(string $bytes, string $declaredMime): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new UserAvatarFailure(strlen($bytes) > self::MAX_BYTES ? 413 : 400, strlen($bytes) > self::MAX_BYTES ? 'AVATAR_TOO_LARGE' : 'INVALID_AVATAR', 'The avatar is empty or exceeds the 2 MiB limit.');
        }

        $size = @getimagesizefromstring($bytes);
        if (!is_array($size) || !is_int($size[0] ?? null) || !is_int($size[1] ?? null)
            || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION
            || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar is not a supported raster image.');
        }
        $imageType = $size[2] ?? null;
        $expectedMime = is_int($imageType) ? (self::MIME_BY_IMAGE_TYPE[$imageType] ?? null) : null;
        if ($expectedMime === null || ($size['mime'] ?? null) !== $expectedMime || $declaredMime !== $expectedMime) {
            throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar content type does not match a supported raster image.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        if ($finfo->buffer($bytes) !== $expectedMime || !self::hasCompleteContainerEnd($bytes, $expectedMime)) {
            throw new UserAvatarFailure(400, 'INVALID_AVATAR', 'The avatar image is invalid or truncated.');
        }
        return $expectedMime;
    }

    private static function hasCompleteContainerEnd(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/png' => str_ends_with($bytes, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82"),
            'image/jpeg' => str_ends_with($bytes, "\xFF\xD9"),
            'image/gif' => str_ends_with($bytes, ';'),
            'image/webp' => strlen($bytes) >= 12
                && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP'
                && unpack('Vsize', substr($bytes, 4, 4))['size'] + 8 === strlen($bytes),
            default => false,
        };
    }

    private static function requireActiveMembership(PDO $db, string $organizationId, string $userId, bool $lock): void
    {
        $query = $db->prepare(
            'SELECT 1 FROM organization_memberships m JOIN users u ON u.id = m.user_id
             WHERE m.organization_id = ? AND m.user_id = ? AND u.disabled_at IS NULL' . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$organizationId, $userId]);
        if (!$query->fetchColumn()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Avatar not found.']);
    }

    private static function write(PDO $db, array $principal): never
    {
        $image = self::readRequestImage();
        $db->beginTransaction();
        try {
            self::requireActiveMembership($db, (string)$principal['organizationId'], (string)$principal['userId'], true);
            $statement = $db->prepare(
                'INSERT INTO user_avatars (organization_id, user_id, content_type, image_bytes, updated_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE content_type = VALUES(content_type), image_bytes = VALUES(image_bytes), updated_at = UTC_TIMESTAMP(3)'
            );
            $statement->bindValue(1, (string)$principal['organizationId']);
            $statement->bindValue(2, (string)$principal['userId']);
            $statement->bindValue(3, $image['mime']);
            $statement->bindValue(4, $image['bytes'], PDO::PARAM_LOB);
            $statement->execute();
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(204);
    }

    private static function delete(PDO $db, array $principal): never
    {
        $db->beginTransaction();
        try {
            self::requireActiveMembership($db, (string)$principal['organizationId'], (string)$principal['userId'], true);
            $db->prepare('DELETE FROM user_avatars WHERE organization_id = ? AND user_id = ?')
                ->execute([(string)$principal['organizationId'], (string)$principal['userId']]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(204);
    }

    private static function read(PDO $db, array $principal, string $targetUserId): never
    {
        self::requireActiveMembership($db, (string)$principal['organizationId'], $targetUserId, false);
        $query = $db->prepare('SELECT a.content_type, a.image_bytes FROM user_avatars a WHERE a.organization_id = ? AND a.user_id = ?');
        $query->execute([(string)$principal['organizationId'], $targetUserId]);
        $avatar = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($avatar)) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Avatar not found.']);

        $mime = (string)$avatar['content_type'];
        if (!in_array($mime, array_values(self::MIME_BY_IMAGE_TYPE), true) || !is_string($avatar['image_bytes'])) {
            throw new RuntimeException('Stored avatar metadata is invalid.');
        }
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($avatar['image_bytes']));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $avatar['image_bytes'];
        exit;
    }
}
