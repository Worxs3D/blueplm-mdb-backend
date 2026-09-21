<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

/**
 * The one-time installation module. Its small interface deliberately keeps
 * deployment-specific work (FTP, hosting panel, NAS permissions) outside the
 * server: callers provide only an already-connected database and public setup
 * data, while secrets remain in the private environment file.
 */
final class Installation
{
    /** @param array<string, string> $env */
    public static function requireBootstrapToken(string $token, array $env): void
    {
        $expected = $env['BLUEPLM_BOOTSTRAP_TOKEN'] ?? '';
        if (!is_string($token) || strlen($expected) < 32 || !hash_equals($expected, $token)) {
            throw new \RuntimeException('Bootstrap authorization failed.');
        }
    }

    /** @param array<string, string> $env */
    public static function requireMaintenanceToken(string $token, array $env): void
    {
        $expected = $env['BLUEPLM_MAINTENANCE_TOKEN'] ?? '';
        if (!is_string($token) || strlen($expected) < 32 || !hash_equals($expected, $token)) {
            throw new \RuntimeException('Maintenance authorization failed.');
        }
    }

    public static function isComplete(PDO $db): bool
    {
        $table = $db->query("SHOW TABLES LIKE 'installation_state'");
        if (!$table || !$table->fetchColumn()) return false;
        $state = $db->query('SELECT bootstrapped_at FROM installation_state WHERE singleton_id = 1')->fetch();
        return is_array($state) && $state['bootstrapped_at'] !== null;
    }

    /**
     * Remove the one-time setup credential from a file-backed deployment.
     *
     * A completed installation is the authorization boundary regardless of this
     * result; hosts may intentionally make the private environment file read-only
     * or provide the token as a process environment variable instead.
     */
    public static function retireBootstrapToken(string $environmentPath): bool
    {
        if (!is_file($environmentPath) || is_link($environmentPath) || !is_writable($environmentPath)) return false;
        $contents = file_get_contents($environmentPath);
        if (!is_string($contents)) return false;
        $updated = preg_replace('/^[ \t]*BLUEPLM_BOOTSTRAP_TOKEN[ \t]*=.*(?:\R|$)/m', '', $contents, 1, $removed);
        if (!is_string($updated) || $removed !== 1) return false;

        $temporaryPath = dirname($environmentPath) . DIRECTORY_SEPARATOR . '.' . basename($environmentPath) . '.blueplm-' . bin2hex(random_bytes(8));
        $permissions = fileperms($environmentPath);
        try {
            if (file_put_contents($temporaryPath, $updated, LOCK_EX) === false) return false;
            if (is_int($permissions)) chmod($temporaryPath, $permissions & 0777);
            return rename($temporaryPath, $environmentPath);
        } finally {
            if (is_file($temporaryPath)) @unlink($temporaryPath);
        }
    }

    /**
     * @param array{organizationName:string,organizationSlug:string,email:string,displayName:string,password:string,vaultName?:string,networkRoot?:string,storageProvider?:string,googleDriveFolderId?:string,enableTotp?:bool} $input
     * @param array<string, string> $env
     * @return array{userId:string,organizationId:string,totpSecret:?string}
     */
    public static function bootstrap(PDO $db, array $env, array $input): array
    {
        if (self::isComplete($db) || (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn() !== 0) {
            throw new \RuntimeException('Installation has already been completed.');
        }
        $organizationName = trim($input['organizationName']);
        $slug = trim($input['organizationSlug']);
        $email = strtolower(trim($input['email']));
        $displayName = trim($input['displayName']);
        $password = $input['password'];
        $vaultName = trim((string)($input['vaultName'] ?? ''));
        $networkRoot = trim((string)($input['networkRoot'] ?? ''));
        $storageProvider = (string)($input['storageProvider'] ?? 'network');
        $googleDriveFolderId = trim((string)($input['googleDriveFolderId'] ?? ''));
        if ($organizationName === '' || strlen($organizationName) > 200) {
            throw new \InvalidArgumentException('Company name is required and must not exceed 200 characters.');
        }
        if (!preg_match('/^[a-z0-9-]{2,100}$/', $slug)) {
            throw new \InvalidArgumentException('Company slug must use 2–100 lowercase letters, digits, or hyphens (for example: doener).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid owner email address.');
        }
        if ($displayName === '' || strlen($displayName) > 200) {
            throw new \InvalidArgumentException('Owner name is required and must not exceed 200 characters.');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Owner password must contain at least 12 characters.');
        }
        if (!in_array($storageProvider, ['network', 'google_drive'], true)) {
            throw new \InvalidArgumentException('Choose either network or Google Drive vault storage.');
        }
        if ($storageProvider === 'network' && (($vaultName === '') !== ($networkRoot === ''))) {
            throw new \InvalidArgumentException('Vault name and network archive path must be entered together.');
        }
        if ($storageProvider === 'google_drive' && (($vaultName === '') !== ($googleDriveFolderId === ''))) {
            throw new \InvalidArgumentException('Vault name and Google Drive folder ID must be entered together.');
        }
        if (strlen($vaultName) > 200 || strlen($networkRoot) > 1024 || strlen($googleDriveFolderId) > 512) throw new \InvalidArgumentException('Vault configuration is too long.');

        $organizationId = Runtime::uuid();
        $userId = Runtime::uuid();
        $totpSecret = !empty($input['enableTotp']) ? Totp::generateSecret() : null;
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO organizations (id, name, slug) VALUES (?, ?, ?)')->execute([$organizationId, $organizationName, $slug]);
            $db->prepare('INSERT INTO users (id, email, display_name, password_hash) VALUES (?, ?, ?, ?)')->execute([$userId, $email, $displayName, Runtime::passwordHash($password)]);
            $db->prepare("INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$organizationId, $userId]);
            $db->prepare('INSERT INTO organization_settings (organization_id) VALUES (?)')->execute([$organizationId]);
            if ($vaultName !== '') {
                $vaultId = Runtime::uuid();
                $providerConfig = $storageProvider === 'google_drive'
                    ? json_encode(['googleDriveFolderId' => $googleDriveFolderId], JSON_THROW_ON_ERROR)
                    : null;
                $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root, storage_provider, provider_config) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$vaultId, $organizationId, $vaultName, $storageProvider === 'network' ? $networkRoot : null, $storageProvider, $providerConfig]);
            }
            if ($totpSecret !== null) {
                $db->prepare('INSERT INTO admin_totp_credentials (user_id, secret_ciphertext) VALUES (?, ?)')
                    ->execute([$userId, Runtime::encryptSecret($totpSecret, $env)]);
            }
            $db->prepare('INSERT INTO installation_state (singleton_id, bootstrapped_at, bootstrapped_by) VALUES (1, UTC_TIMESTAMP(3), ?)')
                ->execute([$userId]);
            Runtime::emitEvent($db, $organizationId, 'installation.completed', $organizationId, ['userId' => $userId, 'totpEnabled' => $totpSecret !== null]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        return ['userId' => $userId, 'organizationId' => $organizationId, 'totpSecret' => $totpSecret];
    }
}
