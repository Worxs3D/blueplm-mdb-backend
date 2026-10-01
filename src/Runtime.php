<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;
use PDOException;

final class Runtime
{
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function passwordHash(string $password): string
    {
        $salt = bin2hex(random_bytes(16));
        $hash = hash_pbkdf2('sha256', $password, $salt, 600000, 64, true);
        return 'pbkdf2$sha256$600000$' . $salt . '$' . bin2hex($hash);
    }

    public static function passwordVerify(string $password, string $encoded): bool
    {
        $parts = explode('$', $encoded);
        if (count($parts) !== 5 || $parts[0] !== 'pbkdf2' || $parts[1] !== 'sha256' || !ctype_digit($parts[2]) || strlen($parts[3]) < 16) return false;
        $iterations = (int)$parts[2];
        if ($iterations < 100000) return false;
        $actual = hash_pbkdf2('sha256', $password, $parts[3], $iterations, 64, true);
        return hash_equals($parts[4], bin2hex($actual));
    }

    /** @param array<string, string> $env */
    public static function encryptSecret(string $plaintext, array $env): string
    {
        if (!function_exists('openssl_encrypt')) throw new \RuntimeException('OpenSSL is required for encrypted administrator secrets.');
        $key = self::secretEncryptionKey($env);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== 16) throw new \RuntimeException('Could not encrypt administrator secret.');
        return rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }

    /** @param array<string, string> $env */
    public static function decryptSecret(string $encoded, array $env): string
    {
        if (!function_exists('openssl_decrypt')) throw new \RuntimeException('OpenSSL is required for encrypted administrator secrets.');
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Stored administrator secret is invalid.');
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::secretEncryptionKey($env), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plaintext === false) throw new \RuntimeException('Stored administrator secret cannot be decrypted.');
        return $plaintext;
    }

    /** @param array<string, string> $env */
    private static function secretEncryptionKey(array $env): string
    {
        $secret = $env['BLUEPLM_SESSION_SECRET'] ?? '';
        if (strlen($secret) < 32) throw new \RuntimeException('BLUEPLM_SESSION_SECRET must be at least 32 characters.');
        return hash('sha256', 'blueplm-admin-secret:' . $secret, true);
    }

    /** @param array<string, string> $env */
    public static function issueSession(PDO $db, array $env, string $userId, string $organizationId): string
    {
        $secret = $env['BLUEPLM_SESSION_SECRET'] ?? '';
        if (strlen($secret) < 32) throw new \RuntimeException('BLUEPLM_SESSION_SECRET must be at least 32 characters.');
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $hash = hash('sha256', $secret . ':' . $token);
        $db->prepare('INSERT INTO sessions (token_hash, user_id, organization_id, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 7 DAY))')->execute([$hash, $userId, $organizationId]);
        return $token;
    }

    /** @param array<string, string> $env */
    public static function tokenHash(string $token, array $env): string
    {
        $secret = $env['BLUEPLM_SESSION_SECRET'] ?? '';
        if (strlen($secret) < 32) throw new \RuntimeException('BLUEPLM_SESSION_SECRET must be at least 32 characters.');
        return hash('sha256', $secret . ':' . $token);
    }

    /** @param array<string, mixed> $payload */
    public static function emitEvent(PDO $db, string $organizationId, string $type, string $aggregateId, array $payload): void
    {
        $db->prepare('INSERT INTO events (organization_id, type, aggregate_id, payload) VALUES (?, ?, ?, ?)')
            ->execute([$organizationId, $type, $aggregateId, json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string, string> $env */
    public static function checkoutExpiry(array $env): string
    {
        $minutes = (int)($env['BLUEPLM_CHECKOUT_TTL_MINUTES'] ?? 480);
        if ($minutes < 5 || $minutes > 1440) throw new \RuntimeException('BLUEPLM_CHECKOUT_TTL_MINUTES must be between 5 and 1440.');
        return gmdate('Y-m-d H:i:s.v', time() + $minutes * 60);
    }

    /** @param array<string, mixed> $principal */
    public static function requireVault(PDO $db, array $principal, string $vaultId): array
    {
        $query = $db->prepare('SELECT * FROM vaults WHERE id = ? AND organization_id = ?');
        $query->execute([$vaultId, $principal['organizationId']]);
        $vault = $query->fetch();
        if (!$vault) self::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Vault not found.']);
        if (in_array($principal['role'], ['owner', 'admin'], true)) return $vault;
        if ($principal['role'] === 'guest') {
            $access = $db->prepare('SELECT 1 FROM vault_access WHERE vault_id = ? AND user_id = ? LIMIT 1');
            $access->execute([$vaultId, $principal['userId']]);
        } else {
            $access = $db->prepare(
                'SELECT 1 FROM vault_access a WHERE a.vault_id = ? AND a.user_id = ?
                 UNION SELECT 1 FROM team_vault_access a JOIN team_members m ON m.team_id = a.team_id
                 WHERE a.vault_id = ? AND m.user_id = ? LIMIT 1'
            );
            $access->execute([$vaultId, $principal['userId'], $vaultId, $principal['userId']]);
        }
        if (!$access->fetchColumn()) self::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Vault not found.']);
        return $vault;
    }
    /** @return array<string, string> */
    public static function env(string $path): array
    {
        $values = [];
        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value) && (str_starts_with($key, 'BLUEPLM_') || str_starts_with($key, 'MARIADB_'))) $values[$key] = $value;
        }
        if (!is_file($path)) return $values;
        $section = '';
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (preg_match('/^\[([^\]]+)]$/', $line, $matches)) {
                $section = strtolower(trim($matches[1]));
                continue;
            }
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"");
            // The shared project configuration predates the MDB adapter
            // and keeps database values in an INI-like [Mariadb] section.
            // Map only that local section; explicit MARIADB_* variables always
            // take precedence and the unrelated FTP section remains ignored.
            if ($section === 'mariadb') {
                $key = match (strtolower($key)) {
                    'url', 'host' => 'MARIADB_HOST',
                    'port' => 'MARIADB_PORT',
                    'dbname', 'database', 'datenbank' => 'MARIADB_DATABASE',
                    'benutzername', 'username', 'user' => 'MARIADB_USER',
                    'passwort', 'password' => 'MARIADB_PASSWORD',
                    default => $key,
                };
            }
            $values[$key] ??= $value;
        }
        return $values;
    }

    /** @param array<string, string> $env */
    public static function database(array $env): PDO
    {
        foreach (['MARIADB_HOST', 'MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD'] as $key) {
            if (!isset($env[$key]) || $env[$key] === '') throw new \RuntimeException("Missing {$key}.");
        }
        $port = $env['MARIADB_PORT'] ?? '3306';
        return new PDO(
            "mysql:host={$env['MARIADB_HOST']};port={$port};dbname={$env['MARIADB_DATABASE']};charset=utf8mb4",
            $env['MARIADB_USER'],
            $env['MARIADB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \InvalidArgumentException('A JSON object is required.');
        return $decoded;
    }

    /** @param array<string, string> $env */
    public static function sendCors(array $env): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = array_filter(array_map('trim', explode(',', $env['BLUEPLM_CORS_ORIGINS'] ?? '')));
        if ($origin !== '' && in_array($origin, $allowed, true)) header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    /** @param array<string, mixed> $body */
    public static function respond(int $status, array $body = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if ($status !== 204) echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /** @param array<string, string> $env @return array<string, mixed> */
    public static function principal(PDO $db, array $env): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\\s+(.+)$/i', $header, $matches)) self::respond(401, ['error' => 'UNAUTHENTICATED', 'message' => 'Bearer token required.']);
        $secret = $env['BLUEPLM_SESSION_SECRET'] ?? '';
        if (strlen($secret) < 32) throw new \RuntimeException('BLUEPLM_SESSION_SECRET must be at least 32 characters.');
        // Match the TypeScript reference backend exactly. The client only sees
        // the opaque token; both runtimes persist this derived hash.
        $hash = hash('sha256', $secret . ':' . $matches[1]);
        $query = $db->prepare(
            'SELECT s.user_id AS userId, s.organization_id AS organizationId, u.email, u.display_name AS displayName, m.role, u.created_at AS createdAt
             FROM sessions s JOIN users u ON u.id = s.user_id
             JOIN organization_memberships m ON m.user_id = s.user_id AND m.organization_id = s.organization_id
             WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP(3) AND u.disabled_at IS NULL'
        );
        $query->execute([$hash]);
        $principal = $query->fetch();
        if (!$principal) self::respond(401, ['error' => 'UNAUTHENTICATED', 'message' => 'Session is invalid or expired.']);
        $db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(3) WHERE token_hash = ?')->execute([$hash]);
        return $principal;
    }
}
