<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Runtime.php';
require dirname(__DIR__) . '/src/Migrator.php';
require dirname(__DIR__) . '/src/FileReferences.php';
require dirname(__DIR__) . '/src/Totp.php';
require dirname(__DIR__) . '/src/Installation.php';
require dirname(__DIR__) . '/src/DatabaseLifecycle.php';

use BluePlm\Migrator;
use BluePlm\FileReferences;
use BluePlm\Installation;
use BluePlm\Runtime;
use BluePlm\DatabaseLifecycle;
use BluePlm\Totp;

const BLUEPLM_API_VERSION = 2;

/** @return array<string, mixed> */
function decodeOrganizationSetting(mixed $value): array
{
    if (!is_string($value) || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<string, mixed> */
function authProviderSettings(PDO $db, string $organizationId): array
{
    $query = $db->prepare('SELECT auth_provider_settings FROM organization_settings WHERE organization_id = ?');
    $query->execute([$organizationId]);
    $settings = decodeOrganizationSetting($query->fetchColumn());
    return [
        'selfRegistration' => ($settings['selfRegistration'] ?? false) === true,
        'users' => is_array($settings['users'] ?? null) ? $settings['users'] : [],
        'suppliers' => is_array($settings['suppliers'] ?? null) ? $settings['suppliers'] : [],
    ];
}

/** @return array{id:string,name:string,slug:string}|null */
function registrationOrganization(PDO $db, mixed $slug = null): ?array
{
    if (is_string($slug) && trim($slug) !== '') {
        $query = $db->prepare('SELECT id, name, slug FROM organizations WHERE slug = ? LIMIT 1');
        $query->execute([strtolower(trim($slug))]);
    } else {
        $query = $db->query('SELECT id, name, slug FROM organizations ORDER BY created_at LIMIT 1');
    }
    if (!$query) return null;
    $organization = $query->fetch();
    return is_array($organization) ? $organization : null;
}

/** @param array<string, mixed> $settings */
function formatOrganizationSerial(array $settings, int $counter): string
{
    $prefix = is_string($settings['prefix'] ?? null) ? $settings['prefix'] : 'PN-';
    $letterPrefix = is_string($settings['letter_prefix'] ?? null) ? $settings['letter_prefix'] : '';
    $suffix = is_string($settings['suffix'] ?? null) ? $settings['suffix'] : '';
    $padding = is_int($settings['padding_digits'] ?? null) ? max(1, min(20, $settings['padding_digits'])) : 5;
    return $prefix . $letterPrefix . str_pad((string)$counter, $padding, '0', STR_PAD_LEFT) . $suffix;
}

/** @param array<string, mixed> $settings */
function nextOrganizationSerialCounter(array $settings, int $current): int
{
    $candidate = $current + 1;
    $zones = is_array($settings['keepout_zones'] ?? null) ? $settings['keepout_zones'] : [];
    do {
        $moved = false;
        foreach ($zones as $zone) {
            if (!is_array($zone)) continue;
            $start = $zone['start'] ?? null;
            $end = $zone['end_num'] ?? null;
            if (!is_int($start) || !is_int($end) || $end < $start) continue;
            if ($candidate >= $start && $candidate <= $end) {
                $candidate = $end + 1;
                $moved = true;
            }
        }
    } while ($moved);
    return $candidate;
}

/**
 * Keep the first MDB organization usable without coupling workflow roles to
 * account roles. These are ordinary editable records; no user assignment is
 * created here. The labels are organization data, not translated UI copy.
 */
function ensureWorkflowRoleDefaults(PDO $db, string $organizationId, string $createdBy): void
{
    $existing = $db->prepare('SELECT 1 FROM workflow_roles WHERE org_id = ? LIMIT 1');
    $existing->execute([$organizationId]);
    if ($existing->fetchColumn()) return;

    $defaults = [
        ['Administrators', '#DC2626', 'shield', 0],
        ['Engineers', '#2563EB', 'wrench', 1],
        ['Viewers', '#64748B', 'eye', 2],
    ];
    $insert = $db->prepare(
        'INSERT IGNORE INTO workflow_roles (id, org_id, name, color, icon, sort_order, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($defaults as [$name, $color, $icon, $sortOrder]) {
        $insert->execute([Runtime::uuid(), $organizationId, $name, $color, $icon, $sortOrder, $createdBy]);
    }
}

/** @return array<int, array{id:string,width:int,visible:bool}> */
function normalizeColumnDefaults(mixed $value): array
{
    if (!is_array($value) || count($value) > 100) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'columnDefaults must be an array with at most 100 entries.']);
    $normalized = [];
    foreach ($value as $entry) {
        if (!is_array($entry) || !is_string($entry['id'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $entry['id']) || !is_int($entry['width'] ?? null) || $entry['width'] < 40 || $entry['width'] > 500 || !is_bool($entry['visible'] ?? null)) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Each column default needs a valid id, width from 40 to 500, and visible flag.']);
        }
        $normalized[] = ['id' => $entry['id'], 'width' => $entry['width'], 'visible' => $entry['visible']];
    }
    return $normalized;
}

/** @param array<string, mixed> $principal */
function canManageItemDesignations(PDO $db, array $principal, ?string $vaultId = null): bool
{
    if (in_array($principal['role'], ['owner', 'admin'], true)) return true;
    if (in_array($principal['role'], ['viewer', 'guest'], true)) return false;
    $sql = 'SELECT actions FROM user_permissions WHERE organization_id = ? AND user_id = ? AND resource = ?';
    $params = [$principal['organizationId'], $principal['userId'], 'system:item-designations'];
    if ($vaultId === null) {
        $sql .= ' AND vault_id IS NULL';
    } else {
        $sql .= ' AND (vault_id IS NULL OR vault_id = ?)';
        $params[] = $vaultId;
    }
    $query = $db->prepare($sql);
    $query->execute($params);
    foreach ($query->fetchAll() as $permission) {
        $actions = json_decode((string)$permission['actions'], true);
        if (is_array($actions) && (in_array('edit', $actions, true) || in_array('admin', $actions, true))) return true;
    }
    return false;
}

header_remove('X-Powered-By');
$root = dirname(__DIR__);
$path = '/' . trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Inspect and update through a short-lived private environment file. The live
// .env is not touched until the operator has explicitly chosen an action.
if (in_array($path, ['/installer/database-status', '/installer/commit'], true)) {
    $pendingEnvironmentPath = $root . '/.env.install';
    $pendingEnv = Runtime::env($pendingEnvironmentPath);
    Runtime::sendCors($pendingEnv);
    if ($method === 'OPTIONS') Runtime::respond(204);
    if ($method !== 'POST') Runtime::respond(405, ['error' => 'METHOD_NOT_ALLOWED']);
    $body = Runtime::jsonBody();
    $providedToken = is_string($body['installationToken'] ?? null) ? $body['installationToken'] : '';
    $expectedToken = $pendingEnv['BLUEPLM_INSTALLATION_TOKEN'] ?? '';
    if (strlen($expectedToken) < 32 || !hash_equals($expectedToken, $providedToken)) {
        Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Installation authorization failed.']);
    }
    try {
        $db = Runtime::database($pendingEnv);
        $inspection = DatabaseLifecycle::inspect($db, $root . '/migrations');
        if ($path === '/installer/database-status') {
            @unlink($pendingEnvironmentPath);
            Runtime::respond(200, ['database' => $inspection]);
        }

        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        if ($action === 'migrate') {
            if (!in_array($inspection['state'], ['managed', 'legacy'], true)) {
                @unlink($pendingEnvironmentPath);
                Runtime::respond(409, ['error' => 'MIGRATION_UNSAFE', 'message' => 'Only a recognized BluePLM database can be migrated automatically.']);
            }
        } elseif ($action === 'reset') {
        } elseif ($action !== 'install' || $inspection['state'] !== 'empty') {
            @unlink($pendingEnvironmentPath);
            Runtime::respond(409, ['error' => 'DATABASE_STATE_CHANGED', 'message' => 'Inspect the database again before continuing.']);
        }

        $needsBootstrap = $action !== 'migrate' || !$inspection['bootstrapped'];
        $bootstrap = [];
        if ($needsBootstrap) {
            $bootstrap = is_array($body['bootstrap'] ?? null) ? $body['bootstrap'] : [];
            foreach (['organizationName', 'organizationSlug', 'email', 'displayName', 'password'] as $key) {
                if (!is_string($bootstrap[$key] ?? null) || trim($bootstrap[$key]) === '') {
                    @unlink($pendingEnvironmentPath);
                    Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "{$key} is required."]);
                }
            }
        }

        if ($action === 'reset') {
            DatabaseLifecycle::reset($db, is_string($body['confirmation'] ?? null) ? $body['confirmation'] : '');
            $inspection = DatabaseLifecycle::inspect($db, $root . '/migrations');
        }

        $applied = Migrator::apply($db, $root . '/migrations');
        $sessionToken = null;
        if ($needsBootstrap) {
            $result = Installation::bootstrap($db, $pendingEnv, [
                'organizationName' => $bootstrap['organizationName'],
                'organizationSlug' => $bootstrap['organizationSlug'],
                'email' => $bootstrap['email'],
                'displayName' => $bootstrap['displayName'],
                'password' => $bootstrap['password'],
                'vaultName' => is_string($bootstrap['vaultName'] ?? null) ? $bootstrap['vaultName'] : '',
                'networkRoot' => is_string($bootstrap['networkRoot'] ?? null) ? $bootstrap['networkRoot'] : '',
                'enableTotp' => false,
            ]);
            $sessionToken = Runtime::issueSession($db, $pendingEnv, $result['userId'], $result['organizationId']);
            if (!Installation::retireBootstrapToken($pendingEnvironmentPath)) {
                throw new \RuntimeException('The one-time bootstrap token could not be retired.');
            }
        }

        $activationRoot = is_string($_SERVER['BLUEPLM_LIVE_ROOT'] ?? null)
            ? rtrim($_SERVER['BLUEPLM_LIVE_ROOT'], '/\\')
            : $root;
        $liveEnvironmentPath = $activationRoot . '/.env';
        $stagedEnvironmentPath = $root . '/.env';
        if (!Installation::retireInstallationToken($pendingEnvironmentPath)) {
            throw new \RuntimeException('The one-time installation token could not be retired.');
        }
        $promoteEnvironment = $action !== 'migrate' || !$inspection['bootstrapped'] || !is_file($liveEnvironmentPath);
        if ($promoteEnvironment && !@rename($pendingEnvironmentPath, $stagedEnvironmentPath)) {
            throw new \RuntimeException('The private server environment could not be activated.');
        }
        if (!$promoteEnvironment) @unlink($pendingEnvironmentPath);
        Runtime::respond(200, [
            'applied' => $applied,
            'bootstrapped' => $needsBootstrap,
            'token' => $sessionToken,
            'promoteEnvironment' => $promoteEnvironment,
        ]);
    } catch (\InvalidArgumentException $error) {
        @unlink($pendingEnvironmentPath);
        Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $error->getMessage()]);
    } catch (\Throwable $error) {
        @unlink($pendingEnvironmentPath);
        Runtime::respond(500, ['error' => 'INSTALLATION_FAILED', 'message' => $error->getMessage()]);
    }
}

$env = Runtime::env($root . '/.env');
Runtime::sendCors($env);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') Runtime::respond(204);

try {
    if ($path === '/health') Runtime::respond(200, [
        'ok' => true,
        'runtime' => 'php',
        'supabase' => false,
        'apiVersion' => BLUEPLM_API_VERSION,
    ]);
    $db = Runtime::database($env);

    // Shared hosting commonly has no SSH access. Schema updates therefore use
    // a separate high-entropy maintenance secret; the one-time bootstrap
    // secret can be removed after the first setup is complete.
    if ($method === 'POST' && $path === '/admin/migrate') {
        $body = Runtime::jsonBody();
        $token = $body['maintenanceToken'] ?? '';
        try {
            Installation::requireMaintenanceToken(is_string($token) ? $token : '', $env);
        } catch (Throwable) {
            Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Migration token is invalid.']);
        }
        try {
            $applied = Migrator::apply($db, dirname(__DIR__) . '/migrations');
        } catch (Throwable $error) {
            Runtime::respond(500, ['error' => 'MIGRATION_FAILED', 'message' => $error->getMessage()]);
        }
        Runtime::respond(200, ['applied' => $applied]);
    }
    if ($method === 'POST' && $path === '/auth/bootstrap') {
        $body = Runtime::jsonBody();
        foreach (['bootstrapToken', 'organizationName', 'organizationSlug', 'email', 'displayName', 'password'] as $key) {
            if (!is_string($body[$key] ?? null) || trim($body[$key]) === '') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "{$key} is required."]);
        }
        try {
            Installation::requireBootstrapToken($body['bootstrapToken'], $env);
            $result = Installation::bootstrap($db, $env, [
                'organizationName' => $body['organizationName'], 'organizationSlug' => $body['organizationSlug'],
                'email' => $body['email'], 'displayName' => $body['displayName'], 'password' => $body['password'],
                'vaultName' => is_string($body['vaultName'] ?? null) ? $body['vaultName'] : '',
                'networkRoot' => is_string($body['networkRoot'] ?? null) ? $body['networkRoot'] : '',
                'enableTotp' => false,
            ]);
            $token = Runtime::issueSession($db, $env, $result['userId'], $result['organizationId']);
        } catch (\InvalidArgumentException $error) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => $error->getMessage()]);
        } catch (\RuntimeException $error) {
            Runtime::respond(409, ['error' => 'ALREADY_BOOTSTRAPPED', 'message' => $error->getMessage()]);
        }
        Runtime::respond(201, ['token' => $token, 'expiresAt' => gmdate('c', time() + 7 * 86400)]);
    }
    if ($method === 'POST' && $path === '/auth/login') {
        $body = Runtime::jsonBody();
        if (!is_string($body['email'] ?? null) || !is_string($body['password'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Email and password are required.']);
        $query = $db->prepare('SELECT u.id, u.password_hash, m.organization_id FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.email = ? AND u.disabled_at IS NULL ORDER BY m.created_at LIMIT 1');
        $query->execute([strtolower($body['email'])]); $user = $query->fetch();
        if (!$user || !Runtime::passwordVerify($body['password'], $user['password_hash'])) Runtime::respond(401, ['error' => 'UNAUTHENTICATED', 'message' => 'Invalid email or password.']);
        $totp = $db->prepare('SELECT 1 FROM admin_totp_credentials WHERE user_id = ?');
        $totp->execute([$user['id']]);
        if ($totp->fetchColumn()) {
            $challengeToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $db->prepare('DELETE FROM auth_totp_challenges WHERE user_id = ? OR expires_at <= UTC_TIMESTAMP(3)')->execute([$user['id']]);
            $db->prepare('INSERT INTO auth_totp_challenges (token_hash, user_id, organization_id, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE))')
                ->execute([Runtime::tokenHash($challengeToken, $env), $user['id'], $user['organization_id']]);
            Runtime::respond(200, ['totpRequired' => true, 'challengeToken' => $challengeToken, 'expiresAt' => gmdate('c', time() + 300)]);
        }
        Runtime::respond(200, ['token' => Runtime::issueSession($db, $env, $user['id'], $user['organization_id']), 'expiresAt' => gmdate('c', time() + 7 * 86400)]);
    }
    if ($method === 'GET' && $path === '/auth/registration') {
        $organization = registrationOrganization($db, $_GET['organizationSlug'] ?? null);
        if (!$organization) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'No organization is configured.']);
        $settings = authProviderSettings($db, $organization['id']);
        Runtime::respond(200, [
            'enabled' => $settings['selfRegistration'] === true,
            'organizationId' => $organization['id'],
            'organizationName' => $organization['name'],
        ]);
    }
    if ($method === 'POST' && $path === '/auth/register') {
        $body = Runtime::jsonBody();
        $organization = registrationOrganization($db, $body['organizationSlug'] ?? null);
        if (!$organization) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'No organization is configured.']);
        if (authProviderSettings($db, $organization['id'])['selfRegistration'] !== true) {
            Runtime::respond(403, ['error' => 'REGISTRATION_DISABLED', 'message' => 'Self-registration is disabled for this organization.']);
        }
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $displayName = is_string($body['displayName'] ?? null) ? trim($body['displayName']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320 || $displayName === '' || strlen($displayName) > 200 || strlen($password) < 12) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid email, display name, and password of at least 12 characters are required.']);
        }
        $existingUser = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $existingUser->execute([$email]);
        if ($existingUser->fetchColumn()) Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An account with this email already exists.']);
        $pending = $db->prepare("SELECT 1 FROM registration_requests WHERE organization_id = ? AND email = ? AND status = 'pending' LIMIT 1");
        $pending->execute([$organization['id'], $email]);
        if ($pending->fetchColumn()) Runtime::respond(409, ['error' => 'ALREADY_PENDING', 'message' => 'A registration request for this email is already pending.']);
        $requestId = Runtime::uuid();
        $db->prepare('INSERT INTO registration_requests (id, organization_id, email, display_name, password_hash) VALUES (?, ?, ?, ?, ?)')
            ->execute([$requestId, $organization['id'], $email, $displayName, Runtime::passwordHash($password)]);
        Runtime::respond(202, ['status' => 'pending', 'requestId' => $requestId]);
    }
    if ($method === 'POST' && $path === '/auth/recovery-register') {
        $body = Runtime::jsonBody();
        $organization = registrationOrganization($db, $body['organizationSlug'] ?? null);
        if (!$organization) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'No organization is configured.']);
        $code = is_string($body['recoveryCode'] ?? null) ? strtoupper(trim($body['recoveryCode'])) : '';
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $displayName = is_string($body['displayName'] ?? null) ? trim($body['displayName']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!preg_match('/^[A-Z2-9]{4}(?:-[A-Z2-9]{4}){3}$/', $code)
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320
            || $displayName === '' || strlen($displayName) > 200 || strlen($password) < 12) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid recovery code, email, display name, and password of at least 12 characters are required.']);
        }
        $existingUser = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $existingUser->execute([$email]);
        if ($existingUser->fetchColumn()) Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An account with this email already exists.']);

        $db->beginTransaction();
        try {
            $recovery = $db->prepare('SELECT id FROM admin_recovery_codes WHERE organization_id = ? AND code_hash = ? AND is_used = FALSE AND is_revoked = FALSE AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE');
            $recovery->execute([$organization['id'], hash('sha256', str_replace('-', '', $code))]);
            $record = $recovery->fetch();
            if (!$record) {
                $db->rollBack();
                Runtime::respond(401, ['error' => 'RECOVERY_CODE_INVALID', 'message' => 'The recovery code is invalid, expired, used, or revoked.']);
            }
            $userId = Runtime::uuid();
            $db->prepare('INSERT INTO users (id, email, display_name, password_hash) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $email, $displayName, Runtime::passwordHash($password)]);
            $db->prepare("INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, 'admin')")
                ->execute([$organization['id'], $userId]);
            $used = $db->prepare('UPDATE admin_recovery_codes SET is_used = TRUE, used_by = ?, used_at = UTC_TIMESTAMP(3) WHERE id = ? AND is_used = FALSE');
            $used->execute([$userId, $record['id']]);
            if ($used->rowCount() === 0) throw new \RuntimeException('Recovery code was already used.');
            $token = Runtime::issueSession($db, $env, $userId, $organization['id']);
            Runtime::emitEvent($db, $organization['id'], 'registration.recovery_approved', $userId, ['userId' => $userId, 'recoveryCodeId' => $record['id'], 'role' => 'admin']);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An account with this email already exists.']);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(201, [
            'token' => $token,
            'expiresAt' => gmdate('c', time() + 7 * 86400),
            'user' => ['id' => $userId, 'email' => $email, 'displayName' => $displayName, 'role' => 'admin'],
        ]);
    }
    if ($method === 'POST' && $path === '/auth/totp/verify') {
        $body = Runtime::jsonBody();
        $challengeToken = is_string($body['challengeToken'] ?? null) ? $body['challengeToken'] : '';
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        if (strlen($challengeToken) < 32 || !preg_match('/^\d{6}$/', $code)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid challenge and six-digit code are required.']);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT c.user_id, c.organization_id, c.attempts_remaining, t.secret_ciphertext FROM auth_totp_challenges c JOIN admin_totp_credentials t ON t.user_id = c.user_id WHERE c.token_hash = ? AND c.expires_at > UTC_TIMESTAMP(3) FOR UPDATE');
            $query->execute([Runtime::tokenHash($challengeToken, $env)]); $challenge = $query->fetch();
            if (!$challenge || (int)$challenge['attempts_remaining'] < 1) {
                $db->rollBack();
                Runtime::respond(401, ['error' => 'TOTP_CHALLENGE_INVALID', 'message' => 'The authenticator challenge is invalid or expired.']);
            }
            if (!Totp::verify(Runtime::decryptSecret($challenge['secret_ciphertext'], $env), $code)) {
                $db->prepare('UPDATE auth_totp_challenges SET attempts_remaining = attempts_remaining - 1 WHERE token_hash = ?')->execute([Runtime::tokenHash($challengeToken, $env)]);
                $db->commit();
                Runtime::respond(401, ['error' => 'TOTP_INVALID', 'message' => 'The authenticator code is invalid.']);
            }
            $db->prepare('DELETE FROM auth_totp_challenges WHERE user_id = ?')->execute([$challenge['user_id']]);
            $sessionToken = Runtime::issueSession($db, $env, $challenge['user_id'], $challenge['organization_id']);
            $db->commit();
            Runtime::respond(200, ['token' => $sessionToken, 'expiresAt' => gmdate('c', time() + 7 * 86400)]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    if ($method === 'GET' && $path === '/auth/me') {
        Runtime::respond(200, ['user' => Runtime::principal($db, $env)]);
    }
    $principal = Runtime::principal($db, $env);
    if ($method === 'GET' && $path === '/registration-requests') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare("SELECT id, email, display_name AS displayName, status, created_at AS createdAt FROM registration_requests WHERE organization_id = ? AND status = 'pending' ORDER BY created_at");
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['requests' => $query->fetchAll()]);
    }
    if ($method === 'POST' && preg_match('#^/registration-requests/([0-9a-f-]{36})/(approve|reject)$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $requestId = $matches[1];
        $action = $matches[2];
        $query = $db->prepare("SELECT id, organization_id, email, display_name, password_hash FROM registration_requests WHERE id = ? AND organization_id = ? AND status = 'pending'");
        $query->execute([$requestId, $principal['organizationId']]);
        $request = $query->fetch();
        if (!$request) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Registration request not found.']);
        if ($action === 'reject') {
            $db->prepare("UPDATE registration_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(3) WHERE id = ? AND status = 'pending'")
                ->execute([$principal['userId'], $requestId]);
            Runtime::emitEvent($db, $principal['organizationId'], 'registration.rejected', $requestId, ['requestId' => $requestId, 'rejectedBy' => $principal['userId']]);
            Runtime::respond(200, ['status' => 'rejected']);
        }
        $body = Runtime::jsonBody();
        $role = is_string($body['role'] ?? null) ? $body['role'] : '';
        if (!in_array($role, ['admin', 'member', 'viewer', 'guest'], true)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'An explicit non-owner role is required for approval.']);
        $userId = Runtime::uuid();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO users (id, email, display_name, password_hash) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $request['email'], $request['display_name'], $request['password_hash']]);
            $db->prepare('INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, ?)')
                ->execute([$principal['organizationId'], $userId, $role]);
            $update = $db->prepare("UPDATE registration_requests SET status = 'approved', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(3) WHERE id = ? AND status = 'pending'");
            $update->execute([$principal['userId'], $requestId]);
            if ($update->rowCount() === 0) throw new \RuntimeException('Registration request was already processed.');
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An account with this email already exists.']);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'registration.approved', $userId, ['requestId' => $requestId, 'approvedBy' => $principal['userId'], 'role' => $role]);
        Runtime::respond(201, ['user' => ['id' => $userId, 'email' => $request['email'], 'displayName' => $request['display_name'], 'role' => $role]]);
    }
    if (in_array($principal['role'], ['viewer', 'guest'], true) && $method !== 'GET') {
        $personalWrite = $path === '/account'
            || $path === '/recovery-codes/use'
            || $path === '/column-defaults/user'
            || str_starts_with($path, '/device-sessions/')
            || str_starts_with($path, '/account/totp');
        if (!$personalWrite) Runtime::respond(403, ['error' => 'READ_ONLY_ROLE', 'message' => 'Viewer and guest accounts are read-only.']);
    }
    if ($method === 'GET' && $path === '/account/totp') {
        $query = $db->prepare('SELECT enabled_at AS enabledAt FROM admin_totp_credentials WHERE user_id = ?');
        $query->execute([$principal['userId']]);
        $credential = $query->fetch();
        Runtime::respond(200, ['enabled' => (bool)$credential, 'enabledAt' => $credential['enabledAt'] ?? null]);
    }
    if ($method === 'POST' && $path === '/account/totp/enrollment') {
        $secret = Totp::generateSecret();
        $enrollmentToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->prepare('DELETE FROM totp_enrollments WHERE user_id = ? OR expires_at <= UTC_TIMESTAMP(3)')->execute([$principal['userId']]);
        $db->prepare('INSERT INTO totp_enrollments (token_hash, user_id, secret_ciphertext, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE))')
            ->execute([Runtime::tokenHash($enrollmentToken, $env), $principal['userId'], Runtime::encryptSecret($secret, $env)]);
        Runtime::respond(201, [
            'enrollmentToken' => $enrollmentToken,
            'secret' => $secret,
            'provisioningUri' => Totp::provisioningUri('BluePLM MDB', $principal['email'], $secret),
            'expiresAt' => gmdate('c', time() + 600),
        ]);
    }
    if ($method === 'POST' && $path === '/account/totp/confirm') {
        $body = Runtime::jsonBody();
        $enrollmentToken = is_string($body['enrollmentToken'] ?? null) ? $body['enrollmentToken'] : '';
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        if (strlen($enrollmentToken) < 32 || !preg_match('/^\d{6}$/', $code)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid enrollment and six-digit code are required.']);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT secret_ciphertext, attempts_remaining FROM totp_enrollments WHERE token_hash = ? AND user_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE');
            $query->execute([Runtime::tokenHash($enrollmentToken, $env), $principal['userId']]); $enrollment = $query->fetch();
            if (!$enrollment || (int)$enrollment['attempts_remaining'] < 1) {
                $db->rollBack();
                Runtime::respond(401, ['error' => 'TOTP_ENROLLMENT_INVALID', 'message' => 'The authenticator enrollment is invalid or expired.']);
            }
            $secret = Runtime::decryptSecret($enrollment['secret_ciphertext'], $env);
            if (!Totp::verify($secret, $code)) {
                $db->prepare('UPDATE totp_enrollments SET attempts_remaining = attempts_remaining - 1 WHERE token_hash = ?')->execute([Runtime::tokenHash($enrollmentToken, $env)]);
                $db->commit();
                Runtime::respond(401, ['error' => 'TOTP_INVALID', 'message' => 'The authenticator code is invalid.']);
            }
            $db->prepare('INSERT INTO admin_totp_credentials (user_id, secret_ciphertext, enabled_at) VALUES (?, ?, UTC_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), enabled_at = VALUES(enabled_at)')->execute([$principal['userId'], Runtime::encryptSecret($secret, $env)]);
            $db->prepare('DELETE FROM totp_enrollments WHERE user_id = ?')->execute([$principal['userId']]);
            Runtime::emitEvent($db, $principal['organizationId'], 'account.totp_enabled', $principal['userId'], ['userId' => $principal['userId']]);
            $db->commit();
            Runtime::respond(200, ['enabled' => true]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    if ($method === 'DELETE' && $path === '/account/totp') {
        $body = Runtime::jsonBody();
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        if (!preg_match('/^\d{6}$/', $code)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A six-digit authenticator code is required.']);
        $query = $db->prepare('SELECT secret_ciphertext FROM admin_totp_credentials WHERE user_id = ?');
        $query->execute([$principal['userId']]); $credential = $query->fetch();
        if (!$credential) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Authenticator protection is not enabled.']);
        if (!Totp::verify(Runtime::decryptSecret($credential['secret_ciphertext'], $env), $code)) Runtime::respond(401, ['error' => 'TOTP_INVALID', 'message' => 'The authenticator code is invalid.']);
        $db->prepare('DELETE FROM admin_totp_credentials WHERE user_id = ?')->execute([$principal['userId']]);
        Runtime::emitEvent($db, $principal['organizationId'], 'account.totp_disabled', $principal['userId'], ['userId' => $principal['userId']]);
        Runtime::respond(200, ['enabled' => false]);
    }
    if ($method === 'GET' && $path === '/users') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('SELECT u.id, u.email, u.display_name AS displayName, m.role, u.created_at AS createdAt FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE m.organization_id = ? AND u.disabled_at IS NULL ORDER BY u.display_name, u.email');
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['users' => $query->fetchAll()]);
    }
    if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/profile$#i', $path, $matches)) {
        $targetId = $matches[1];
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('SELECT u.id, u.email, u.display_name AS displayName, m.role, u.created_at AS createdAt FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL');
        $query->execute([$targetId, $principal['organizationId']]);
        $profile = $query->fetch();
        if (!$profile) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $teams = $db->prepare('SELECT t.id, t.name, t.color, t.icon FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? AND t.organization_id = ? ORDER BY t.name');
        $teams->execute([$targetId, $principal['organizationId']]);
        $roles = $db->prepare('SELECT wr.id, wr.name, wr.color, wr.icon FROM user_workflow_roles uwr JOIN workflow_roles wr ON wr.id = uwr.workflow_role_id WHERE uwr.user_id = ? AND uwr.org_id = ? AND wr.is_active = TRUE ORDER BY wr.sort_order, wr.name');
        $roles->execute([$targetId, $principal['organizationId']]);
        $profile['teams'] = $teams->fetchAll();
        $profile['workflowRoles'] = $roles->fetchAll();
        Runtime::respond(200, ['user' => $profile]);
    }
    if ($method === 'POST' && $path === '/users') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        $displayName = is_string($body['displayName'] ?? null) ? trim($body['displayName']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $role = is_string($body['role'] ?? null) ? $body['role'] : 'member';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320 || $displayName === '' || strlen($displayName) > 200 || strlen($password) < 12 || !in_array($role, ['admin', 'member', 'viewer', 'guest'], true)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid email, display name, password of at least 12 characters, and role are required.']);
        $userId = Runtime::uuid();
        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO users (id, email, display_name, password_hash) VALUES (?, ?, ?, ?)')->execute([$userId, $email, $displayName, Runtime::passwordHash($password)]);
            $db->prepare('INSERT INTO organization_memberships (organization_id, user_id, role) VALUES (?, ?, ?)')->execute([$principal['organizationId'], $userId, $role]);
            Runtime::emitEvent($db, $principal['organizationId'], 'user.created', $userId, ['userId' => $userId, 'createdBy' => $principal['userId'], 'role' => $role]);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A user with this email already exists.']);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(201, ['id' => $userId, 'email' => $email, 'displayName' => $displayName, 'role' => $role]);
    }
    if ($method === 'PATCH' && preg_match('#^/users/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $targetId = $matches[1];
        $target = $db->prepare('SELECT u.id FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.organization_id = ? AND u.disabled_at IS NULL');
        $target->execute([$targetId, $principal['organizationId']]);
        if (!$target->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $updates = []; $values = []; $changed = []; $revokeSessions = false; $membershipRole = null;
        if (array_key_exists('email', $body)) {
            $email = is_string($body['email']) ? strtolower(trim($body['email'])) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid email address is required.']);
            $updates[] = 'email = ?'; $values[] = $email; $changed[] = 'email'; $revokeSessions = true;
        }
        if (array_key_exists('displayName', $body)) {
            $displayName = is_string($body['displayName']) ? trim($body['displayName']) : '';
            if ($displayName === '' || strlen($displayName) > 200) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid display name is required.']);
            $updates[] = 'display_name = ?'; $values[] = $displayName; $changed[] = 'displayName';
        }
        if (array_key_exists('password', $body)) {
            $password = is_string($body['password']) ? $body['password'] : '';
            if (strlen($password) < 12) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'The password must have at least 12 characters.']);
            $updates[] = 'password_hash = ?'; $values[] = Runtime::passwordHash($password); $changed[] = 'password'; $revokeSessions = true;
        }
        if (array_key_exists('role', $body)) {
            $membershipRole = is_string($body['role']) ? $body['role'] : '';
            if (!in_array($membershipRole, ['admin', 'member', 'viewer', 'guest'], true)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid editable role is required.']);
            $currentRole = $db->prepare('SELECT role FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
            $currentRole->execute([$principal['organizationId'], $targetId]);
            if ($currentRole->fetchColumn() === 'owner') Runtime::respond(409, ['error' => 'OWNER_ROLE_LOCKED', 'message' => 'The owner role cannot be changed through this endpoint.']);
            $changed[] = 'role'; $revokeSessions = true;
        }
        if ($updates === [] && $membershipRole === null) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'At least one editable user field is required.']);
        try {
            $db->beginTransaction();
            if ($updates !== []) {
                $values[] = $targetId;
                $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);
            }
            if ($membershipRole !== null) $db->prepare('UPDATE organization_memberships SET role = ? WHERE organization_id = ? AND user_id = ?')->execute([$membershipRole, $principal['organizationId'], $targetId]);
            if ($revokeSessions) $db->prepare('DELETE FROM sessions WHERE user_id = ? AND organization_id = ?')->execute([$targetId, $principal['organizationId']]);
            Runtime::emitEvent($db, $principal['organizationId'], 'user.credentials_updated', $targetId, ['userId' => $targetId, 'changedFields' => $changed, 'changedBy' => $principal['userId']]);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A user with this email already exists.']);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        $query = $db->prepare('SELECT u.id, u.email, u.display_name AS displayName, m.role, u.created_at AS createdAt FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.organization_id = ?');
        $query->execute([$targetId, $principal['organizationId']]);
        Runtime::respond(200, ['user' => $query->fetch()]);
    }
    if ($method === 'DELETE' && preg_match('#^/users/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $targetId = $matches[1];
        if ($targetId === $principal['userId']) Runtime::respond(400, ['error' => 'SELF_DELETE', 'message' => 'Use the account deletion flow for your own account.']);
        $target = $db->prepare('SELECT m.role FROM organization_memberships m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.user_id = ? AND u.disabled_at IS NULL');
        $target->execute([$principal['organizationId'], $targetId]); $targetRow = $target->fetch();
        if (!$targetRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        if ($targetRow['role'] === 'owner') { $owners = $db->prepare("SELECT COUNT(*) FROM organization_memberships WHERE organization_id = ? AND role = 'owner'"); $owners->execute([$principal['organizationId']]); if ((int)$owners->fetchColumn() <= 1) Runtime::respond(409, ['error' => 'LAST_OWNER', 'message' => 'The last organization owner cannot be removed.']); }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM checkouts WHERE user_id = ?')->execute([$targetId]);
            $db->prepare('DELETE FROM sessions WHERE user_id = ? AND organization_id = ?')->execute([$targetId, $principal['organizationId']]);
            $db->prepare('DELETE FROM organization_memberships WHERE user_id = ? AND organization_id = ?')->execute([$targetId, $principal['organizationId']]);
            $db->prepare('UPDATE users SET disabled_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$targetId]);
            Runtime::emitEvent($db, $principal['organizationId'], 'user.removed', $targetId, ['userId' => $targetId, 'removedBy' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(204);
    }
    if ($method === 'GET' && $path === '/workflow-roles') {
        ensureWorkflowRoleDefaults($db, $principal['organizationId'], $principal['userId']);
        $query = $db->prepare(
            'SELECT id, name, color, icon, description, sort_order
             FROM workflow_roles
             WHERE org_id = ? AND is_active = TRUE
             ORDER BY sort_order, name'
        );
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['roles' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/workflow-roles') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $color = is_string($body['color'] ?? null) ? trim($body['color']) : '#6B7280';
        $icon = is_string($body['icon'] ?? null) ? trim($body['icon']) : 'badge-check';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : null;
        if ($name === '' || strlen($name) > 200 || $color === '' || strlen($color) > 32 || $icon === '' || strlen($icon) > 64 || ($description !== null && strlen($description) > 2000)) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid name, color, icon, and optional description are required.']);
        }
        $roleId = Runtime::uuid();
        try {
            $db->prepare('INSERT INTO workflow_roles (id, org_id, name, color, icon, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$roleId, $principal['organizationId'], $name, $color, $icon, $description ?: null, $principal['userId']]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A workflow role with this name already exists.']);
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'workflow_role.created', $roleId, ['roleId' => $roleId, 'createdBy' => $principal['userId']]);
        Runtime::respond(201, ['role' => ['id' => $roleId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'description' => $description ?: null, 'sort_order' => 0]]);
    }
    if ($method === 'PATCH' && preg_match('#^/workflow-roles/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $roleId = $matches[1];
        $current = $db->prepare('SELECT id, name, color, icon, description, sort_order FROM workflow_roles WHERE id = ? AND org_id = ? AND is_active = TRUE');
        $current->execute([$roleId, $principal['organizationId']]);
        $role = $current->fetch();
        if (!$role) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Workflow role not found.']);
        $name = array_key_exists('name', $body) && is_string($body['name']) ? trim($body['name']) : $role['name'];
        $color = array_key_exists('color', $body) && is_string($body['color']) ? trim($body['color']) : $role['color'];
        $icon = array_key_exists('icon', $body) && is_string($body['icon']) ? trim($body['icon']) : $role['icon'];
        $description = array_key_exists('description', $body) ? (is_string($body['description']) ? trim($body['description']) : null) : $role['description'];
        if ($name === '' || strlen($name) > 200 || $color === '' || strlen($color) > 32 || $icon === '' || strlen($icon) > 64 || ($description !== null && strlen($description) > 2000)) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid name, color, icon, and optional description are required.']);
        }
        try {
            $db->prepare('UPDATE workflow_roles SET name = ?, color = ?, icon = ?, description = ?, updated_by = ? WHERE id = ? AND org_id = ?')
                ->execute([$name, $color, $icon, $description ?: null, $principal['userId'], $roleId, $principal['organizationId']]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A workflow role with this name already exists.']);
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'workflow_role.updated', $roleId, ['roleId' => $roleId, 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['role' => ['id' => $roleId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'description' => $description, 'sort_order' => (int)$role['sort_order']]]);
    }
    if ($method === 'DELETE' && preg_match('#^/workflow-roles/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $roleId = $matches[1];
        $query = $db->prepare('DELETE FROM workflow_roles WHERE id = ? AND org_id = ?');
        $query->execute([$roleId, $principal['organizationId']]);
        if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Workflow role not found.']);
        Runtime::emitEvent($db, $principal['organizationId'], 'workflow_role.deleted', $roleId, ['roleId' => $roleId, 'deletedBy' => $principal['userId']]);
        Runtime::respond(204);
    }
    if ($method === 'GET' && $path === '/workflow-role-assignments') {
        $query = $db->prepare(
            'SELECT user_id, workflow_role_id
             FROM user_workflow_roles uwr
             JOIN workflow_roles wr ON wr.id = uwr.workflow_role_id AND wr.org_id = uwr.org_id
             WHERE uwr.org_id = ? AND wr.is_active = TRUE
             ORDER BY uwr.user_id, uwr.workflow_role_id'
        );
        $query->execute([$principal['organizationId']]);
        $assignments = [];
        foreach ($query->fetchAll() as $assignment) {
            $assignments[$assignment['user_id']][] = $assignment['workflow_role_id'];
        }
        Runtime::respond(200, ['assignments' => (object)$assignments]);
    }
    if ($method === 'PUT' && preg_match('#^/users/([0-9a-f-]{36})/workflow-roles$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $targetId = $matches[1];
        $body = Runtime::jsonBody();
        $roleIds = $body['roleIds'] ?? null;
        if (!is_array($roleIds) || count($roleIds) > 100 || count(array_filter($roleIds, static fn($id) => !is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id))) > 0) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'roleIds must be an array of valid IDs.']);
        }
        $roleIds = array_values(array_unique($roleIds));
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $member->execute([$principal['organizationId'], $targetId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        if ($roleIds !== []) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $roles = $db->prepare("SELECT COUNT(*) FROM workflow_roles WHERE org_id = ? AND is_active = TRUE AND id IN ($placeholders)");
            $roles->execute([$principal['organizationId'], ...$roleIds]);
            if ((int)$roles->fetchColumn() !== count($roleIds)) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'One or more workflow roles were not found.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM user_workflow_roles WHERE org_id = ? AND user_id = ?')->execute([$principal['organizationId'], $targetId]);
            if ($roleIds !== []) {
                $insert = $db->prepare('INSERT INTO user_workflow_roles (id, org_id, user_id, workflow_role_id, assigned_by) VALUES (?, ?, ?, ?, ?)');
                foreach ($roleIds as $roleId) $insert->execute([Runtime::uuid(), $principal['organizationId'], $targetId, $roleId, $principal['userId']]);
            }
            Runtime::emitEvent($db, $principal['organizationId'], 'workflow_role.assignments_updated', $targetId, ['userId' => $targetId, 'roleIds' => $roleIds, 'updatedBy' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['success' => true, 'roleIds' => $roleIds]);
    }
    if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/vault-access$#i', $path, $matches)) {
        $targetId = $matches[1];
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $member->execute([$principal['organizationId'], $targetId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $query = $db->prepare('SELECT va.vault_id FROM vault_access va JOIN vaults v ON v.id = va.vault_id WHERE va.user_id = ? AND v.organization_id = ? ORDER BY va.vault_id');
        $query->execute([$targetId, $principal['organizationId']]);
        Runtime::respond(200, ['vaultIds' => array_column($query->fetchAll(), 'vault_id')]);
    }
    if ($method === 'PUT' && preg_match('#^/users/([0-9a-f-]{36})/vault-access$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $targetId = $matches[1];
        $body = Runtime::jsonBody();
        $vaultIds = $body['vaultIds'] ?? null;
        if (!is_array($vaultIds) || count($vaultIds) > 500 || count(array_filter($vaultIds, static fn($id) => !is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id))) > 0) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'vaultIds must be an array of valid IDs.']);
        $vaultIds = array_values(array_unique($vaultIds));
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $member->execute([$principal['organizationId'], $targetId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        if ($vaultIds !== []) {
            $placeholders = implode(',', array_fill(0, count($vaultIds), '?'));
            $vaults = $db->prepare("SELECT COUNT(*) FROM vaults WHERE organization_id = ? AND id IN ($placeholders)");
            $vaults->execute([$principal['organizationId'], ...$vaultIds]);
            if ((int)$vaults->fetchColumn() !== count($vaultIds)) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'One or more vaults were not found.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE va FROM vault_access va JOIN vaults v ON v.id = va.vault_id WHERE va.user_id = ? AND v.organization_id = ?')->execute([$targetId, $principal['organizationId']]);
            $insert = $db->prepare('INSERT INTO vault_access (vault_id, user_id, granted_by) VALUES (?, ?, ?)');
            foreach ($vaultIds as $vaultId) $insert->execute([$vaultId, $targetId, $principal['userId']]);
            Runtime::emitEvent($db, $principal['organizationId'], 'user.vault_access_updated', $targetId, ['userId' => $targetId, 'updatedBy' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && $path === '/vaults/access') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('SELECT va.user_id, va.vault_id FROM vault_access va JOIN vaults v ON v.id = va.vault_id JOIN organization_memberships m ON m.user_id = va.user_id AND m.organization_id = v.organization_id WHERE v.organization_id = ? ORDER BY va.user_id, va.vault_id');
        $query->execute([$principal['organizationId']]);
        $accessMap = [];
        foreach ($query->fetchAll() as $grant) $accessMap[$grant['vault_id']][] = $grant['user_id'];
        Runtime::respond(200, ['accessMap' => $accessMap]);
    }
    if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/permissions$#i', $path, $matches)) {
        $targetId = $matches[1];
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $member->execute([$principal['organizationId'], $targetId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $vaultId = is_string($_GET['vaultId'] ?? null) && $_GET['vaultId'] !== '' ? $_GET['vaultId'] : null;
        if ($vaultId !== null && !preg_match('/^[0-9a-f-]{36}$/i', $vaultId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'vaultId must be a valid ID.']);
        $query = $db->prepare('SELECT resource, actions FROM user_permissions WHERE organization_id = ? AND user_id = ? AND vault_id <=> ? ORDER BY resource');
        $query->execute([$principal['organizationId'], $targetId, $vaultId]);
        $permissions = [];
        foreach ($query->fetchAll() as $permission) $permissions[] = ['resource' => $permission['resource'], 'actions' => json_decode($permission['actions'], true, 16, JSON_THROW_ON_ERROR)];
        Runtime::respond(200, ['permissions' => $permissions]);
    }
    if ($method === 'PUT' && preg_match('#^/users/([0-9a-f-]{36})/permissions$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $targetId = $matches[1];
        $body = Runtime::jsonBody();
        $vaultId = is_string($body['vaultId'] ?? null) && $body['vaultId'] !== '' ? $body['vaultId'] : null;
        $permissionMap = $body['permissions'] ?? null;
        if ($vaultId !== null && !preg_match('/^[0-9a-f-]{36}$/i', $vaultId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'vaultId must be a valid ID.']);
        if (!is_array($permissionMap) || count($permissionMap) > 200) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'permissions must be an object.']);
        $allowedActions = ['view', 'create', 'edit', 'delete', 'admin'];
        $normalized = [];
        foreach ($permissionMap as $resource => $actions) {
            if (!is_string($resource) || !preg_match('/^[A-Za-z0-9_.:-]{1,200}$/', $resource) || !is_array($actions) || count($actions) > count($allowedActions)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Each permission requires a valid resource and action array.']);
            $actions = array_values(array_unique($actions));
            if (count(array_filter($actions, static fn($action) => !is_string($action) || !in_array($action, $allowedActions, true))) > 0) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A permission contains an unsupported action.']);
            if ($actions !== []) $normalized[$resource] = $actions;
        }
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?');
        $member->execute([$principal['organizationId'], $targetId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        if ($vaultId !== null) {
            $vault = $db->prepare('SELECT 1 FROM vaults WHERE organization_id = ? AND id = ?');
            $vault->execute([$principal['organizationId'], $vaultId]);
            if (!$vault->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Vault not found.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM user_permissions WHERE organization_id = ? AND user_id = ? AND vault_id <=> ?')->execute([$principal['organizationId'], $targetId, $vaultId]);
            $insert = $db->prepare('INSERT INTO user_permissions (id, organization_id, user_id, resource, vault_id, actions, granted_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($normalized as $resource => $actions) $insert->execute([Runtime::uuid(), $principal['organizationId'], $targetId, $resource, $vaultId, json_encode($actions, JSON_THROW_ON_ERROR), $principal['userId']]);
            Runtime::emitEvent($db, $principal['organizationId'], 'user.permissions_updated', $targetId, ['userId' => $targetId, 'vaultId' => $vaultId, 'updatedBy' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/effective-permissions$#i', $path, $matches)) {
        $targetId = $matches[1];
        if ($targetId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $member = $db->prepare('SELECT m.role FROM organization_memberships m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.user_id = ? AND u.disabled_at IS NULL');
        $member->execute([$principal['organizationId'], $targetId]);
        $memberRow = $member->fetch();
        if (!$memberRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);

        $merged = [];
        $mergePermission = static function (array &$target, string $resource, ?string $vaultId, array $actions): void {
            $key = $resource . "\0" . ($vaultId ?? '');
            $target[$key] ??= ['resource' => $resource, 'vaultId' => $vaultId, 'actions' => []];
            $target[$key]['actions'] = array_values(array_unique(array_merge($target[$key]['actions'], $actions)));
        };
        $direct = $db->prepare('SELECT resource, vault_id, actions FROM user_permissions WHERE organization_id = ? AND user_id = ?');
        $direct->execute([$principal['organizationId'], $targetId]);
        foreach ($direct->fetchAll() as $permission) {
            $mergePermission($merged, (string)$permission['resource'], $permission['vault_id'] !== null ? (string)$permission['vault_id'] : null, json_decode($permission['actions'], true, 16, JSON_THROW_ON_ERROR));
        }
        $teamPermissions = $db->prepare('SELECT tp.resource, tp.vault_id, tp.actions FROM team_permissions tp JOIN team_members tm ON tm.team_id = tp.team_id AND tm.user_id = ? WHERE tp.organization_id = ?');
        $teamPermissions->execute([$targetId, $principal['organizationId']]);
        foreach ($teamPermissions->fetchAll() as $permission) {
            $mergePermission($merged, (string)$permission['resource'], $permission['vault_id'] !== null ? (string)$permission['vault_id'] : null, json_decode($permission['actions'], true, 16, JSON_THROW_ON_ERROR));
        }

        $vaultQuery = $db->prepare('SELECT id FROM vaults WHERE organization_id = ? ORDER BY name');
        $vaultQuery->execute([$principal['organizationId']]);
        $allVaultIds = array_values(array_map(static fn(array $vault): string => (string)$vault['id'], $vaultQuery->fetchAll()));
        $accessibleVaultIds = [];
        if (in_array($memberRow['role'], ['owner', 'admin'], true)) {
            $accessibleVaultIds = $allVaultIds;
        } else {
            $directVaults = $db->prepare('SELECT vault_id FROM vault_access WHERE user_id = ? AND vault_id IN (SELECT id FROM vaults WHERE organization_id = ?)');
            $directVaults->execute([$targetId, $principal['organizationId']]);
            $accessibleVaultIds = array_column($directVaults->fetchAll(), 'vault_id');
            $teamIdsQuery = $db->prepare('SELECT team_id FROM team_members WHERE user_id = ?');
            $teamIdsQuery->execute([$targetId]);
            foreach ($teamIdsQuery->fetchAll() as $teamRow) {
                $restricted = $db->prepare('SELECT vault_id FROM team_vault_access WHERE team_id = ?');
                $restricted->execute([$teamRow['team_id']]);
                $teamVaultIds = array_column($restricted->fetchAll(), 'vault_id');
                $accessibleVaultIds = array_merge($accessibleVaultIds, $teamVaultIds === [] ? $allVaultIds : $teamVaultIds);
            }
        }
        Runtime::respond(200, ['permissions' => array_values($merged), 'vaultIds' => array_values(array_unique($accessibleVaultIds))]);
    }
    if ($method === 'GET' && preg_match('#^/teams/([0-9a-f-]{36})/permissions$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $teamId = $matches[1];
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?');
        $team->execute([$teamId, $principal['organizationId']]);
        if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $query = $db->prepare('SELECT resource, vault_id AS vaultId, actions FROM team_permissions WHERE team_id = ? AND organization_id = ? ORDER BY resource, vault_id');
        $query->execute([$teamId, $principal['organizationId']]);
        $permissions = [];
        foreach ($query->fetchAll() as $permission) $permissions[] = ['resource' => $permission['resource'], 'vaultId' => $permission['vaultId'], 'actions' => json_decode($permission['actions'], true, 16, JSON_THROW_ON_ERROR)];
        Runtime::respond(200, ['permissions' => $permissions]);
    }
    if ($method === 'PUT' && preg_match('#^/teams/([0-9a-f-]{36})/permissions$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $teamId = $matches[1];
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?');
        $team->execute([$teamId, $principal['organizationId']]);
        if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $body = Runtime::jsonBody();
        $entries = $body['permissions'] ?? null;
        if (!is_array($entries) || count($entries) > 500) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'permissions must be an array.']);
        $allowedActions = ['view', 'create', 'edit', 'delete', 'admin'];
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Each permission must be an object.']);
            $resource = is_string($entry['resource'] ?? null) ? $entry['resource'] : '';
            $vaultId = $entry['vaultId'] ?? null;
            $actions = $entry['actions'] ?? null;
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,200}$/', $resource) || ($vaultId !== null && (!is_string($vaultId) || !preg_match('/^[0-9a-f-]{36}$/i', $vaultId))) || !is_array($actions) || count($actions) > count($allowedActions)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Each permission requires a valid resource, vaultId, and action array.']);
            $actions = array_values(array_unique($actions));
            if (count(array_filter($actions, static fn($action) => !is_string($action) || !in_array($action, $allowedActions, true))) > 0) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A permission contains an unsupported action.']);
            if ($actions !== []) $normalized[] = [$resource, $vaultId, $actions];
        }
        $vaultIds = array_values(array_unique(array_filter(array_map(static fn(array $entry) => $entry[1], $normalized))));
        if ($vaultIds !== []) {
            $placeholders = implode(',', array_fill(0, count($vaultIds), '?'));
            $vaults = $db->prepare("SELECT COUNT(*) FROM vaults WHERE organization_id = ? AND id IN ($placeholders)");
            $vaults->execute([$principal['organizationId'], ...$vaultIds]);
            if ((int)$vaults->fetchColumn() !== count($vaultIds)) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'One or more vaults were not found.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM team_permissions WHERE team_id = ? AND organization_id = ?')->execute([$teamId, $principal['organizationId']]);
            $insert = $db->prepare('INSERT INTO team_permissions (id, organization_id, team_id, resource, vault_id, actions, granted_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($normalized as [$resource, $vaultId, $actions]) $insert->execute([Runtime::uuid(), $principal['organizationId'], $teamId, $resource, $vaultId, json_encode($actions, JSON_THROW_ON_ERROR), $principal['userId']]);
            Runtime::emitEvent($db, $principal['organizationId'], 'team.permissions_updated', $teamId, ['teamId' => $teamId, 'updatedBy' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && preg_match('#^/teams/([0-9a-f-]{36})/reviewers$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $teamId = $matches[1];
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$teamId, $principal['organizationId']]);
        if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $query = $db->prepare('SELECT tr.id, tr.team_id, tr.reviewer_type, tr.user_id, tr.workflow_role_id, tr.added_at, u.email, u.display_name AS displayName FROM team_reviewers tr LEFT JOIN users u ON u.id = tr.user_id WHERE tr.team_id = ? AND tr.organization_id = ? ORDER BY tr.added_at');
        $query->execute([$teamId, $principal['organizationId']]);
        $reviewers = [];
        foreach ($query->fetchAll() as $reviewer) {
            $reviewers[] = ['id' => $reviewer['id'], 'team_id' => $reviewer['team_id'], 'reviewer_type' => $reviewer['reviewer_type'], 'user_id' => $reviewer['user_id'], 'workflow_role_id' => $reviewer['workflow_role_id'], 'added_at' => $reviewer['added_at'], 'user' => $reviewer['user_id'] ? ['id' => $reviewer['user_id'], 'email' => $reviewer['email'], 'full_name' => $reviewer['displayName'], 'avatar_url' => null] : null];
        }
        Runtime::respond(200, ['reviewers' => $reviewers]);
    }
    if ($method === 'POST' && preg_match('#^/teams/([0-9a-f-]{36})/reviewers$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $teamId = $matches[1]; $body = Runtime::jsonBody();
        $reviewerType = is_string($body['reviewerType'] ?? null) ? $body['reviewerType'] : '';
        $userId = is_string($body['userId'] ?? null) ? $body['userId'] : null;
        $workflowRoleId = is_string($body['workflowRoleId'] ?? null) ? $body['workflowRoleId'] : null;
        if (!in_array($reviewerType, ['user', 'workflow_role'], true) || ($reviewerType === 'user' && !preg_match('/^[0-9a-f-]{36}$/i', (string)$userId)) || ($reviewerType === 'workflow_role' && !preg_match('/^[0-9a-f-]{36}$/i', (string)$workflowRoleId))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A reviewer type and matching target are required.']);
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$teamId, $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        if ($reviewerType === 'user') { $target = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?'); $target->execute([$principal['organizationId'], $userId]); if (!$target->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']); }
        else { $target = $db->prepare('SELECT 1 FROM workflow_roles WHERE org_id = ? AND id = ?'); $target->execute([$principal['organizationId'], $workflowRoleId]); if (!$target->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Workflow role not found.']); }
        $duplicate = $db->prepare('SELECT 1 FROM team_reviewers WHERE organization_id = ? AND team_id = ? AND reviewer_type = ? AND user_id <=> ? AND workflow_role_id <=> ?'); $duplicate->execute([$principal['organizationId'], $teamId, $reviewerType, $userId, $workflowRoleId]); if ($duplicate->fetch()) Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'Reviewer rule already exists.']);
        $reviewerId = Runtime::uuid(); $db->prepare('INSERT INTO team_reviewers (id, organization_id, team_id, reviewer_type, user_id, workflow_role_id, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$reviewerId, $principal['organizationId'], $teamId, $reviewerType, $userId, $workflowRoleId, $principal['userId']]);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.reviewer_added', $teamId, ['teamId' => $teamId, 'reviewerId' => $reviewerId, 'addedBy' => $principal['userId']]);
        Runtime::respond(201, ['id' => $reviewerId]);
    }
    if ($method === 'DELETE' && preg_match('#^/team-reviewers/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('DELETE FROM team_reviewers WHERE id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Reviewer rule not found.']);
        Runtime::respond(204);
    }
    // Team management is a first-class MDB API. Keep its response names
    // aligned with the MDB client contract so the desktop client never needs a
    // Supabase fallback when MariaDB is selected.
    if ($method === 'GET' && $path === '/teams') {
        $query = $db->prepare(
            'SELECT t.id, t.name, t.color, t.icon, t.created_at AS createdAt, t.module_defaults,
                    COUNT(DISTINCT tm.user_id) AS memberCount,
                    COUNT(DISTINCT tva.vault_id) AS vaultCount
             FROM teams t
             LEFT JOIN team_members tm ON tm.team_id = t.id
             LEFT JOIN team_vault_access tva ON tva.team_id = t.id
             WHERE t.organization_id = ?
             GROUP BY t.id, t.name, t.color, t.icon, t.created_at
             ORDER BY t.name'
        );
        $query->execute([$principal['organizationId']]);
        $teams = $query->fetchAll();
        foreach ($teams as &$team) { $team['memberCount'] = (int)$team['memberCount']; $team['vaultCount'] = (int)$team['vaultCount']; $team['module_defaults'] = $team['module_defaults'] === null ? null : json_decode($team['module_defaults'], true); }
        unset($team);
        Runtime::respond(200, ['teams' => $teams]);
    }
    if ($method === 'GET' && preg_match('#^/teams/([0-9a-f-]{36})/module-defaults$#i', $path, $matches)) {
        $team = $db->prepare('SELECT module_defaults FROM teams WHERE id = ? AND organization_id = ?');
        $team->execute([$matches[1], $principal['organizationId']]); $row = $team->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        Runtime::respond(200, ['defaults' => $row['module_defaults'] === null ? null : json_decode($row['module_defaults'], true)]);
    }
    if ($method === 'PUT' && preg_match('#^/teams/([0-9a-f-]{36})/module-defaults$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $defaults = $body['defaults'] ?? null;
        if (!is_array($defaults)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid module defaults object is required.']);
        try { $encodedDefaults = json_encode($defaults, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid module defaults object is required.']); }
        if (strlen($encodedDefaults) > 500000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid module defaults object is required.']);
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$matches[1], $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $db->prepare('UPDATE teams SET module_defaults = ?, module_defaults_forced_at = NULL WHERE id = ? AND organization_id = ?')->execute([$encodedDefaults, $matches[1], $principal['organizationId']]);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.module_defaults_updated', $matches[1], ['teamId' => $matches[1], 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/teams/([0-9a-f-]{36})/module-defaults$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('UPDATE teams SET module_defaults = NULL, module_defaults_forced_at = NULL WHERE id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]);
        if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.module_defaults_cleared', $matches[1], ['teamId' => $matches[1], 'clearedBy' => $principal['userId']]);
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'POST' && $path === '/teams') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $color = is_string($body['color'] ?? null) ? trim($body['color']) : '';
        $icon = is_string($body['icon'] ?? null) ? trim($body['icon']) : '';
        if ($name === '' || strlen($name) > 200 || $color === '' || strlen($color) > 32 || $icon === '' || strlen($icon) > 64) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A name, color, and icon are required.']);
        $teamId = Runtime::uuid();
        try {
            $db->prepare('INSERT INTO teams (id, organization_id, name, color, icon) VALUES (?, ?, ?, ?, ?)')->execute([$teamId, $principal['organizationId'], $name, $color, $icon]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A team with this name already exists.']);
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'team.created', $teamId, ['teamId' => $teamId, 'createdBy' => $principal['userId']]);
        Runtime::respond(201, ['id' => $teamId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'createdAt' => gmdate('c'), 'memberCount' => 0, 'vaultCount' => 0]);
    }
    if ($method === 'PATCH' && preg_match('#^/teams/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $teamId = $matches[1];
        $teamQuery = $db->prepare('SELECT id, name, color, icon, created_at AS createdAt FROM teams WHERE id = ? AND organization_id = ?'); $teamQuery->execute([$teamId, $principal['organizationId']]); $team = $teamQuery->fetch();
        if (!$team) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $name = array_key_exists('name', $body) && is_string($body['name']) ? trim($body['name']) : $team['name'];
        $color = array_key_exists('color', $body) && is_string($body['color']) ? trim($body['color']) : $team['color'];
        $icon = array_key_exists('icon', $body) && is_string($body['icon']) ? trim($body['icon']) : $team['icon'];
        if ($name === '' || strlen($name) > 200 || $color === '' || strlen($color) > 32 || $icon === '' || strlen($icon) > 64) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A name, color, and icon are required.']);
        try { $db->prepare('UPDATE teams SET name = ?, color = ?, icon = ? WHERE id = ? AND organization_id = ?')->execute([$name, $color, $icon, $teamId, $principal['organizationId']]); }
        catch (PDOException $error) { if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A team with this name already exists.']); throw $error; }
        Runtime::emitEvent($db, $principal['organizationId'], 'team.updated', $teamId, ['teamId' => $teamId, 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['id' => $teamId, 'name' => $name, 'color' => $color, 'icon' => $icon, 'createdAt' => $team['createdAt']]);
    }
    if ($method === 'DELETE' && preg_match('#^/teams/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('DELETE FROM teams WHERE id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]);
        if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.deleted', $matches[1], ['teamId' => $matches[1], 'deletedBy' => $principal['userId']]);
        Runtime::respond(204);
    }
    if ($method === 'GET' && preg_match('#^/users/([0-9a-f-]{36})/teams$#i', $path, $matches)) {
        $userId = $matches[1];
        if ($userId !== $principal['userId'] && !in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?'); $member->execute([$principal['organizationId'], $userId]);
        if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $query = $db->prepare('SELECT t.id, t.name, t.color, t.icon FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ? AND t.organization_id = ? ORDER BY t.name'); $query->execute([$userId, $principal['organizationId']]);
        Runtime::respond(200, ['teams' => $query->fetchAll()]);
    }
    if ($method === 'GET' && preg_match('#^/teams/([0-9a-f-]{36})/members$#i', $path, $matches)) {
        $query = $db->prepare('SELECT tm.user_id AS userId, tm.created_at AS addedAt, u.email, u.display_name AS displayName, m.role FROM team_members tm JOIN teams t ON t.id = tm.team_id JOIN users u ON u.id = tm.user_id JOIN organization_memberships m ON m.user_id = u.id AND m.organization_id = t.organization_id WHERE tm.team_id = ? AND t.organization_id = ? ORDER BY u.display_name, u.email'); $query->execute([$matches[1], $principal['organizationId']]);
        Runtime::respond(200, ['members' => $query->fetchAll()]);
    }
    if ($method === 'POST' && preg_match('#^/teams/([0-9a-f-]{36})/members$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $userId = is_string($body['userId'] ?? null) ? $body['userId'] : '';
        if (!preg_match('/^[0-9a-f-]{36}$/i', $userId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid userId is required.']);
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$matches[1], $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $member = $db->prepare('SELECT 1 FROM organization_memberships WHERE organization_id = ? AND user_id = ?'); $member->execute([$principal['organizationId'], $userId]); if (!$member->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        $db->prepare('INSERT IGNORE INTO team_members (team_id, user_id) VALUES (?, ?)')->execute([$matches[1], $userId]);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.member_added', $matches[1], ['teamId' => $matches[1], 'userId' => $userId, 'addedBy' => $principal['userId']]);
        Runtime::respond(201, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/teams/([0-9a-f-]{36})/members/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('DELETE tm FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.team_id = ? AND tm.user_id = ? AND t.organization_id = ?'); $query->execute([$matches[1], $matches[2], $principal['organizationId']]);
        if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team membership not found.']);
        Runtime::emitEvent($db, $principal['organizationId'], 'team.member_removed', $matches[1], ['teamId' => $matches[1], 'userId' => $matches[2], 'removedBy' => $principal['userId']]);
        Runtime::respond(204);
    }
    if ($method === 'GET' && preg_match('#^/teams/([0-9a-f-]{36})/vault-access$#i', $path, $matches)) {
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$matches[1], $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        $query = $db->prepare('SELECT vault_id FROM team_vault_access WHERE team_id = ? ORDER BY vault_id'); $query->execute([$matches[1]]);
        Runtime::respond(200, ['vaultIds' => array_column($query->fetchAll(), 'vault_id')]);
    }
    if ($method === 'PUT' && preg_match('#^/teams/([0-9a-f-]{36})/vault-access$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $vaultIds = $body['vaultIds'] ?? null;
        if (!is_array($vaultIds) || count($vaultIds) > 500 || count(array_filter($vaultIds, static fn($id) => !is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id))) > 0) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'vaultIds must be an array of valid IDs.']);
        $vaultIds = array_values(array_unique($vaultIds));
        $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$matches[1], $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        if ($vaultIds !== []) { $placeholders = implode(',', array_fill(0, count($vaultIds), '?')); $vaults = $db->prepare("SELECT COUNT(*) FROM vaults WHERE organization_id = ? AND id IN ($placeholders)"); $vaults->execute([$principal['organizationId'], ...$vaultIds]); if ((int)$vaults->fetchColumn() !== count($vaultIds)) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'One or more vaults were not found.']); }
        $db->beginTransaction();
        try { $db->prepare('DELETE FROM team_vault_access WHERE team_id = ?')->execute([$matches[1]]); $insert = $db->prepare('INSERT INTO team_vault_access (team_id, vault_id, granted_by) VALUES (?, ?, ?)'); foreach ($vaultIds as $vaultId) $insert->execute([$matches[1], $vaultId, $principal['userId']]); Runtime::emitEvent($db, $principal['organizationId'], 'team.vault_access_updated', $matches[1], ['teamId' => $matches[1], 'updatedBy' => $principal['userId']]); $db->commit(); }
        catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && $path === '/account') {
        if ($principal['role'] === 'owner') { $owners = $db->prepare("SELECT 1 FROM organization_memberships WHERE organization_id = ? AND role = 'owner' AND user_id <> ? LIMIT 1"); $owners->execute([$principal['organizationId'], $principal['userId']]); if (!$owners->fetch()) Runtime::respond(409, ['error' => 'LAST_OWNER', 'message' => 'Transfer organization ownership before deleting the last owner account.']); }
        $db->beginTransaction(); try { $db->prepare('DELETE FROM checkouts WHERE user_id = ?')->execute([$principal['userId']]); $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$principal['userId']]); $db->prepare('DELETE FROM organization_memberships WHERE user_id = ? AND organization_id = ?')->execute([$principal['userId'], $principal['organizationId']]); $db->prepare('UPDATE users SET disabled_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$principal['userId']]); Runtime::emitEvent($db, $principal['organizationId'], 'account.deleted', $principal['userId'], ['userId' => $principal['userId']]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; } Runtime::respond(204);
    }
    if ($method === 'POST' && $path === '/recovery-codes') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']); $body = Runtime::jsonBody(); $days = is_int($body['expiresInDays'] ?? null) ? $body['expiresInDays'] : 90; if ($days < 1 || $days > 3650) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'expiresInDays must be between 1 and 3650.']); $description = is_string($body['description'] ?? null) ? substr($body['description'], 0, 1024) : null;
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $bytes = random_bytes(16); $parts = []; for ($part = 0; $part < 4; $part++) { $segment = ''; for ($offset = 0; $offset < 4; $offset++) $segment .= $chars[ord($bytes[$part * 4 + $offset]) % strlen($chars)]; $parts[] = $segment; } $code = implode('-', $parts); $id = Runtime::uuid(); $db->prepare('INSERT INTO admin_recovery_codes (id, organization_id, code_hash, description, created_by, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL ? DAY))')->execute([$id, $principal['organizationId'], hash('sha256', str_replace('-', '', $code)), $description, $principal['userId'], $days]); Runtime::respond(201, ['code' => $code, 'codeId' => $id]);
    }
    if ($method === 'GET' && $path === '/recovery-codes') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']); $query = $db->prepare('SELECT id, organization_id AS org_id, description, created_by, created_at, expires_at, is_used, used_by, used_at, is_revoked, revoked_by, revoked_at, revoke_reason FROM admin_recovery_codes WHERE organization_id = ? ORDER BY created_at DESC'); $query->execute([$principal['organizationId']]); Runtime::respond(200, ['codes' => $query->fetchAll()]);
    }
    if ($method === 'PATCH' && preg_match('#^/recovery-codes/([0-9a-f-]{36})/revoke$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']); $body = Runtime::jsonBody(); $reason = is_string($body['reason'] ?? null) ? substr($body['reason'], 0, 1024) : null;
        $query = $db->prepare('UPDATE admin_recovery_codes SET is_revoked = TRUE, revoked_by = ?, revoked_at = UTC_TIMESTAMP(3), revoke_reason = ? WHERE id = ? AND organization_id = ? AND is_used = FALSE'); $query->execute([$principal['userId'], $reason, $matches[1], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Active recovery code not found.']); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/recovery-codes/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']); $query = $db->prepare('DELETE FROM admin_recovery_codes WHERE id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Recovery code not found.']); Runtime::respond(204);
    }
    if ($method === 'POST' && $path === '/recovery-codes/use') {
        $body = Runtime::jsonBody(); if (!is_string($body['code'] ?? null) || trim($body['code']) === '') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'code is required.']); $db->beginTransaction(); try { $query = $db->prepare('SELECT id FROM admin_recovery_codes WHERE organization_id = ? AND code_hash = ? AND is_used = FALSE AND is_revoked = FALSE AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $query->execute([$principal['organizationId'], hash('sha256', strtoupper(str_replace('-', '', $body['code'])))]); $record = $query->fetch(); if (!$record) { $db->rollBack(); Runtime::respond(200, ['success' => false, 'error' => 'Recovery code is invalid, expired, used, or revoked.']); } $db->prepare('UPDATE admin_recovery_codes SET is_used = TRUE, used_by = ?, used_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$principal['userId'], $record['id']]); $db->prepare("UPDATE organization_memberships SET role = 'admin' WHERE organization_id = ? AND user_id = ?")->execute([$principal['organizationId'], $principal['userId']]); $db->commit(); } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; } Runtime::respond(200, ['success' => true, 'message' => 'Administrator access restored.']);
    }
    if ($method === 'POST' && $path === '/device-sessions') {
        $body = Runtime::jsonBody(); $machineId = is_string($body['machineId'] ?? null) ? trim($body['machineId']) : '';
        if ($machineId === '' || strlen($machineId) > 512) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'machineId is required.']);
        $machineName = is_string($body['machineName'] ?? null) ? substr($body['machineName'], 0, 512) : null; $osVersion = is_string($body['osVersion'] ?? null) ? substr($body['osVersion'], 0, 512) : null; $appVersion = is_string($body['appVersion'] ?? null) ? substr($body['appVersion'], 0, 128) : null; $platform = is_string($body['platform'] ?? null) ? substr($body['platform'], 0, 128) : null;
        $db->prepare('INSERT INTO device_sessions (id, user_id, organization_id, machine_id, machine_name, os_version, app_version, platform, last_active, last_seen, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), TRUE) ON DUPLICATE KEY UPDATE organization_id = VALUES(organization_id), machine_name = VALUES(machine_name), os_version = VALUES(os_version), app_version = VALUES(app_version), platform = VALUES(platform), last_active = UTC_TIMESTAMP(3), last_seen = UTC_TIMESTAMP(3), is_active = TRUE')->execute([Runtime::uuid(), $principal['userId'], $principal['organizationId'], $machineId, $machineName, $osVersion, $appVersion, $platform]);
        $query = $db->prepare('SELECT id, user_id, organization_id AS org_id, machine_id, machine_name, os_version, app_version, platform, last_active, last_seen, is_active, created_at FROM device_sessions WHERE user_id = ? AND machine_id = ?'); $query->execute([$principal['userId'], $machineId]); Runtime::respond(201, ['session' => $query->fetch()]);
    }
    if ($method === 'PATCH' && $path === '/device-sessions/current/heartbeat') {
        $body = Runtime::jsonBody(); $machineId = is_string($body['machineId'] ?? null) ? trim($body['machineId']) : '';
        if ($machineId === '' || strlen($machineId) > 512) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'machineId is required.']);
        $query = $db->prepare('UPDATE device_sessions SET last_active = UTC_TIMESTAMP(3), last_seen = UTC_TIMESTAMP(3) WHERE user_id = ? AND organization_id = ? AND machine_id = ? AND is_active = TRUE'); $query->execute([$principal['userId'], $principal['organizationId'], $machineId]); Runtime::respond(200, ['active' => $query->rowCount() > 0]);
    }
    if ($method === 'PATCH' && $path === '/device-sessions/current/end') {
        $body = Runtime::jsonBody(); $machineId = is_string($body['machineId'] ?? null) ? trim($body['machineId']) : '';
        if ($machineId === '' || strlen($machineId) > 512) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'machineId is required.']);
        $db->prepare('UPDATE device_sessions SET is_active = FALSE WHERE user_id = ? AND organization_id = ? AND machine_id = ?')->execute([$principal['userId'], $principal['organizationId'], $machineId]); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'PATCH' && preg_match('#^/device-sessions/([0-9a-f-]{36})/end$#i', $path, $matches)) {
        $query = $db->prepare('UPDATE device_sessions SET is_active = FALSE WHERE id = ? AND user_id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['userId'], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Device session not found.']); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && $path === '/device-sessions/mine') {
        $query = $db->prepare('SELECT id, user_id, organization_id AS org_id, machine_id, machine_name, os_version, app_version, platform, last_active, last_seen, is_active, created_at FROM device_sessions WHERE user_id = ? AND organization_id = ? AND is_active = TRUE AND last_seen >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE) ORDER BY last_seen DESC'); $query->execute([$principal['userId'], $principal['organizationId']]); Runtime::respond(200, ['sessions' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/organizations/current/online-users') {
        $query = $db->prepare('SELECT s.user_id, u.email, u.display_name AS full_name, NULL AS avatar_url, NULL AS custom_avatar_url, m.role, COALESCE(s.machine_name, s.machine_id) AS machine_name, s.platform, s.last_seen FROM device_sessions s JOIN users u ON u.id = s.user_id JOIN organization_memberships m ON m.user_id = s.user_id AND m.organization_id = s.organization_id WHERE s.organization_id = ? AND s.is_active = TRUE AND s.last_seen >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE) ORDER BY s.last_seen DESC'); $query->execute([$principal['organizationId']]); Runtime::respond(200, ['users' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/organizations/current') {
        $query = $db->prepare('SELECT o.id, o.name, o.slug, o.created_at AS createdAt, s.default_new_user_team_id AS defaultNewUserTeamId, s.document_manager_license_key AS documentManagerLicenseKey, s.module_defaults_forced_at AS module_defaults_forced_at FROM organizations o LEFT JOIN organization_settings s ON s.organization_id = o.id WHERE o.id = ?');
        $query->execute([$principal['organizationId']]); $organization = $query->fetch();
        if (!$organization) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Organization not found.']);
        Runtime::respond(200, ['organization' => $organization]);
    }
    if ($method === 'PATCH' && $path === '/organizations/current/document-manager-license') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $licenseKey = $body['documentManagerLicenseKey'] ?? null;
        if ($licenseKey !== null && (!is_string($licenseKey) || strlen($licenseKey) > 8192)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'documentManagerLicenseKey must be a string up to 8192 characters or null.']);
        $db->prepare('INSERT INTO organization_settings (organization_id, document_manager_license_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE document_manager_license_key = VALUES(document_manager_license_key)')->execute([$principal['organizationId'], $licenseKey]);
        Runtime::emitEvent($db, $principal['organizationId'], 'organization.document_manager_license_updated', $principal['organizationId'], ['configured' => $licenseKey !== null && $licenseKey !== '', 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['documentManagerLicenseKey' => $licenseKey]);
    }
    if ($method === 'PUT' && $path === '/organizations/current/settings') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $teamId = $body['defaultNewUserTeamId'] ?? null;
        if ($teamId !== null && (!is_string($teamId) || !preg_match('/^[0-9a-f-]{36}$/i', $teamId))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'defaultNewUserTeamId must be a team ID or null.']);
        if ($teamId !== null) { $team = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?'); $team->execute([$teamId, $principal['organizationId']]); if (!$team->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']); }
        $db->prepare('INSERT INTO organization_settings (organization_id, default_new_user_team_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE default_new_user_team_id = VALUES(default_new_user_team_id)')->execute([$principal['organizationId'], $teamId]);
        Runtime::emitEvent($db, $principal['organizationId'], 'organization.default_team_updated', $principal['organizationId'], ['defaultNewUserTeamId' => $teamId, 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['defaultNewUserTeamId' => $teamId]);
    }
    if ($method === 'GET' && preg_match('#^/organizations/current/settings/(serialization|export|rfq|auth-providers|modules)$#', $path, $matches)) {
        $columns = [
            'serialization' => 'serialization_settings',
            'export' => 'export_settings',
            'rfq' => 'rfq_settings',
            'auth-providers' => 'auth_provider_settings',
            'modules' => 'module_defaults',
        ];
        $section = $matches[1];
        $column = $columns[$section];
        $query = $db->prepare("SELECT {$column}, serialization_counter, module_defaults_forced_at FROM organization_settings WHERE organization_id = ?");
        $query->execute([$principal['organizationId']]);
        $row = $query->fetch() ?: [];
        $value = decodeOrganizationSetting($row[$column] ?? null);
        if ($section === 'serialization') $value['current_counter'] = (int)($row['serialization_counter'] ?? 0);
        if ($section === 'modules') $value['_forcedAt'] = $row['module_defaults_forced_at'] ?? null;
        Runtime::respond(200, ['value' => $value]);
    }
    if ($method === 'PUT' && preg_match('#^/organizations/current/settings/(serialization|export|rfq|auth-providers|modules)$#', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $columns = [
            'serialization' => 'serialization_settings',
            'export' => 'export_settings',
            'rfq' => 'rfq_settings',
            'auth-providers' => 'auth_provider_settings',
            'modules' => 'module_defaults',
        ];
        $section = $matches[1];
        $column = $columns[$section];
        $body = Runtime::jsonBody();
        $value = $body['value'] ?? null;
        if (!is_array($value)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'value must be a JSON object.']);
        $force = ($body['force'] ?? false) === true;
        if ($section === 'modules' && array_key_exists('_forcedAt', $value)) unset($value['_forcedAt']);
        $counter = null;
        if ($section === 'serialization') {
            if (array_key_exists('current_counter', $value)) {
                if (!is_int($value['current_counter']) || $value['current_counter'] < 0) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'current_counter must be a non-negative integer.']);
                if (($body['replaceCounter'] ?? false) === true) $counter = $value['current_counter'];
                unset($value['current_counter']);
            }
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'value must contain valid JSON data.']);
        }
        if (strlen($encoded) > 65535) Runtime::respond(413, ['error' => 'PAYLOAD_TOO_LARGE', 'message' => 'Settings may not exceed 65535 bytes.']);
        if ($section === 'serialization' && $counter !== null) {
            $db->prepare("INSERT INTO organization_settings (organization_id, {$column}, serialization_counter) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE {$column} = VALUES({$column}), serialization_counter = VALUES(serialization_counter)")
                ->execute([$principal['organizationId'], $encoded, $counter]);
        } else {
            if ($section === 'modules' && $force) {
                $db->prepare("INSERT INTO organization_settings (organization_id, {$column}, module_defaults_forced_at) VALUES (?, ?, UTC_TIMESTAMP(3)) ON DUPLICATE KEY UPDATE {$column} = VALUES({$column}), module_defaults_forced_at = UTC_TIMESTAMP(3)")
                    ->execute([$principal['organizationId'], $encoded]);
            } else {
                $db->prepare("INSERT INTO organization_settings (organization_id, {$column}) VALUES (?, ?) ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})")
                    ->execute([$principal['organizationId'], $encoded]);
            }
        }
        if ($section === 'serialization') {
            $query = $db->prepare('SELECT serialization_counter FROM organization_settings WHERE organization_id = ?');
            $query->execute([$principal['organizationId']]);
            $value['current_counter'] = (int)$query->fetchColumn();
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'organization.settings_updated', $principal['organizationId'], ['section' => $section, 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['value' => $value]);
    }
    if (($method === 'GET' && $path === '/organizations/current/serialization/preview') || ($method === 'POST' && $path === '/organizations/current/serialization/next')) {
        $mutating = $method === 'POST';
        if ($mutating) $db->beginTransaction();
        try {
            if ($mutating) {
                $db->prepare('INSERT IGNORE INTO organization_settings (organization_id) VALUES (?)')->execute([$principal['organizationId']]);
            }
            $suffix = $mutating ? ' FOR UPDATE' : '';
            $query = $db->prepare('SELECT serialization_settings, serialization_counter FROM organization_settings WHERE organization_id = ?' . $suffix);
            $query->execute([$principal['organizationId']]);
            $row = $query->fetch() ?: [];
            $settings = decodeOrganizationSetting($row['serialization_settings'] ?? null);
            if (($settings['enabled'] ?? true) !== true) {
                if ($mutating) $db->commit();
                Runtime::respond(200, ['serialNumber' => null]);
            }
            $counter = nextOrganizationSerialCounter($settings, (int)($row['serialization_counter'] ?? 0));
            if ($mutating) {
                $db->prepare('UPDATE organization_settings SET serialization_counter = ? WHERE organization_id = ?')->execute([$counter, $principal['organizationId']]);
                Runtime::emitEvent($db, $principal['organizationId'], 'organization.serial_number_allocated', $principal['organizationId'], ['counter' => $counter, 'allocatedBy' => $principal['userId']]);
                $db->commit();
            }
            Runtime::respond(200, ['serialNumber' => formatOrganizationSerial($settings, $counter)]);
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    if ($method === 'GET' && $path === '/organizations/current/serialization/exists') {
        $serial = is_string($_GET['serial'] ?? null) ? trim($_GET['serial']) : '';
        if ($serial === '' || strlen($serial) > 512) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid serial number is required.']);
        // The unique database key reserves part numbers until permanent deletion,
        // including while an item is in trash. Report the same availability rule.
        $query = $db->prepare('SELECT 1 FROM files WHERE organization_id = ? AND part_number = ? LIMIT 1');
        $query->execute([$principal['organizationId'], $serial]);
        Runtime::respond(200, ['exists' => (bool)$query->fetchColumn()]);
    }
    if ($method === 'GET' && $path === '/organizations/current/serialization/files') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('SELECT part_number AS partNumber, canonical_path AS filePath FROM files WHERE organization_id = ? AND part_number IS NOT NULL AND deleted_at IS NULL ORDER BY part_number');
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['files' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/module-access') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $query = $db->prepare('SELECT module_id, team_id, user_id FROM module_access WHERE organization_id = ? ORDER BY module_id, granted_at');
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['access' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/module-access/denied') {
        if (in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(200, ['moduleIds' => []]);
        $query = $db->prepare('SELECT DISTINCT restricted.module_id FROM module_access restricted WHERE restricted.organization_id = ? AND NOT EXISTS (SELECT 1 FROM module_access allowed LEFT JOIN team_members tm ON tm.team_id = allowed.team_id AND tm.user_id = ? WHERE allowed.organization_id = restricted.organization_id AND allowed.module_id = restricted.module_id AND (allowed.user_id = ? OR tm.user_id IS NOT NULL)) ORDER BY restricted.module_id');
        $query->execute([$principal['organizationId'], $principal['userId'], $principal['userId']]);
        Runtime::respond(200, ['moduleIds' => array_values(array_map(static fn(array $row): string => $row['module_id'], $query->fetchAll()))]);
    }
    if ($method === 'PUT' && preg_match('#^/module-access/([a-z0-9-]{1,128})$#', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $teamIds = $body['teamIds'] ?? [];
        $userIds = $body['userIds'] ?? [];
        if (!is_array($teamIds) || !is_array($userIds) || count($teamIds) > 500 || count($userIds) > 500) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'teamIds and userIds must be arrays with at most 500 entries.']);
        $moduleId = $matches[1];
        $teamIds = array_values(array_unique($teamIds));
        $userIds = array_values(array_unique($userIds));
        $teamLookup = $db->prepare('SELECT id FROM teams WHERE id = ? AND organization_id = ?');
        foreach ($teamIds as $teamId) {
            if (!is_string($teamId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid team ID.']);
            $teamLookup->execute([$teamId, $principal['organizationId']]);
            if (!$teamLookup->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Team not found.']);
        }
        $userLookup = $db->prepare('SELECT u.id FROM users u JOIN organization_memberships m ON m.user_id = u.id WHERE u.id = ? AND m.organization_id = ?');
        foreach ($userIds as $userId) {
            if (!is_string($userId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid user ID.']);
            $userLookup->execute([$userId, $principal['organizationId']]);
            if (!$userLookup->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'User not found.']);
        }
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM module_access WHERE organization_id = ? AND module_id = ?')->execute([$principal['organizationId'], $moduleId]);
            $insert = $db->prepare('INSERT INTO module_access (id, organization_id, module_id, team_id, user_id, granted_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($teamIds as $teamId) {
                $insert->execute([Runtime::uuid(), $principal['organizationId'], $moduleId, $teamId, null, $principal['userId']]);
            }
            foreach ($userIds as $userId) {
                $insert->execute([Runtime::uuid(), $principal['organizationId'], $moduleId, null, $userId, $principal['userId']]);
            }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::emitEvent($db, $principal['organizationId'], 'organization.module_access_updated', $principal['organizationId'], ['moduleId' => $moduleId, 'updatedBy' => $principal['userId']]);
        Runtime::respond(200, ['success' => true, 'restricted' => count($teamIds) + count($userIds) > 0]);
    }
    if ($method === 'GET' && $path === '/item-designations') {
        $count = $db->prepare('SELECT COUNT(*) FROM item_designations WHERE organization_id = ?');
        $count->execute([$principal['organizationId']]);
        if ((int)$count->fetchColumn() === 0) {
            $seed = $db->prepare('INSERT IGNORE INTO item_designations (id, organization_id, name, sort_order) VALUES (?, ?, ?, ?)');
            foreach ([['Part', 0], ['Assembly', 1], ['Packed Assembly', 2]] as [$name, $sortOrder]) {
                $seed->execute([Runtime::uuid(), $principal['organizationId'], $name, $sortOrder]);
            }
        }
        $query = $db->prepare('SELECT id, name, sort_order FROM item_designations WHERE organization_id = ? ORDER BY sort_order, name');
        $query->execute([$principal['organizationId']]);
        Runtime::respond(200, ['designations' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/item-designations') {
        if (!canManageItemDesignations($db, $principal)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Item designation edit permission required.']);
        $body = Runtime::jsonBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $sortOrder = $body['sortOrder'] ?? null;
        if ($name === '' || strlen($name) > 120 || str_contains($name, "\0") || ($sortOrder !== null && (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 2147483647))) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'name and an optional non-negative sortOrder are required.']);
        }
        if ($sortOrder === null) {
            $next = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM item_designations WHERE organization_id = ?');
            $next->execute([$principal['organizationId']]);
            $sortOrder = (int)$next->fetchColumn();
        }
        $id = Runtime::uuid();
        try {
            $db->prepare('INSERT INTO item_designations (id, organization_id, name, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$id, $principal['organizationId'], $name, $sortOrder]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An item designation with this name already exists.']);
            throw $error;
        }
        $query = $db->prepare('SELECT id, name, sort_order FROM item_designations WHERE id = ? AND organization_id = ?');
        $query->execute([$id, $principal['organizationId']]);
        Runtime::respond(201, ['designation' => $query->fetch()]);
    }
    if ($method === 'PATCH' && preg_match('#^/item-designations/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!canManageItemDesignations($db, $principal)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Item designation edit permission required.']);
        $body = Runtime::jsonBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $sortOrder = $body['sortOrder'] ?? null;
        if ($name === '' || strlen($name) > 120 || str_contains($name, "\0") || ($sortOrder !== null && (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 2147483647))) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'name and an optional non-negative sortOrder are required.']);
        }
        $exists = $db->prepare('SELECT 1 FROM item_designations WHERE id = ? AND organization_id = ?');
        $exists->execute([$matches[1], $principal['organizationId']]);
        if (!$exists->fetchColumn()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Item designation not found.']);
        try {
            if ($sortOrder === null) {
                $db->prepare('UPDATE item_designations SET name = ? WHERE id = ? AND organization_id = ?')
                    ->execute([$name, $matches[1], $principal['organizationId']]);
            } else {
                $db->prepare('UPDATE item_designations SET name = ?, sort_order = ? WHERE id = ? AND organization_id = ?')
                    ->execute([$name, $sortOrder, $matches[1], $principal['organizationId']]);
            }
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'An item designation with this name already exists.']);
            throw $error;
        }
        $query = $db->prepare('SELECT id, name, sort_order FROM item_designations WHERE id = ? AND organization_id = ?');
        $query->execute([$matches[1], $principal['organizationId']]);
        Runtime::respond(200, ['designation' => $query->fetch()]);
    }
    if ($method === 'DELETE' && preg_match('#^/item-designations/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!canManageItemDesignations($db, $principal)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Item designation edit permission required.']);
        try {
            $query = $db->prepare('DELETE FROM item_designations WHERE id = ? AND organization_id = ?');
            $query->execute([$matches[1], $principal['organizationId']]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'DESIGNATION_IN_USE', 'message' => 'The item designation is still assigned to one or more items.']);
            throw $error;
        }
        if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Item designation not found.']);
        Runtime::respond(204);
    }
    if ($method === 'GET' && preg_match('#^/vaults/([0-9a-f-]{36})/item-designations$#i', $path, $matches)) {
        $vaultId = $matches[1];
        Runtime::requireVault($db, $principal, $vaultId);
        $query = $db->prepare('SELECT part_number, designation_id FROM item_designation_assignments WHERE organization_id = ? AND vault_id = ? ORDER BY part_number');
        $query->execute([$principal['organizationId'], $vaultId]);
        Runtime::respond(200, ['assignments' => $query->fetchAll()]);
    }
    if ($method === 'PUT' && preg_match('#^/vaults/([0-9a-f-]{36})/item-designations/([^/]+)$#i', $path, $matches)) {
        $vaultId = $matches[1];
        Runtime::requireVault($db, $principal, $vaultId);
        if (!canManageItemDesignations($db, $principal, $vaultId)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Item designation edit permission required.']);
        $partNumber = rawurldecode($matches[2]);
        $body = Runtime::jsonBody();
        $designationId = $body['designationId'] ?? null;
        if ($partNumber === '' || strlen($partNumber) > 512 || str_contains($partNumber, "\0") || ($designationId !== null && (!is_string($designationId) || !preg_match('/^[0-9a-f-]{36}$/i', $designationId)))) {
            Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid part number and designationId are required.']);
        }
        if ($designationId === null) {
            $db->prepare('DELETE FROM item_designation_assignments WHERE organization_id = ? AND vault_id = ? AND part_number = ?')
                ->execute([$principal['organizationId'], $vaultId, $partNumber]);
            Runtime::respond(200, ['success' => true]);
        }
        $designation = $db->prepare('SELECT id FROM item_designations WHERE id = ? AND organization_id = ?');
        $designation->execute([$designationId, $principal['organizationId']]);
        if (!$designation->fetchColumn()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Item designation not found.']);
        $db->prepare('INSERT INTO item_designation_assignments (organization_id, vault_id, part_number, designation_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE designation_id = VALUES(designation_id), updated_at = CURRENT_TIMESTAMP(3)')
            ->execute([$principal['organizationId'], $vaultId, $partNumber, $designationId]);
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && $path === '/metadata-columns') {
        $query = $db->prepare('SELECT id, organization_id AS org_id, name, label, data_type, select_options, width, visible, sortable, required, default_value, sort_order, created_by, updated_by, created_at, updated_at FROM file_metadata_columns WHERE organization_id = ? ORDER BY sort_order, name');
        $query->execute([$principal['organizationId']]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['select_options'] = decodeOrganizationSetting($row['select_options']);
            $row['width'] = (int)$row['width'];
            $row['sort_order'] = (int)$row['sort_order'];
            foreach (['visible', 'sortable', 'required'] as $flag) $row[$flag] = (bool)$row[$flag];
        }
        unset($row);
        Runtime::respond(200, ['columns' => $rows]);
    }
    if ($method === 'POST' && $path === '/metadata-columns') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $name = is_string($body['name'] ?? null) ? strtolower(trim($body['name'])) : '';
        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        $dataType = is_string($body['data_type'] ?? null) ? $body['data_type'] : 'text';
        $options = $body['select_options'] ?? [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,127}$/', $name) || $label === '' || strlen($label) > 256 || !in_array($dataType, ['text', 'number', 'date', 'boolean', 'select'], true) || !is_array($options)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid metadata column.']);
        $id = Runtime::uuid();
        try {
            $db->prepare('INSERT INTO file_metadata_columns (id, organization_id, name, label, data_type, select_options, width, visible, sortable, required, default_value, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $principal['organizationId'], $name, $label, $dataType, json_encode(array_values($options), JSON_THROW_ON_ERROR), max(40, min(1000, (int)($body['width'] ?? 120))), (int)(bool)($body['visible'] ?? true), (int)(bool)($body['sortable'] ?? true), (int)(bool)($body['required'] ?? false), is_string($body['default_value'] ?? null) ? substr($body['default_value'], 0, 20000) : null, (int)($body['sort_order'] ?? 0), $principal['userId']]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A metadata column with this name already exists.']);
            throw $error;
        }
        Runtime::respond(201, ['id' => $id]);
    }
    if ($method === 'PATCH' && preg_match('#^/metadata-columns/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody();
        $mapping = ['name' => 'name', 'label' => 'label', 'data_type' => 'data_type', 'width' => 'width', 'visible' => 'visible', 'sortable' => 'sortable', 'required' => 'required', 'default_value' => 'default_value', 'sort_order' => 'sort_order'];
        $sets = [];
        $values = [];
        foreach ($mapping as $input => $column) {
            if (!array_key_exists($input, $body)) continue;
            $value = $body[$input];
            if ($input === 'name' && (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,127}$/', $value))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid column name.']);
            if ($input === 'label' && (!is_string($value) || trim($value) === '' || strlen($value) > 256)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid column label.']);
            if ($input === 'data_type' && (!is_string($value) || !in_array($value, ['text', 'number', 'date', 'boolean', 'select'], true))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid column type.']);
            if ($input === 'width') $value = max(40, min(1000, (int)$value));
            if (in_array($input, ['visible', 'sortable', 'required'], true)) $value = (int)(bool)$value;
            if ($input === 'default_value') $value = is_string($value) ? substr($value, 0, 20000) : null;
            if ($input === 'sort_order') $value = (int)$value;
            $sets[] = "{$column} = ?";
            $values[] = $value;
        }
        if (array_key_exists('select_options', $body)) {
            if (!is_array($body['select_options'])) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'select_options must be an array.']);
            $sets[] = 'select_options = ?';
            $values[] = json_encode(array_values($body['select_options']), JSON_THROW_ON_ERROR);
        }
        if (!$sets) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'At least one field is required.']);
        $sets[] = 'updated_by = ?';
        $values[] = $principal['userId'];
        $values[] = $matches[1];
        $values[] = $principal['organizationId'];
        try {
            $statement = $db->prepare('UPDATE file_metadata_columns SET ' . implode(', ', $sets) . ' WHERE id = ? AND organization_id = ?');
            $statement->execute($values);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'A metadata column with this name already exists.']);
            throw $error;
        }
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare('SELECT 1 FROM file_metadata_columns WHERE id = ? AND organization_id = ?');
            $exists->execute([$matches[1], $principal['organizationId']]);
            if (!$exists->fetchColumn()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Metadata column not found.']);
        }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && $path === '/column-defaults/organization') {
        $query = $db->prepare('SELECT column_defaults FROM organization_settings WHERE organization_id = ?');
        $query->execute([$principal['organizationId']]);
        $value = $query->fetchColumn();
        Runtime::respond(200, ['columnDefaults' => decodeOrganizationSetting($value)]);
    }
    if ($method === 'PUT' && $path === '/column-defaults/organization') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $columnDefaults = normalizeColumnDefaults(Runtime::jsonBody()['columnDefaults'] ?? null);
        $db->prepare('INSERT INTO organization_settings (organization_id, column_defaults) VALUES (?, ?) ON DUPLICATE KEY UPDATE column_defaults = VALUES(column_defaults)')
            ->execute([$principal['organizationId'], json_encode($columnDefaults, JSON_THROW_ON_ERROR)]);
        Runtime::respond(200, ['columnDefaults' => $columnDefaults]);
    }
    if ($method === 'POST' && $path === '/column-defaults/organization/force') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $columnDefaults = normalizeColumnDefaults(Runtime::jsonBody()['columnDefaults'] ?? null);
        $encoded = json_encode($columnDefaults, JSON_THROW_ON_ERROR);
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO organization_settings (organization_id, column_defaults) VALUES (?, ?) ON DUPLICATE KEY UPDATE column_defaults = VALUES(column_defaults)')->execute([$principal['organizationId'], $encoded]);
            $db->prepare('INSERT INTO user_settings (user_id, column_defaults) SELECT user_id, ? FROM organization_memberships WHERE organization_id = ? ON DUPLICATE KEY UPDATE column_defaults = VALUES(column_defaults)')->execute([$encoded, $principal['organizationId']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['columnDefaults' => $columnDefaults]);
    }
    if ($method === 'GET' && $path === '/column-defaults/user') {
        $query = $db->prepare('SELECT column_defaults FROM user_settings WHERE user_id = ?');
        $query->execute([$principal['userId']]);
        $value = $query->fetchColumn();
        Runtime::respond(200, ['columnDefaults' => decodeOrganizationSetting($value)]);
    }
    if ($method === 'PUT' && $path === '/column-defaults/user') {
        $columnDefaults = normalizeColumnDefaults(Runtime::jsonBody()['columnDefaults'] ?? null);
        $db->prepare('INSERT INTO user_settings (user_id, column_defaults) VALUES (?, ?) ON DUPLICATE KEY UPDATE column_defaults = VALUES(column_defaults)')
            ->execute([$principal['userId'], json_encode($columnDefaults, JSON_THROW_ON_ERROR)]);
        Runtime::respond(200, ['columnDefaults' => $columnDefaults]);
    }
    if ($method === 'DELETE' && preg_match('#^/metadata-columns/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $statement = $db->prepare('DELETE FROM file_metadata_columns WHERE id = ? AND organization_id = ?');
        $statement->execute([$matches[1], $principal['organizationId']]);
        if ($statement->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Metadata column not found.']);
        Runtime::respond(204);
    }
    if ($method === 'GET' && $path === '/organizations/current/profile') {
        $q=$db->prepare('SELECT logo_storage_path,phone,website,contact_email FROM organizations WHERE id=?');$q->execute([$principal['organizationId']]);Runtime::respond(200,['profile'=>$q->fetch()?:null]);
    }
    if ($method === 'PATCH' && $path === '/organizations/current/profile') {
        if (!in_array($principal['role'], ['owner','admin'], true)) Runtime::respond(403,['error'=>'FORBIDDEN','message'=>'Administrator role required.']);$b=Runtime::jsonBody();$map=['phone'=>'phone','website'=>'website','contactEmail'=>'contact_email','logoStoragePath'=>'logo_storage_path'];$sets=[];$values=[];foreach($map as $input=>$column)if(array_key_exists($input,$b)){$sets[]="$column=?";$values[]=$b[$input]===null?null:(is_string($b[$input])?substr($b[$input],0,2048):null);}if(!$sets)Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'At least one profile field is required.']);$values[]=$principal['organizationId'];$db->prepare('UPDATE organizations SET '.implode(',',$sets).' WHERE id=?')->execute($values);Runtime::respond(200,['success'=>true]);
    }
    if ($method === 'GET' && $path === '/organizations/current/addresses') {
        $q=$db->prepare('SELECT id,organization_id AS org_id,address_type,label,is_default,company_name,contact_name,address_line1,address_line2,city,state,postal_code,country,attention_to,phone FROM organization_addresses WHERE organization_id=? ORDER BY address_type,is_default DESC,label');$q->execute([$principal['organizationId']]);$rows=$q->fetchAll();foreach($rows as &$row)$row['is_default']=(bool)$row['is_default'];unset($row);Runtime::respond(200,['addresses'=>$rows]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/checkout-owner$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $query = $db->prepare('SELECT u.id, u.email, u.display_name AS full_name, NULL AS avatar_url FROM checkouts c JOIN users u ON u.id = c.user_id WHERE c.file_id = ? AND c.expires_at > UTC_TIMESTAMP(3)'); $query->execute([$matches[1]]); Runtime::respond(200, ['user' => $query->fetch() ?: null]);
    }
    if ($method === 'PUT' && preg_match('#^/files/([0-9a-f-]{36})/watcher$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1]; $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$fileId, $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $checkin = is_bool($body['notifyOnCheckin'] ?? null) ? $body['notifyOnCheckin'] : true; $checkout = is_bool($body['notifyOnCheckout'] ?? null) ? $body['notifyOnCheckout'] : false; $state = is_bool($body['notifyOnStateChange'] ?? null) ? $body['notifyOnStateChange'] : true; $review = is_bool($body['notifyOnReview'] ?? null) ? $body['notifyOnReview'] : true;
        $db->prepare('INSERT INTO file_watchers (id, organization_id, file_id, user_id, notify_on_checkin, notify_on_checkout, notify_on_state_change, notify_on_review) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE notify_on_checkin = VALUES(notify_on_checkin), notify_on_checkout = VALUES(notify_on_checkout), notify_on_state_change = VALUES(notify_on_state_change), notify_on_review = VALUES(notify_on_review)')->execute([Runtime::uuid(), $principal['organizationId'], $fileId, $principal['userId'], $checkin, $checkout, $state, $review]); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/files/([0-9a-f-]{36})/watcher$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $db->prepare('DELETE FROM file_watchers WHERE file_id = ? AND user_id = ? AND organization_id = ?')->execute([$matches[1], $principal['userId'], $principal['organizationId']]); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/watcher$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $query = $db->prepare('SELECT id FROM file_watchers WHERE file_id = ? AND user_id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['userId'], $principal['organizationId']]); Runtime::respond(200, ['watching' => (bool)$query->fetch()]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/references/sync$#i', $path, $matches)) {
        Runtime::respond(200, FileReferences::sync($db, $principal, $matches[1], Runtime::jsonBody()));
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/references/(where-used|contains)$#i', $path, $matches)) {
        Runtime::respond(200, ['references' => FileReferences::list($db, $principal, $matches[1], $matches[2])]);
    }
    if ($method === 'GET' && $path === '/file-watchers/mine') {
        $query = $db->prepare('SELECT fw.id, fw.notify_on_checkin, fw.notify_on_checkout, fw.notify_on_state_change, fw.notify_on_review, fw.created_at, f.id AS file_id, f.file_name, f.canonical_path AS file_path, f.state, f.current_revision AS version FROM file_watchers fw JOIN files f ON f.id = fw.file_id WHERE fw.user_id = ? AND fw.organization_id = ? AND f.deleted_at IS NULL ORDER BY fw.created_at DESC'); $query->execute([$principal['userId'], $principal['organizationId']]); Runtime::respond(200, ['watchers' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/ecos/active') {
        $query = $db->prepare("SELECT id, eco_number, title, status, created_at FROM ecos WHERE organization_id = ? AND status IN ('open', 'in_progress') ORDER BY created_at DESC"); $query->execute([$principal['organizationId']]); Runtime::respond(200, ['ecos' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/ecos') {
        $q=$db->prepare('SELECT e.id,e.eco_number,e.title,e.description,e.status,e.created_at,e.created_by,u.display_name AS created_by_name,u.email AS created_by_email,(SELECT COUNT(*) FROM file_ecos fe WHERE fe.eco_id=e.id) AS file_count FROM ecos e LEFT JOIN users u ON u.id=e.created_by WHERE e.organization_id=? ORDER BY e.created_at DESC');$q->execute([$principal['organizationId']]);Runtime::respond(200,['ecos'=>$q->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/ecos') {
        $b=Runtime::jsonBody();$number=is_string($b['ecoNumber']??null)?strtoupper(trim($b['ecoNumber'])):'';$title=is_string($b['title']??null)?trim($b['title']):'';if($number===''||strlen($number)>128||strlen($title)>512)Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'Invalid ECO payload.']);$id=Runtime::uuid();try{$db->prepare('INSERT INTO ecos (id,organization_id,eco_number,title,description,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$principal['organizationId'],$number,$title,is_string($b['description']??null)?substr($b['description'],0,20000):null,'open',$principal['userId'],$principal['userId']]);}catch(PDOException $e){if($e->getCode()==='23000')Runtime::respond(409,['error'=>'ALREADY_EXISTS','message'=>'ECO number already exists.']);throw $e;}Runtime::respond(201,['eco'=>['id'=>$id,'eco_number'=>$number,'title'=>$title?:null,'description'=>$b['description']??null,'status'=>'open','created_at'=>gmdate('c'),'created_by'=>$principal['userId'],'created_by_name'=>$principal['displayName'],'created_by_email'=>$principal['email'],'file_count'=>0]]);
    }
    if ($method === 'GET' && preg_match('#^/ecos/([0-9a-f-]{36})/files$#i',$path,$m)) {
        $q=$db->prepare('SELECT id FROM ecos WHERE id=? AND organization_id=?');$q->execute([$m[1],$principal['organizationId']]);if(!$q->fetch())Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'ECO not found.']);$q=$db->prepare("SELECT fe.*,f.file_name,f.canonical_path,f.current_revision FROM file_ecos fe JOIN files f ON f.id=fe.file_id WHERE fe.eco_id=? AND f.deleted_at IS NULL AND (? IN ('owner','admin') OR EXISTS(SELECT 1 FROM vault_access va WHERE va.vault_id=f.vault_id AND va.user_id=?) OR EXISTS(SELECT 1 FROM team_vault_access tva JOIN team_members tm ON tm.team_id=tva.team_id WHERE tva.vault_id=f.vault_id AND tm.user_id=?)) ORDER BY fe.created_at DESC");$q->execute([$m[1],$principal['role'],$principal['userId'],$principal['userId']]);$rows=$q->fetchAll();foreach($rows as &$r){$r['file']=['id'=>$r['file_id'],'file_name'=>$r['file_name'],'file_path'=>$r['canonical_path'],'part_number'=>null,'revision'=>(string)$r['current_revision']];unset($r['file_name'],$r['canonical_path'],$r['current_revision']);}unset($r);Runtime::respond(200,['files'=>$rows]);
    }
    if ($method === 'PATCH' && preg_match('#^/ecos/([0-9a-f-]{36})/status$#i',$path,$m)) {
        $b=Runtime::jsonBody();$status=$b['status']??null;if(!is_string($status)||!in_array($status,['open','in_progress','completed','cancelled'],true))Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'Invalid status.']);$q=$status==='completed'?$db->prepare('UPDATE ecos SET status=?,completed_at=UTC_TIMESTAMP(3),updated_by=? WHERE id=? AND organization_id=?'):$db->prepare('UPDATE ecos SET status=?,updated_by=? WHERE id=? AND organization_id=?');$q->execute($status==='completed'?[$status,$principal['userId'],$m[1],$principal['organizationId']]:[$status,$principal['userId'],$m[1],$principal['organizationId']]);if($q->rowCount()===0)Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'ECO not found.']);Runtime::respond(200,['success'=>true]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/ecos/([0-9a-f-]{36})$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $eco = $db->prepare('SELECT id FROM ecos WHERE id = ? AND organization_id = ?'); $eco->execute([$matches[2], $principal['organizationId']]); if (!$eco->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'ECO not found.']); $notes = is_string($body['notes'] ?? null) ? substr($body['notes'], 0, 20000) : null;
        try { $db->prepare('INSERT INTO file_ecos (id, file_id, eco_id, created_by, notes) VALUES (?, ?, ?, ?, ?)')->execute([Runtime::uuid(), $matches[1], $matches[2], $principal['userId'], $notes]); } catch (PDOException $error) { if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_LINKED', 'message' => 'File is already part of this ECO.']); throw $error; } Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/files/([0-9a-f-]{36})/ecos/([0-9a-f-]{36})$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $db->prepare('DELETE fe FROM file_ecos fe JOIN ecos e ON e.id = fe.eco_id WHERE fe.file_id = ? AND fe.eco_id = ? AND e.organization_id = ?')->execute([$matches[1], $matches[2], $principal['organizationId']]); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/ecos$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $query = $db->prepare('SELECT fe.id, fe.notes, fe.created_at, e.id AS eco_id, e.eco_number, e.title, e.status FROM file_ecos fe JOIN ecos e ON e.id = fe.eco_id WHERE fe.file_id = ? AND e.organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]); Runtime::respond(200, ['ecos' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/vaults') {
        if (in_array($principal['role'], ['owner', 'admin'], true)) {
            $query = $db->prepare("SELECT id, name, network_root AS networkRoot, 'network' AS storageProvider, created_at AS createdAt FROM vaults WHERE organization_id = ? ORDER BY name");
            $query->execute([$principal['organizationId']]);
        } elseif ($principal['role'] === 'guest') {
            $query = $db->prepare(
                "SELECT v.id, v.name, v.network_root AS networkRoot, 'network' AS storageProvider, v.created_at AS createdAt FROM vaults v
                 JOIN vault_access a ON a.vault_id = v.id AND a.user_id = ?
                 WHERE v.organization_id = ? ORDER BY v.name"
            );
            $query->execute([$principal['userId'], $principal['organizationId']]);
        } else {
            $query = $db->prepare(
                "SELECT DISTINCT v.id, v.name, v.network_root AS networkRoot, 'network' AS storageProvider, v.created_at AS createdAt FROM vaults v
                 LEFT JOIN vault_access a ON a.vault_id = v.id AND a.user_id = ?
                 LEFT JOIN team_vault_access ta ON ta.vault_id = v.id
                 LEFT JOIN team_members tm ON tm.team_id = ta.team_id AND tm.user_id = ?
                 WHERE v.organization_id = ? AND (a.user_id IS NOT NULL OR tm.user_id IS NOT NULL) ORDER BY v.name"
            );
            $query->execute([$principal['userId'], $principal['userId'], $principal['organizationId']]);
        }
        Runtime::respond(200, ['vaults' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/vaults') {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator role required.']);
        $body = Runtime::jsonBody(); $name = is_string($body['name'] ?? null) ? trim($body['name']) : ''; $provider = $body['storageProvider'] ?? 'network'; $networkRoot = is_string($body['networkRoot'] ?? null) ? trim($body['networkRoot']) : '';
        if ($name === '' || strlen($name) > 200 || $provider !== 'network') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid network vault name is required.']);
        if ($networkRoot === '' || strlen($networkRoot) > 1024) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'networkRoot is required for a network vault.']);
        $id = Runtime::uuid(); $db->prepare('INSERT INTO vaults (id, organization_id, name, network_root) VALUES (?, ?, ?, ?)')->execute([$id, $principal['organizationId'], $name, $networkRoot]); Runtime::respond(201, ['id' => $id, 'name' => $name, 'networkRoot' => $networkRoot, 'storageProvider' => 'network', 'createdAt' => gmdate('c')]);
    }
    if ($method === 'GET' && $path === '/suppliers') {
        $query = $db->prepare('SELECT id, name, code, contact_email, contact_phone, website, city, state, country, is_active, is_approved, erp_id, erp_synced_at, created_at FROM suppliers WHERE organization_id = ? ORDER BY is_active DESC, name'); $query->execute([$principal['organizationId']]); $rows = $query->fetchAll(); foreach ($rows as &$row) { $row['is_active'] = (bool)$row['is_active']; $row['is_approved'] = (bool)$row['is_approved']; } unset($row); Runtime::respond(200, ['suppliers' => $rows]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/suppliers$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $query = $db->prepare("SELECT ps.*, JSON_OBJECT('id',s.id,'name',s.name,'code',s.code,'contact_email',s.contact_email,'contact_phone',s.contact_phone,'website',s.website,'city',s.city,'state',s.state,'country',s.country,'is_active',s.is_active,'is_approved',s.is_approved,'erp_id',s.erp_id,'erp_synced_at',s.erp_synced_at,'created_at',s.created_at) AS supplier FROM part_suppliers ps JOIN suppliers s ON s.id = ps.supplier_id WHERE ps.file_id = ? AND ps.organization_id = ? AND ps.is_active = TRUE ORDER BY ps.is_preferred DESC, ps.unit_price IS NULL, ps.unit_price ASC"); $query->execute([$matches[1], $principal['organizationId']]); $rows = $query->fetchAll(); foreach ($rows as &$row) { $row['supplier'] = json_decode($row['supplier'], true, 512, JSON_THROW_ON_ERROR); $row['price_breaks'] = $row['price_breaks'] === null ? null : json_decode($row['price_breaks'], true, 512, JSON_THROW_ON_ERROR); foreach (['is_preferred','is_active','is_qualified'] as $key) $row[$key] = (bool)$row[$key]; if ($row['unit_price'] !== null) $row['unit_price'] = (float)$row['unit_price']; } unset($row); Runtime::respond(200, ['partSuppliers' => $rows]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/suppliers$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $supplierId = $body['supplierId'] ?? null; if (!is_string($supplierId) || !preg_match('/^[0-9a-f-]{36}$/i', $supplierId)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'supplierId is required.']); $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $supplier = $db->prepare('SELECT id FROM suppliers WHERE id = ? AND organization_id = ? AND is_active = TRUE'); $supplier->execute([$supplierId, $principal['organizationId']]); if (!$supplier->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Supplier not found.']);
        $price = $body['unitPrice'] ?? null; if ($price !== null && (!is_numeric($price) || (float)$price < 0)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'unitPrice must be non-negative.']); $id = Runtime::uuid(); $preferred = (bool)($body['isPreferred'] ?? false); $db->beginTransaction(); try { if ($preferred) $db->prepare('UPDATE part_suppliers SET is_preferred = FALSE, updated_by = ? WHERE file_id = ? AND organization_id = ?')->execute([$principal['userId'], $matches[1], $principal['organizationId']]); $db->prepare('INSERT INTO part_suppliers (id, organization_id, file_id, supplier_id, supplier_part_number, supplier_description, supplier_url, unit_price, currency, price_unit, price_breaks, min_order_qty, order_multiple, lead_time_days, is_preferred, is_qualified, qualified_at, notes, last_price_update, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?, ?)')->execute([$id, $principal['organizationId'], $matches[1], $supplierId, is_string($body['supplierPartNumber'] ?? null) ? substr($body['supplierPartNumber'],0,255) : null, is_string($body['supplierDescription'] ?? null) ? substr($body['supplierDescription'],0,20000) : null, is_string($body['supplierUrl'] ?? null) ? substr($body['supplierUrl'],0,2048) : null, $price === null ? null : (float)$price, is_string($body['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/',$body['currency']) ? strtoupper($body['currency']) : 'USD', is_string($body['priceUnit'] ?? null) ? substr($body['priceUnit'],0,64) : 'each', isset($body['priceBreaks']) ? json_encode($body['priceBreaks'], JSON_THROW_ON_ERROR) : null, is_int($body['minOrderQty'] ?? null) && $body['minOrderQty'] > 0 ? $body['minOrderQty'] : 1, is_int($body['orderMultiple'] ?? null) && $body['orderMultiple'] > 0 ? $body['orderMultiple'] : 1, is_int($body['leadTimeDays'] ?? null) && $body['leadTimeDays'] >= 0 ? $body['leadTimeDays'] : null, $preferred, (bool)($body['isQualified'] ?? false), is_string($body['qualifiedAt'] ?? null) ? $body['qualifiedAt'] : null, is_string($body['notes'] ?? null) ? substr($body['notes'],0,20000) : null, $principal['userId'], $principal['userId']]); $db->commit(); } catch (PDOException $error) { if ($db->inTransaction()) $db->rollBack(); if ($error->getCode() === '23000') Runtime::respond(409, ['error' => 'ALREADY_EXISTS', 'message' => 'This supplier is already assigned to the item.']); throw $error; }
        $query = $db->prepare("SELECT ps.*, JSON_OBJECT('id',s.id,'name',s.name,'code',s.code,'contact_email',s.contact_email,'contact_phone',s.contact_phone,'website',s.website,'city',s.city,'state',s.state,'country',s.country,'is_active',s.is_active,'is_approved',s.is_approved,'erp_id',s.erp_id,'erp_synced_at',s.erp_synced_at,'created_at',s.created_at) AS supplier FROM part_suppliers ps JOIN suppliers s ON s.id = ps.supplier_id WHERE ps.id = ? AND ps.organization_id = ?"); $query->execute([$id, $principal['organizationId']]); $row = $query->fetch(); $row['supplier'] = json_decode($row['supplier'], true, 512, JSON_THROW_ON_ERROR); $row['price_breaks'] = $row['price_breaks'] === null ? null : json_decode($row['price_breaks'], true, 512, JSON_THROW_ON_ERROR); $row['unit_price'] = $row['unit_price'] === null ? null : (float)$row['unit_price']; foreach (['is_preferred','is_active','is_qualified'] as $key) $row[$key] = (bool)$row[$key]; Runtime::respond(201, ['partSupplier' => $row]);
    }
    if ($method === 'PATCH' && preg_match('#^/part-suppliers/([0-9a-f-]{36})$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $part = $db->prepare('SELECT ps.file_id, f.vault_id FROM part_suppliers ps JOIN files f ON f.id = ps.file_id WHERE ps.id = ? AND ps.organization_id = ?'); $part->execute([$matches[1], $principal['organizationId']]); $partRow = $part->fetch(); if (!$partRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Part supplier not found.']); Runtime::requireVault($db, $principal, $partRow['vault_id']);
        $mapping = ['supplierPartNumber' => 'supplier_part_number', 'supplierDescription' => 'supplier_description', 'supplierUrl' => 'supplier_url', 'unitPrice' => 'unit_price', 'currency' => 'currency', 'priceUnit' => 'price_unit', 'minOrderQty' => 'min_order_qty', 'orderMultiple' => 'order_multiple', 'leadTimeDays' => 'lead_time_days', 'isPreferred' => 'is_preferred', 'isQualified' => 'is_qualified', 'qualifiedAt' => 'qualified_at', 'notes' => 'notes']; $sets = []; $values = []; foreach ($mapping as $input => $column) { if (!array_key_exists($input, $body)) continue; $value = $body[$input]; if (in_array($input, ['unitPrice','minOrderQty','orderMultiple','leadTimeDays'], true) && $value !== null && !is_numeric($value)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "$input must be numeric."]); if ($input === 'currency' && is_string($value)) $value = strtoupper($value); $sets[] = "$column = ?"; $values[] = $value; } if (array_key_exists('priceBreaks', $body)) { $sets[] = 'price_breaks = ?'; $values[] = $body['priceBreaks'] === null ? null : json_encode($body['priceBreaks'], JSON_THROW_ON_ERROR); } if (!$sets) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'At least one field is required.']);
        $db->beginTransaction(); try { if (($body['isPreferred'] ?? false) === true) $db->prepare('UPDATE part_suppliers SET is_preferred = FALSE, updated_by = ? WHERE file_id = ? AND organization_id = ?')->execute([$principal['userId'], $partRow['file_id'], $principal['organizationId']]); if (array_key_exists('unitPrice', $body)) $sets[] = 'last_price_update = UTC_TIMESTAMP(3)'; $sets[] = 'updated_by = ?'; $values[] = $principal['userId']; $db->prepare('UPDATE part_suppliers SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([...$values, $matches[1]]); $db->commit(); } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        $query = $db->prepare("SELECT ps.*, JSON_OBJECT('id',s.id,'name',s.name,'code',s.code,'contact_email',s.contact_email,'contact_phone',s.contact_phone,'website',s.website,'city',s.city,'state',s.state,'country',s.country,'is_active',s.is_active,'is_approved',s.is_approved,'erp_id',s.erp_id,'erp_synced_at',s.erp_synced_at,'created_at',s.created_at) AS supplier FROM part_suppliers ps JOIN suppliers s ON s.id = ps.supplier_id WHERE ps.id = ? AND ps.organization_id = ?"); $query->execute([$matches[1], $principal['organizationId']]); $row = $query->fetch(); $row['supplier'] = json_decode($row['supplier'], true, 512, JSON_THROW_ON_ERROR); $row['price_breaks'] = $row['price_breaks'] === null ? null : json_decode($row['price_breaks'], true, 512, JSON_THROW_ON_ERROR); $row['unit_price'] = $row['unit_price'] === null ? null : (float)$row['unit_price']; foreach (['is_preferred','is_active','is_qualified'] as $key) $row[$key] = (bool)$row[$key]; Runtime::respond(200, ['partSupplier' => $row]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/suppliers/([0-9a-f-]{36})/preferred$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $part = $db->prepare('SELECT id FROM part_suppliers WHERE id = ? AND file_id = ? AND organization_id = ? AND is_active = TRUE'); $part->execute([$matches[2], $matches[1], $principal['organizationId']]); if (!$part->fetch()) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Part supplier not found.']); $db->beginTransaction(); $db->prepare('UPDATE part_suppliers SET is_preferred = FALSE, updated_by = ? WHERE file_id = ? AND organization_id = ?')->execute([$principal['userId'], $matches[1], $principal['organizationId']]); $db->prepare('UPDATE part_suppliers SET is_preferred = TRUE, updated_by = ? WHERE id = ?')->execute([$principal['userId'], $matches[2]]); $db->commit(); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/part-suppliers/([0-9a-f-]{36})$#i', $path, $matches)) {
        $part = $db->prepare('SELECT ps.file_id, f.vault_id FROM part_suppliers ps JOIN files f ON f.id = ps.file_id WHERE ps.id = ? AND ps.organization_id = ?'); $part->execute([$matches[1], $principal['organizationId']]); $partRow = $part->fetch(); if (!$partRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Part supplier not found.']); Runtime::requireVault($db, $principal, $partRow['vault_id']); $db->prepare('UPDATE part_suppliers SET is_active = FALSE, is_preferred = FALSE, updated_by = ? WHERE id = ?')->execute([$principal['userId'], $matches[1]]); Runtime::respond(204);
    }
    if ($method === 'GET' && $path === '/deviations') {
        $query = $db->prepare("SELECT d.*, u.display_name AS created_by_name, u.email AS created_by_email, a.display_name AS approved_by_name, (SELECT COUNT(*) FROM file_deviations fd WHERE fd.deviation_id=d.id) AS file_count FROM deviations d JOIN users u ON u.id=d.created_by LEFT JOIN users a ON a.id=d.approved_by WHERE d.organization_id=? ORDER BY d.created_at DESC"); $query->execute([$principal['organizationId']]); $rows=$query->fetchAll(); foreach ($rows as &$row) $row['affected_part_numbers']=$row['affected_part_numbers']===null?[]:json_decode($row['affected_part_numbers'],true,512,JSON_THROW_ON_ERROR); unset($row); Runtime::respond(200,['deviations'=>$rows]);
    }
    if ($method === 'POST' && $path === '/deviations') {
        $body=Runtime::jsonBody(); $number=is_string($body['deviationNumber']??null)?strtoupper(trim($body['deviationNumber'])):''; $title=is_string($body['title']??null)?trim($body['title']):''; if($number===''||strlen($number)>128||$title===''||strlen($title)>512) Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'deviationNumber and title are required.']); $id=Runtime::uuid(); try{$db->prepare('INSERT INTO deviations (id,organization_id,deviation_number,title,description,deviation_type,expiration_date,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$id,$principal['organizationId'],$number,$title,is_string($body['description']??null)?substr($body['description'],0,20000):null,is_string($body['deviationType']??null)?substr($body['deviationType'],0,128):null,is_string($body['expirationDate']??null)?$body['expirationDate']:null,$principal['userId'],$principal['userId']]);}catch(PDOException $error){if($error->getCode()==='23000')Runtime::respond(409,['error'=>'ALREADY_EXISTS','message'=>'Deviation number already exists.']);throw $error;} $query=$db->prepare('SELECT d.*,u.display_name AS created_by_name,u.email AS created_by_email,NULL AS approved_by_name,0 AS file_count FROM deviations d JOIN users u ON u.id=d.created_by WHERE d.id=?');$query->execute([$id]);$row=$query->fetch();$row['affected_part_numbers']=[];Runtime::respond(201,['deviation'=>$row]);
    }
    if ($method === 'GET' && preg_match('#^/deviations/([0-9a-f-]{36})/files$#i',$path,$matches)) {
        $deviation=$db->prepare('SELECT id FROM deviations WHERE id=? AND organization_id=?');$deviation->execute([$matches[1],$principal['organizationId']]);if(!$deviation->fetch())Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'Deviation not found.']); $query=$db->prepare("SELECT fd.*,f.id AS linked_file_id,f.file_name AS linked_file_name,f.canonical_path AS linked_file_path,f.current_revision AS linked_file_revision FROM file_deviations fd JOIN files f ON f.id=fd.file_id WHERE fd.deviation_id=? AND f.deleted_at IS NULL AND (? IN ('owner','admin') OR EXISTS(SELECT 1 FROM vault_access va WHERE va.vault_id=f.vault_id AND va.user_id=?) OR EXISTS(SELECT 1 FROM team_vault_access tva JOIN team_members tm ON tm.team_id=tva.team_id WHERE tva.vault_id=f.vault_id AND tm.user_id=?)) ORDER BY fd.created_at DESC");$query->execute([$matches[1],$principal['role'],$principal['userId'],$principal['userId']]);$rows=$query->fetchAll();foreach($rows as &$row){$row['file']=['id'=>$row['linked_file_id'],'file_name'=>$row['linked_file_name'],'file_path'=>$row['linked_file_path'],'part_number'=>null,'revision'=>(string)$row['linked_file_revision'],'version'=>$row['file_version']??(int)$row['linked_file_revision']];unset($row['linked_file_id'],$row['linked_file_name'],$row['linked_file_path'],$row['linked_file_revision']);}unset($row);Runtime::respond(200,['files'=>$rows]);
    }
    if ($method === 'PUT' && preg_match('#^/deviations/([0-9a-f-]{36})/files$#i',$path,$matches)) {
        $body=Runtime::jsonBody();$files=$body['files']??null;if(!is_array($files)||count($files)<1||count($files)>500)Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'One or more files are required.']);$deviation=$db->prepare('SELECT affected_part_numbers FROM deviations WHERE id=? AND organization_id=?');$deviation->execute([$matches[1],$principal['organizationId']]);$dev=$deviation->fetch();if(!$dev)Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'Deviation not found.']);foreach($files as $file){if(!is_array($file)||!is_string($file['fileId']??null))Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'Invalid file payload.']);$lookup=$db->prepare('SELECT vault_id FROM files WHERE id=? AND organization_id=? AND deleted_at IS NULL');$lookup->execute([$file['fileId'],$principal['organizationId']]);$fileRow=$lookup->fetch();if(!$fileRow)Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'File not found.']);Runtime::requireVault($db,$principal,$fileRow['vault_id']);}$parts=array_values(array_unique(array_merge($dev['affected_part_numbers']===null?[]:json_decode($dev['affected_part_numbers'],true,512,JSON_THROW_ON_ERROR),is_array($body['affectedPartNumbers']??null)?array_values(array_filter($body['affectedPartNumbers'],'is_string')):[])));$db->beginTransaction();try{foreach($files as $file)$db->prepare('INSERT INTO file_deviations (id,file_id,deviation_id,file_version,file_revision,notes,created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE file_version=VALUES(file_version),file_revision=VALUES(file_revision),notes=VALUES(notes)')->execute([Runtime::uuid(),$file['fileId'],$matches[1],is_int($file['fileVersion']??null)?$file['fileVersion']:null,is_string($file['fileRevision']??null)?substr($file['fileRevision'],0,64):null,is_string($file['notes']??null)?substr($file['notes'],0,20000):null,$principal['userId']]);$db->prepare('UPDATE deviations SET affected_part_numbers=?,updated_by=? WHERE id=?')->execute([json_encode($parts,JSON_THROW_ON_ERROR),$principal['userId'],$matches[1]]);$db->commit();}catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}Runtime::respond(200,['success'=>true]);
    }
    if ($method === 'DELETE' && preg_match('#^/file-deviations/([0-9a-f-]{36})$#i',$path,$matches)) {
        $query=$db->prepare('SELECT fd.file_id,f.vault_id FROM file_deviations fd JOIN deviations d ON d.id=fd.deviation_id JOIN files f ON f.id=fd.file_id WHERE fd.id=? AND d.organization_id=?');$query->execute([$matches[1],$principal['organizationId']]);$row=$query->fetch();if(!$row)Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'File deviation not found.']);Runtime::requireVault($db,$principal,$row['vault_id']);$db->prepare('DELETE FROM file_deviations WHERE id=?')->execute([$matches[1]]);Runtime::respond(204);
    }
    if ($method === 'PATCH' && preg_match('#^/deviations/([0-9a-f-]{36})/status$#i',$path,$matches)) {
        $body=Runtime::jsonBody();$status=$body['status']??null;if(!is_string($status)||!in_array($status,['draft','pending_approval','approved','rejected','closed','expired'],true))Runtime::respond(400,['error'=>'INVALID_REQUEST','message'=>'Invalid status.']);$query=$status==='approved'?$db->prepare('UPDATE deviations SET status=?,approved_by=?,approved_at=UTC_TIMESTAMP(3),updated_by=? WHERE id=? AND organization_id=?'):$db->prepare('UPDATE deviations SET status=?,updated_by=? WHERE id=? AND organization_id=?');$query->execute($status==='approved'?[$status,$principal['userId'],$principal['userId'],$matches[1],$principal['organizationId']]:[$status,$principal['userId'],$matches[1],$principal['organizationId']]);if($query->rowCount()===0)Runtime::respond(404,['error'=>'NOT_FOUND','message'=>'Deviation not found.']);Runtime::respond(200,['success'=>true]);
    }
    if ($method === 'GET' && $path === '/item-images') {
        if (in_array($principal['role'], ['owner', 'admin'], true)) $query = $db->prepare('SELECT part_number AS partNumber, vault_id AS vaultId, image_type AS imageType, icon_name AS iconName, icon_color AS iconColor, storage_relative_path AS storageRelativePath FROM item_images WHERE organization_id = ? ORDER BY part_number');
        else $query = $db->prepare('SELECT DISTINCT i.part_number AS partNumber, i.vault_id AS vaultId, i.image_type AS imageType, i.icon_name AS iconName, i.icon_color AS iconColor, i.storage_relative_path AS storageRelativePath FROM item_images i LEFT JOIN vault_access va ON va.vault_id = i.vault_id AND va.user_id = ? LEFT JOIN team_vault_access tva ON tva.vault_id = i.vault_id LEFT JOIN team_members tm ON tm.team_id = tva.team_id AND tm.user_id = ? WHERE i.organization_id = ? AND (va.user_id IS NOT NULL OR tm.user_id IS NOT NULL) ORDER BY i.part_number');
        $query->execute(in_array($principal['role'], ['owner', 'admin'], true) ? [$principal['organizationId']] : [$principal['userId'], $principal['userId'], $principal['organizationId']]); Runtime::respond(200, ['images' => $query->fetchAll()]);
    }
    if ($method === 'PUT' && preg_match('#^/item-images/(.+)$#', $path, $matches)) {
        $partNumber = rawurldecode($matches[1]); $body = Runtime::jsonBody(); $vaultId = $body['vaultId'] ?? null; $imageType = $body['imageType'] ?? null; $iconName = $body['iconName'] ?? null; $iconColor = $body['iconColor'] ?? null; $storagePath = $body['storageRelativePath'] ?? null;
        if (!is_string($vaultId) || !preg_match('/^[0-9a-f-]{36}$/i', $vaultId) || !is_string($imageType) || !in_array($imageType, ['icon', 'image'], true) || $partNumber === '' || strlen($partNumber) > 256 || ($imageType === 'icon' && (!is_string($iconName) || $iconName === '')) || ($imageType === 'image' && (!is_string($storagePath) || $storagePath === ''))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Invalid item image payload.']);
        Runtime::requireVault($db, $principal, $vaultId); $db->prepare('INSERT INTO item_images (id, organization_id, vault_id, part_number, image_type, icon_name, icon_color, storage_relative_path, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vault_id = VALUES(vault_id), image_type = VALUES(image_type), icon_name = VALUES(icon_name), icon_color = VALUES(icon_color), storage_relative_path = VALUES(storage_relative_path), updated_at = UTC_TIMESTAMP(3)')->execute([Runtime::uuid(), $principal['organizationId'], $vaultId, $partNumber, $imageType, is_string($iconName) ? substr($iconName, 0, 128) : null, is_string($iconColor) ? substr($iconColor, 0, 64) : null, is_string($storagePath) ? substr($storagePath, 0, 2048) : null, $principal['userId']]); Runtime::respond(200, ['partNumber' => $partNumber, 'vaultId' => $vaultId, 'imageType' => $imageType, 'iconName' => $iconName, 'iconColor' => $iconColor, 'storageRelativePath' => $storagePath]);
    }
    if ($method === 'DELETE' && preg_match('#^/item-images/(.+)$#', $path, $matches)) {
        $partNumber = rawurldecode($matches[1]); $image = $db->prepare('SELECT vault_id FROM item_images WHERE organization_id = ? AND part_number = ?'); $image->execute([$principal['organizationId'], $partNumber]); $row = $image->fetch(); if (!$row) Runtime::respond(204); Runtime::requireVault($db, $principal, $row['vault_id']); $db->prepare('DELETE FROM item_images WHERE organization_id = ? AND part_number = ?')->execute([$principal['organizationId'], $partNumber]); Runtime::respond(204);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/share-links$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $days = $body['expiresInDays'] ?? 7; if (!is_int($days) || $days < 1 || $days > 365) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'expiresInDays must be between 1 and 365.']); $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); $id = Runtime::uuid(); $token = bin2hex(random_bytes(16)); $db->prepare('INSERT INTO file_share_links (id, organization_id, file_id, token, created_by, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL ? DAY))')->execute([$id, $principal['organizationId'], $matches[1], $token, $principal['userId'], $days]); Runtime::respond(201, ['id' => $id, 'token' => $token, 'expiresAt' => gmdate('c', time() + $days * 86400), 'downloadUrl' => "blueplm://share/$token"]);
    }
    if ($method === 'GET' && preg_match('#^/share-links/([a-f0-9]{32})$#i', $path, $matches)) {
        $link = $db->prepare('SELECT file_id FROM file_share_links WHERE token = ? AND organization_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(3)'); $link->execute([$matches[1], $principal['organizationId']]); $linkRow = $link->fetch(); if (!$linkRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Share link not found or expired.']); $file = $db->prepare('SELECT id, vault_id AS vaultId, canonical_path AS canonicalPath, file_name AS fileName, vault_id FROM files WHERE id = ? AND organization_id = ?'); $file->execute([$linkRow['file_id'], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']); unset($fileRow['vault_id']); Runtime::respond(200, ['file' => $fileRow]);
    }
    if ($method === 'GET' && $path === '/search/ecos') {
        $term = isset($_GET['q']) ? substr(trim((string)$_GET['q']), 0, 200) : ''; if ($term === '') Runtime::respond(200, ['results' => []]);
        if (in_array($principal['role'], ['owner', 'admin'], true)) $query = $db->prepare("SELECT e.eco_number, e.title AS eco_title, f.id AS file_id, f.file_name, f.canonical_path AS file_path, NULL AS part_number FROM ecos e JOIN file_ecos fe ON fe.eco_id=e.id JOIN files f ON f.id=fe.file_id WHERE e.organization_id=? AND e.eco_number LIKE ? AND f.deleted_at IS NULL ORDER BY e.eco_number,f.file_name LIMIT 200");
        else $query = $db->prepare("SELECT DISTINCT e.eco_number, e.title AS eco_title, f.id AS file_id, f.file_name, f.canonical_path AS file_path, NULL AS part_number FROM ecos e JOIN file_ecos fe ON fe.eco_id=e.id JOIN files f ON f.id=fe.file_id LEFT JOIN vault_access va ON va.vault_id=f.vault_id AND va.user_id=? LEFT JOIN team_vault_access tva ON tva.vault_id=f.vault_id LEFT JOIN team_members tm ON tm.team_id=tva.team_id AND tm.user_id=? WHERE e.organization_id=? AND e.eco_number LIKE ? AND f.deleted_at IS NULL AND (va.user_id IS NOT NULL OR tm.user_id IS NOT NULL) ORDER BY e.eco_number,f.file_name LIMIT 200");
        $query->execute(in_array($principal['role'], ['owner', 'admin'], true) ? [$principal['organizationId'], "%$term%"] : [$principal['userId'], $principal['userId'], $principal['organizationId'], "%$term%"]); Runtime::respond(200, ['results' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/activity') {
        $requestedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50; $limit = max(1, min($requestedLimit, 500));
        $select = "SELECT e.sequence_id AS id, e.type, e.aggregate_id AS aggregateId, e.payload, e.created_at AS createdAt, u.email AS userEmail, f.file_name AS fileName, f.canonical_path AS filePath FROM events e LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.userId')) LEFT JOIN files f ON f.id = e.aggregate_id AND f.organization_id = e.organization_id WHERE e.organization_id = ?";
        if (in_array($principal['role'], ['owner', 'admin'], true)) {
            $query = $db->prepare($select . ' ORDER BY e.sequence_id DESC LIMIT ?'); $query->bindValue(1, $principal['organizationId']); $query->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $query = $db->prepare($select . ' AND EXISTS (SELECT 1 FROM files accessible_file WHERE accessible_file.id = e.aggregate_id AND accessible_file.organization_id = e.organization_id AND (EXISTS (SELECT 1 FROM vault_access va WHERE va.vault_id = accessible_file.vault_id AND va.user_id = ?) OR EXISTS (SELECT 1 FROM team_vault_access tva JOIN team_members tm ON tm.team_id = tva.team_id WHERE tva.vault_id = accessible_file.vault_id AND tm.user_id = ?))) ORDER BY e.sequence_id DESC LIMIT ?');
            $query->bindValue(1, $principal['organizationId']); $query->bindValue(2, $principal['userId']); $query->bindValue(3, $principal['userId']); $query->bindValue(4, $limit, PDO::PARAM_INT);
        }
        $query->execute(); Runtime::respond(200, ['events' => $query->fetchAll()]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/activity$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ?'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch();
        if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $requestedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; $limit = max(1, min($requestedLimit, 500));
        $query = $db->prepare("SELECT e.sequence_id AS id, e.type, e.aggregate_id AS aggregateId, e.payload, e.created_at AS createdAt, u.email AS userEmail, f.file_name AS fileName, f.canonical_path AS filePath FROM events e LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.userId')) LEFT JOIN files f ON f.id = e.aggregate_id AND f.organization_id = e.organization_id WHERE e.organization_id = ? AND e.aggregate_id = ? ORDER BY e.sequence_id DESC LIMIT ?");
        $query->bindValue(1, $principal['organizationId']); $query->bindValue(2, $matches[1]); $query->bindValue(3, $limit, PDO::PARAM_INT); $query->execute(); Runtime::respond(200, ['events' => $query->fetchAll()]);
    }
    if ($method === 'GET' && preg_match('#^/vaults/([0-9a-f-]{36})/files$#i', $path, $matches)) {
        Runtime::requireVault($db, $principal, $matches[1]);
        $query = $db->prepare(
            'SELECT f.id, f.canonical_path AS canonicalPath, f.file_name AS fileName, f.part_number AS partNumber, f.storage_relative_path AS storageRelativePath,
                    f.current_revision AS currentRevision, f.state, f.content_hash AS contentHash, f.size_bytes AS sizeBytes,
                    f.created_at AS createdAt, f.updated_at AS updatedAt, c.user_id AS checkedOutByUserId, u.display_name AS checkedOutBy, c.expires_at AS checkoutExpiresAt
             FROM files f LEFT JOIN checkouts c ON c.file_id = f.id AND c.expires_at > UTC_TIMESTAMP(3)
             LEFT JOIN users u ON u.id = c.user_id WHERE f.vault_id = ? AND f.organization_id = ? AND f.deleted_at IS NULL ORDER BY f.canonical_path'
        );
        $query->execute([$matches[1], $principal['organizationId']]);
        Runtime::respond(200, ['files' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/files/import') {
        $body = Runtime::jsonBody();
        foreach (['vaultId', 'canonicalPath', 'storageRelativePath'] as $key) {
            if (!is_string($body[$key] ?? null) || trim($body[$key]) === '') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "{$key} is required."]);
        }
        $vaultId = $body['vaultId'];
        Runtime::requireVault($db, $principal, $vaultId);
        $canonicalPath = ltrim(str_replace('\\', '/', trim($body['canonicalPath'])), '/');
        $storageRelativePath = ltrim(str_replace('\\', '/', trim($body['storageRelativePath'])), '/');
        if ($canonicalPath === '' || $storageRelativePath === '' || str_contains($canonicalPath, '../') || str_contains($storageRelativePath, '../')) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'File paths must be normalized vault-relative paths.']);
        $fileName = is_string($body['fileName'] ?? null) && trim($body['fileName']) !== '' ? trim($body['fileName']) : basename($canonicalPath);
        $partNumber = is_string($body['partNumber'] ?? null) && trim($body['partNumber']) !== '' ? trim($body['partNumber']) : null;
        if ($partNumber !== null && strlen($partNumber) > 512) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'partNumber is too long.']);
        $contentHash = is_string($body['contentHash'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $body['contentHash']) ? strtolower($body['contentHash']) : null;
        $sizeBytes = is_int($body['sizeBytes'] ?? null) && $body['sizeBytes'] >= 0 ? $body['sizeBytes'] : null;
        $existing = $db->prepare('SELECT id, part_number FROM files WHERE vault_id = ? AND canonical_path = ? AND deleted_at IS NULL');
        $existing->execute([$vaultId, $canonicalPath]);
        $record = $existing->fetch();
        if ($record) {
            try {
                // A rescan after upgrading from a schema without part_number is
                // the authoritative backfill path for existing vault contents.
                if ($partNumber !== null && $record['part_number'] !== $partNumber) {
                    $db->prepare('UPDATE files SET part_number = ? WHERE id = ? AND organization_id = ?')
                        ->execute([$partNumber, $record['id'], $principal['organizationId']]);
                }
            } catch (PDOException $error) {
                if ($error->getCode() === '23000' && str_contains($error->getMessage(), 'uq_files_org_part_number')) {
                    Runtime::respond(409, ['error' => 'PART_NUMBER_EXISTS', 'message' => 'This part number is already assigned to another file.']);
                }
                throw $error;
            }
            Runtime::respond(200, ['id' => $record['id'], 'created' => false]);
        }
        $fileId = Runtime::uuid();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, part_number, storage_relative_path, current_revision, content_hash, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$fileId, $principal['organizationId'], $vaultId, $canonicalPath, $fileName, $partNumber, $storageRelativePath, $contentHash, $sizeBytes]);
            $db->prepare('INSERT INTO file_revisions (id, file_id, revision_number, content_hash, storage_relative_path, size_bytes, checked_in_by, comment) VALUES (?, ?, 1, ?, ?, ?, ?, ?)')->execute([Runtime::uuid(), $fileId, $contentHash, $storageRelativePath, $sizeBytes, $principal['userId'], 'Initial vault import']);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.imported', $fileId, ['userId' => $principal['userId'], 'vaultId' => $vaultId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                if (str_contains($error->getMessage(), 'uq_files_org_part_number')) Runtime::respond(409, ['error' => 'PART_NUMBER_EXISTS', 'message' => 'This part number is already assigned to another file.']);
                Runtime::respond(409, ['error' => 'PATH_EXISTS', 'message' => 'A file already exists at this vault path.']);
            }
            throw $error;
        }
        Runtime::respond(201, ['id' => $fileId, 'created' => true]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/revisions$#i', $path, $matches)) {
        $fileId = $matches[1];
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$fileId, $principal['organizationId']]); $row = $file->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']);
        Runtime::requireVault($db, $principal, $row['vault_id']);
        $query = $db->prepare('SELECT id, revision_number AS revisionNumber, content_hash AS contentHash, storage_relative_path AS storageRelativePath, size_bytes AS sizeBytes, comment, created_at AS createdAt, checked_in_by AS checkedInBy FROM file_revisions WHERE file_id = ? ORDER BY revision_number DESC');
        $query->execute([$fileId]); Runtime::respond(200, ['revisions' => $query->fetchAll()]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/rollback-target$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $targetVersion = $body['targetVersion'] ?? null; if (!is_int($targetVersion) || $targetVersion < 1) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'targetVersion must be a positive integer.']); $comment = is_string($body['comment'] ?? null) ? substr($body['comment'], 0, 4096) : null;
        $file = $db->prepare('SELECT vault_id, current_revision FROM files WHERE id = ? AND organization_id = ?'); $file->execute([$matches[1], $principal['organizationId']]); $fileRow = $file->fetch(); if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $checkout = $db->prepare('SELECT file_id FROM checkouts WHERE file_id = ? AND user_id = ? AND expires_at > UTC_TIMESTAMP(3)'); $checkout->execute([$matches[1], $principal['userId']]); if (!$checkout->fetch()) Runtime::respond(409, ['error' => 'CHECKOUT_REQUIRED', 'message' => 'You must check out the file before switching versions.']);
        $revision = $db->prepare('SELECT id, revision_number AS revisionNumber, content_hash AS contentHash, storage_relative_path AS storageRelativePath, size_bytes AS sizeBytes, comment, created_at AS createdAt FROM file_revisions WHERE file_id = ? AND revision_number = ?'); $revision->execute([$matches[1], $targetVersion]); $target = $revision->fetch(); if (!$target) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => "Version $targetVersion not found."]);
        $maximum = $db->prepare('SELECT MAX(revision_number) AS maxVersion FROM file_revisions WHERE file_id = ?'); $maximum->execute([$matches[1]]); $max = $maximum->fetch(); $db->prepare('INSERT INTO events (organization_id, type, aggregate_id, payload) VALUES (?, ?, ?, ?)')->execute([$principal['organizationId'], 'file.revision_switch_requested', $matches[1], json_encode(['userId' => $principal['userId'], 'fromVersion' => (int)$fileRow['current_revision'], 'toVersion' => $targetVersion, 'comment' => $comment], JSON_THROW_ON_ERROR)]); Runtime::respond(200, ['success' => true, 'targetVersionRecord' => $target, 'maxVersion' => (int)($max['maxVersion'] ?? $fileRow['current_revision'])]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/workflow$#i', $path, $matches)) {
        $fileId = $matches[1];
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$fileId, $principal['organizationId']]); $row = $file->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']);
        Runtime::requireVault($db, $principal, $row['vault_id']);
        $query = $db->prepare('SELECT a.*, s.name AS current_state_name, s.color AS current_state_color, w.name AS workflow_name, w.description AS workflow_description FROM file_workflow_assignments a JOIN workflow_states s ON s.id = a.current_state_id JOIN workflow_templates w ON w.id = a.workflow_id WHERE a.file_id = ?');
        $query->execute([$fileId]); Runtime::respond(200, ['assignment' => $query->fetch() ?: null]);
    }
    if ($method === 'GET' && preg_match('#^/files/([0-9a-f-]{36})/inspection$#i', $path, $matches)) {
        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$matches[1], $principal['organizationId']]); $row = $file->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); Runtime::requireVault($db, $principal, $row['vault_id']);
        $query = $db->prepare('SELECT * FROM inspection_characteristics WHERE file_id = ? AND organization_id = ? ORDER BY sort_order, id'); $query->execute([$matches[1], $principal['organizationId']]); Runtime::respond(200, ['rows' => $query->fetchAll()]);
    }
    if ($method === 'PUT' && preg_match('#^/files/([0-9a-f-]{36})/inspection$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1]; $rows = $body['rows'] ?? null;
        if (!is_array($rows) || count($rows) > 10000) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'rows must be an array with at most 10000 entries.']);

        $textColumns = ['balloon_number', 'char_id', 'zone', 'char_type', 'sub_type', 'nominal_value', 'unit', 'plus_tolerance', 'minus_tolerance', 'upper_limit', 'lower_limit', 'classification', 'inspection_method', 'operation', 'aql', 'reference', 'comments'];
        $columns = ['sort_order', ...$textColumns, 'sample_size', 'supplier_inspection_rate', 'internal_inspection_rate'];
        $validatedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $row['id']) || !is_int($row['sort_order'] ?? null)) {
                Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Each inspection row needs a UUID id and an integer sort_order.']);
            }
            foreach ($textColumns as $column) if (($row[$column] ?? null) !== null && !is_string($row[$column])) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "Inspection field {$column} must be a string or null."]);
            if (($row['sample_size'] ?? null) !== null && !is_int($row['sample_size'])) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'sample_size must be an integer or null.']);
            foreach (['supplier_inspection_rate', 'internal_inspection_rate'] as $column) if (($row[$column] ?? null) !== null && (!is_numeric($row[$column]) || !is_finite((float)$row[$column]))) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => "Inspection field {$column} must be a finite number or null."]);
            $validatedRows[] = $row;
        }

        $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $file->execute([$fileId, $principal['organizationId']]); $fileRow = $file->fetch();
        if (!$fileRow) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']);
        Runtime::requireVault($db, $principal, $fileRow['vault_id']);
        $db->beginTransaction();
        try {
            $checkout = $db->prepare('SELECT user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $checkout->execute([$fileId]); $lock = $checkout->fetch();
            if (!$lock || $lock['user_id'] !== $principal['userId']) { $db->rollBack(); Runtime::respond(409, ['error' => 'CHECKOUT_REQUIRED', 'message' => 'You must check out the drawing before editing its inspection table.']); }
            $db->prepare('DELETE FROM inspection_characteristics WHERE file_id = ?')->execute([$fileId]);
            if ($validatedRows !== []) {
                $insertColumns = ['id', 'organization_id', 'file_id', ...$columns, 'created_by', 'updated_by'];
                $insert = $db->prepare('INSERT INTO inspection_characteristics (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', array_fill(0, count($insertColumns), '?')) . ')');
                foreach ($validatedRows as $row) $insert->execute([$row['id'], $principal['organizationId'], $fileId, $row['sort_order'], ...array_map(static fn(string $column): mixed => $row[$column] ?? null, $textColumns), $row['sample_size'] ?? null, $row['supplier_inspection_rate'] ?? null, $row['internal_inspection_rate'] ?? null, $principal['userId'], $principal['userId']]);
            }
            Runtime::emitEvent($db, $principal['organizationId'], 'file.inspection_updated', $fileId, ['count' => count($validatedRows), 'userId' => $principal['userId']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'GET' && preg_match('#^/file-revisions/([0-9a-f-]{36})/inspection$#i', $path, $matches)) {
        $revision = $db->prepare('SELECT f.vault_id FROM file_revisions r JOIN files f ON f.id = r.file_id WHERE r.id = ? AND f.organization_id = ?'); $revision->execute([$matches[1], $principal['organizationId']]); $row = $revision->fetch();
        if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File revision not found.']); Runtime::requireVault($db, $principal, $row['vault_id']);
        $query = $db->prepare('SELECT * FROM inspection_characteristic_versions WHERE file_revision_id = ? AND organization_id = ? ORDER BY sort_order, id'); $query->execute([$matches[1], $principal['organizationId']]); Runtime::respond(200, ['rows' => $query->fetchAll()]);
    }
    if ($method === 'GET' && $path === '/inspection-methods') {
        $query = $db->prepare('SELECT id, name FROM inspection_methods WHERE organization_id = ? ORDER BY name'); $query->execute([$principal['organizationId']]); Runtime::respond(200, ['methods' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/inspection-methods') {
        $body = Runtime::jsonBody(); $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || strlen($name) > 255) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A method name is required.']);
        $id = Runtime::uuid(); $db->prepare('INSERT INTO inspection_methods (id, organization_id, name, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id')->execute([$id, $principal['organizationId'], $name, $principal['userId']]);
        $query = $db->prepare('SELECT id, name FROM inspection_methods WHERE organization_id = ? AND name = ?'); $query->execute([$principal['organizationId'], $name]); Runtime::respond(201, ['method' => $query->fetch()]);
    }
    if ($method === 'PATCH' && preg_match('#^/inspection-methods/([0-9a-f-]{36})$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || strlen($name) > 255) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A method name is required.']);
        $query = $db->prepare('UPDATE inspection_methods SET name = ? WHERE id = ? AND organization_id = ?'); $query->execute([$name, $matches[1], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Inspection method not found.']); Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/inspection-methods/([0-9a-f-]{36})$#i', $path, $matches)) {
        $query = $db->prepare('DELETE FROM inspection_methods WHERE id = ? AND organization_id = ?'); $query->execute([$matches[1], $principal['organizationId']]); if ($query->rowCount() === 0) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Inspection method not found.']); Runtime::respond(204);
    }
    if ($method === 'GET' && preg_match('#^/vaults/([0-9a-f-]{36})/folders$#i', $path, $matches)) {
        Runtime::requireVault($db, $principal, $matches[1]); $query = $db->prepare('SELECT id, organization_id AS org_id, vault_id, folder_path, created_by, created_at, deleted_at, deleted_by FROM folders WHERE vault_id = ? AND deleted_at IS NULL ORDER BY folder_path'); $query->execute([$matches[1]]); Runtime::respond(200, ['folders' => $query->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/folders') {
        $body = Runtime::jsonBody(); if (!is_string($body['vaultId'] ?? null) || !is_string($body['folderPath'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'vaultId and folderPath are required.']);
        Runtime::requireVault($db, $principal, $body['vaultId']); $folderPath = ltrim(str_replace('\\', '/', trim($body['folderPath'])), '/');
        if ($folderPath === '' || str_contains($folderPath, '../')) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'folderPath must be a normalized vault-relative path.']);
        $db->prepare('INSERT INTO folders (id, organization_id, vault_id, folder_path, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE deleted_at = NULL, deleted_by = NULL')->execute([Runtime::uuid(), $principal['organizationId'], $body['vaultId'], $folderPath, $principal['userId']]);
        $query = $db->prepare('SELECT id, organization_id AS org_id, vault_id, folder_path, created_by, created_at, deleted_at, deleted_by FROM folders WHERE vault_id = ? AND folder_path = ?'); $query->execute([$body['vaultId'], $folderPath]); Runtime::respond(201, ['folder' => $query->fetch()]);
    }
    if ($method === 'PATCH' && preg_match('#^/folders/([0-9a-f-]{36})$#i', $path, $matches)) {
        $body = Runtime::jsonBody();
        if (!is_string($body['folderPath'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'folderPath is required.']);
        $folderPath = ltrim(str_replace('\\', '/', trim($body['folderPath'])), '/');
        if ($folderPath === '' || str_contains($folderPath, '../')) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'folderPath must be a normalized vault-relative path.']);
        $query = $db->prepare('SELECT vault_id FROM folders WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $query->execute([$matches[1], $principal['organizationId']]); $folder = $query->fetch();
        if (!$folder) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Folder not found.']);
        Runtime::requireVault($db, $principal, $folder['vault_id']);
        try {
            $db->prepare('UPDATE folders SET folder_path = ? WHERE id = ?')->execute([$folderPath, $matches[1]]);
        } catch (Throwable $error) {
            if ($error instanceof PDOException && $error->getCode() === '23000') Runtime::respond(409, ['error' => 'PATH_EXISTS', 'message' => 'A folder already exists at this path.']);
            throw $error;
        }
        Runtime::respond(200, ['success' => true]);
    }
    if ($method === 'DELETE' && preg_match('#^/folders/([0-9a-f-]{36})$#i', $path, $matches)) {
        $query = $db->prepare('SELECT vault_id, folder_path FROM folders WHERE id = ? AND organization_id = ? AND deleted_at IS NULL');
        $query->execute([$matches[1], $principal['organizationId']]); $folder = $query->fetch();
        if (!$folder) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'Folder not found.']);
        Runtime::requireVault($db, $principal, $folder['vault_id']);
        $db->prepare("UPDATE folders SET deleted_at = UTC_TIMESTAMP(3), deleted_by = ? WHERE vault_id = ? AND (folder_path = ? OR LOCATE(CONCAT(?, '/'), folder_path) = 1)")->execute([$principal['userId'], $folder['vault_id'], $folder['folder_path'], $folder['folder_path']]);
        Runtime::respond(204);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/checkout$#i', $path, $matches)) {
        $body = Runtime::jsonBody();
        if (!is_string($body['clientWorkingPath'] ?? null) || trim($body['clientWorkingPath']) === '') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'clientWorkingPath is required.']);
        $fileId = $matches[1]; Runtime::requireVault($db, $principal, (function () use ($db, $fileId, $principal) { $q = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL'); $q->execute([$fileId, $principal['organizationId']]); $row = $q->fetch(); if (!$row) Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); return $row['vault_id']; })());
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt = Runtime::checkoutExpiry($env);
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $lock->execute([$fileId]);
            if ($lock->fetch()) { $db->rollBack(); Runtime::respond(409, ['error' => 'CHECKED_OUT', 'message' => 'File is checked out by another client.']); }
            $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]);
            $db->prepare('INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES (?, ?, ?, ?, ?)')->execute([$fileId, $principal['userId'], Runtime::tokenHash($token, $env), $body['clientWorkingPath'], $expiresAt]);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.checked_out', $fileId, ['userId' => $principal['userId'], 'expiresAt' => str_replace(' ', 'T', $expiresAt) . 'Z']);
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['checkoutToken' => $token, 'expiresAt' => str_replace(' ', 'T', $expiresAt) . 'Z']);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/checkin$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1];
        if (!is_string($body['checkoutToken'] ?? null) || !is_string($body['storageRelativePath'] ?? null) || trim($body['storageRelativePath']) === '') Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'checkoutToken and storageRelativePath are required.']);
        $db->beginTransaction();
        try {
            $file = $db->prepare('SELECT * FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE'); $file->execute([$fileId, $principal['organizationId']]); $row = $file->fetch();
            if (!$row) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $row['vault_id']);
            $lock = $db->prepare('SELECT token_hash, user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $lock->execute([$fileId]); $checkout = $lock->fetch();
            if (!$checkout || $checkout['user_id'] !== $principal['userId'] || !hash_equals($checkout['token_hash'], Runtime::tokenHash($body['checkoutToken'], $env))) { $db->rollBack(); Runtime::respond(409, ['error' => 'INVALID_CHECKOUT', 'message' => 'A valid checkout from this client is required.']); }
            $revision = (int)$row['current_revision'] + 1;
            $relativePath = ltrim(str_replace('\\', '/', trim($body['storageRelativePath'])), '/');
            if ($relativePath === '' || str_contains($relativePath, '../')) { $db->rollBack(); Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'storageRelativePath must be a normalized vault-relative path.']); }
            $hash = is_string($body['contentHash'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $body['contentHash']) ? $body['contentHash'] : null;
            $size = is_int($body['sizeBytes'] ?? null) && $body['sizeBytes'] >= 0 ? $body['sizeBytes'] : null;
            $comment = is_string($body['comment'] ?? null) ? substr($body['comment'], 0, 4000) : null;
            $partNumberProvided = array_key_exists('partNumber', $body);
            $partNumber = null;
            if ($partNumberProvided) {
                if ($body['partNumber'] !== null && !is_string($body['partNumber'])) { $db->rollBack(); Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'partNumber must be a string or null.']); }
                $partNumber = is_string($body['partNumber']) && trim($body['partNumber']) !== '' ? trim($body['partNumber']) : null;
                if ($partNumber !== null && strlen($partNumber) > 512) { $db->rollBack(); Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'partNumber is too long.']); }
            }
            if ($partNumberProvided) {
                $db->prepare('UPDATE files SET current_revision = ?, storage_relative_path = ?, content_hash = ?, size_bytes = ?, part_number = ? WHERE id = ?')->execute([$revision, $relativePath, $hash, $size, $partNumber, $fileId]);
            } else {
                $db->prepare('UPDATE files SET current_revision = ?, storage_relative_path = ?, content_hash = ?, size_bytes = ? WHERE id = ?')->execute([$revision, $relativePath, $hash, $size, $fileId]);
            }
            $revisionId = Runtime::uuid();
            $db->prepare('INSERT INTO file_revisions (id, file_id, revision_number, content_hash, storage_relative_path, size_bytes, checked_in_by, comment) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$revisionId, $fileId, $revision, $hash, $relativePath, $size, $principal['userId'], $comment]);
            $db->prepare('INSERT INTO inspection_characteristic_versions (id, file_revision_id, organization_id, sort_order, balloon_number, char_id, zone, char_type, sub_type, nominal_value, unit, plus_tolerance, minus_tolerance, upper_limit, lower_limit, classification, inspection_method, operation, aql, sample_size, supplier_inspection_rate, internal_inspection_rate, reference, comments) SELECT UUID(), ?, organization_id, sort_order, balloon_number, char_id, zone, char_type, sub_type, nominal_value, unit, plus_tolerance, minus_tolerance, upper_limit, lower_limit, classification, inspection_method, operation, aql, sample_size, supplier_inspection_rate, internal_inspection_rate, reference, comments FROM inspection_characteristics WHERE file_id = ?')->execute([$revisionId, $fileId]);
            Runtime::emitEvent($db, $principal['organizationId'], 'file.checked_in', $fileId, ['revision' => $revision, 'userId' => $principal['userId']]);
            $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]); $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof PDOException && $error->getCode() === '23000' && str_contains($error->getMessage(), 'uq_files_org_part_number')) Runtime::respond(409, ['error' => 'PART_NUMBER_EXISTS', 'message' => 'This part number is already assigned to another file.']);
            throw $error;
        }
        Runtime::respond(200, ['fileId' => $fileId, 'revision' => $revision]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/checkout/renew$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1];
        if (!is_string($body['checkoutToken'] ?? null) || strlen($body['checkoutToken']) < 20) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'checkoutToken is required.']);
        $expiresAt = Runtime::checkoutExpiry($env);
        $db->beginTransaction();
        try {
            $file = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE'); $file->execute([$fileId, $principal['organizationId']]); $row = $file->fetch();
            if (!$row) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $row['vault_id']);
            $lock = $db->prepare('SELECT token_hash, user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $lock->execute([$fileId]); $checkout = $lock->fetch();
            if (!$checkout || $checkout['user_id'] !== $principal['userId'] || !hash_equals($checkout['token_hash'], Runtime::tokenHash($body['checkoutToken'], $env))) { $db->rollBack(); Runtime::respond(409, ['error' => 'INVALID_CHECKOUT', 'message' => 'A current checkout from this client is required.']); }
            $db->prepare('UPDATE checkouts SET expires_at = ? WHERE file_id = ?')->execute([$expiresAt, $fileId]); $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['expiresAt' => str_replace(' ', 'T', $expiresAt) . 'Z']);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/checkout/cancel$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1];
        if (!is_string($body['checkoutToken'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'checkoutToken is required.']);
        $lock = $db->prepare('SELECT c.token_hash, c.user_id, f.vault_id FROM checkouts c JOIN files f ON f.id = c.file_id WHERE c.file_id = ? AND f.organization_id = ?'); $lock->execute([$fileId, $principal['organizationId']]); $checkout = $lock->fetch();
        if (!$checkout || $checkout['user_id'] !== $principal['userId'] || !hash_equals($checkout['token_hash'], Runtime::tokenHash($body['checkoutToken'], $env))) Runtime::respond(409, ['error' => 'INVALID_CHECKOUT', 'message' => 'A valid checkout from this client is required.']);
        Runtime::requireVault($db, $principal, $checkout['vault_id']); $db->prepare('DELETE FROM checkouts WHERE file_id = ?')->execute([$fileId]); Runtime::emitEvent($db, $principal['organizationId'], 'file.checkout_cancelled', $fileId, ['userId' => $principal['userId']]); Runtime::respond(204);
    }
    if ($method === 'PATCH' && preg_match('#^/files/([0-9a-f-]{36})/path$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1];
        if (!is_string($body['canonicalPath'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'canonicalPath is required.']);
        $canonicalPath = ltrim(str_replace('\\', '/', trim($body['canonicalPath'])), '/');
        if ($canonicalPath === '' || str_contains($canonicalPath, '../')) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'canonicalPath must be a normalized vault-relative path.']);
        $fileName = is_string($body['fileName'] ?? null) && trim($body['fileName']) !== '' ? trim($body['fileName']) : basename($canonicalPath);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE'); $query->execute([$fileId, $principal['organizationId']]); $file = $query->fetch();
            if (!$file) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $file['vault_id']);
            $lock = $db->prepare('SELECT user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $lock->execute([$fileId]); $checkout = $lock->fetch();
            if ($checkout && $checkout['user_id'] !== $principal['userId']) { $db->rollBack(); Runtime::respond(409, ['error' => 'CHECKED_OUT', 'message' => 'File is checked out by another user.']); }
            $collision = $db->prepare('SELECT id FROM files WHERE vault_id = ? AND canonical_path = ? AND deleted_at IS NULL AND id <> ? FOR UPDATE'); $collision->execute([$file['vault_id'], $canonicalPath, $fileId]);
            if ($collision->fetch()) { $db->rollBack(); Runtime::respond(409, ['error' => 'PATH_EXISTS', 'message' => 'A file already exists at this path.']); }
            $db->prepare('UPDATE files SET canonical_path = ?, file_name = ? WHERE id = ?')->execute([$canonicalPath, $fileName, $fileId]); Runtime::emitEvent($db, $principal['organizationId'], 'file.moved', $fileId, ['canonicalPath' => $canonicalPath, 'fileName' => $fileName, 'userId' => $principal['userId']]); $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['success' => true, 'fileId' => $fileId, 'canonicalPath' => $canonicalPath]);
    }
    if ($method === 'PATCH' && preg_match('#^/vaults/([0-9a-f-]{36})/files/path-prefix$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $vaultId = $matches[1];
        if (!is_string($body['oldFolderPath'] ?? null) || !is_string($body['newFolderPath'] ?? null)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'oldFolderPath and newFolderPath are required.']);
        $oldPath = ltrim(str_replace('\\', '/', trim($body['oldFolderPath'])), '/'); $newPath = ltrim(str_replace('\\', '/', trim($body['newFolderPath'])), '/');
        if ($oldPath === '' || $newPath === '' || str_contains($oldPath, '../') || str_contains($newPath, '../')) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'Folder paths must be normalized vault-relative paths.']);
        Runtime::requireVault($db, $principal, $vaultId); $db->beginTransaction();
        try {
            $query = $db->prepare("SELECT id, canonical_path FROM files WHERE vault_id = ? AND organization_id = ? AND deleted_at IS NULL AND LOCATE(CONCAT(?, '/'), canonical_path) = 1 FOR UPDATE"); $query->execute([$vaultId, $principal['organizationId'], $oldPath]); $files = $query->fetchAll();
            $changes = array_map(static fn(array $file): array => ['id' => $file['id'], 'path' => $newPath . substr($file['canonical_path'], strlen($oldPath))], $files);
            if ($changes !== []) {
                $ids = array_column($changes, 'id'); $paths = array_column($changes, 'path'); $placeholders = implode(', ', array_fill(0, count($paths), '?'));
                $collision = $db->prepare("SELECT id FROM files WHERE vault_id = ? AND deleted_at IS NULL AND canonical_path IN ({$placeholders}) FOR UPDATE"); $collision->execute([$vaultId, ...$paths]);
                foreach ($collision->fetchAll() as $row) if (!in_array($row['id'], $ids, true)) { $db->rollBack(); Runtime::respond(409, ['error' => 'PATH_EXISTS', 'message' => 'A file already exists at a destination path.']); }
                $update = $db->prepare('UPDATE files SET canonical_path = ?, file_name = ? WHERE id = ?'); foreach ($changes as $change) $update->execute([$change['path'], basename($change['path']), $change['id']]);
            }
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['success' => true, 'updated' => count($changes), 'total' => count($changes)]);
    }
    if ($method === 'PATCH' && preg_match('#^/files/([0-9a-f-]{36})/state$#i', $path, $matches)) {
        $body = Runtime::jsonBody(); $fileId = $matches[1]; $allowed = ['not_tracked', 'wip', 'in_review', 'released', 'obsolete'];
        if (!is_string($body['state'] ?? null) || !in_array($body['state'], $allowed, true)) Runtime::respond(400, ['error' => 'INVALID_REQUEST', 'message' => 'A valid state is required.']);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT vault_id FROM files WHERE id = ? AND organization_id = ? AND deleted_at IS NULL FOR UPDATE'); $query->execute([$fileId, $principal['organizationId']]); $file = $query->fetch();
            if (!$file) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $file['vault_id']); $assignment = $db->prepare('SELECT file_id FROM file_workflow_assignments WHERE file_id = ? FOR UPDATE'); $assignment->execute([$fileId]);
            if ($assignment->fetch()) { $db->rollBack(); Runtime::respond(409, ['error' => 'WORKFLOW_ASSIGNED', 'message' => 'This file has a workflow. Execute a workflow transition instead.']); }
            $db->prepare('UPDATE files SET state = ? WHERE id = ?')->execute([$body['state'], $fileId]); Runtime::emitEvent($db, $principal['organizationId'], 'file.state_changed', $fileId, ['state' => $body['state'], 'userId' => $principal['userId']]); $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['file' => ['id' => $fileId, 'state' => $body['state']]]);
    }
    if ($method === 'GET' && $path === '/trash') {
        $vaultId = $_GET['vaultId'] ?? null;
        if ($vaultId !== null) Runtime::requireVault($db, $principal, (string)$vaultId);
        $query = $db->prepare(
            'SELECT f.id, f.vault_id AS vaultId, f.canonical_path AS canonicalPath, f.file_name AS fileName, f.current_revision AS currentRevision, f.state,
                    f.content_hash AS contentHash, f.size_bytes AS sizeBytes, f.deleted_at AS deletedAt, f.deleted_by AS deletedBy,
                    u.display_name AS deletedByName, f.updated_at AS updatedAt
             FROM files f LEFT JOIN users u ON u.id = f.deleted_by WHERE f.organization_id = ? AND f.deleted_at IS NOT NULL
             AND (? IS NULL OR f.vault_id = ?) ORDER BY f.deleted_at DESC'
        );
        $query->execute([$principal['organizationId'], $vaultId, $vaultId]); Runtime::respond(200, ['files' => $query->fetchAll()]);
    }
    if ($method === 'POST' && preg_match('#^/files/([0-9a-f-]{36})/(trash|restore)$#i', $path, $matches)) {
        $fileId = $matches[1]; $operation = $matches[2];
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM files WHERE id = ? AND organization_id = ? FOR UPDATE'); $query->execute([$fileId, $principal['organizationId']]); $file = $query->fetch();
            if (!$file) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $file['vault_id']);
            if ($operation === 'trash') {
                if ($file['deleted_at'] !== null) { $db->rollBack(); Runtime::respond(409, ['error' => 'ALREADY_TRASHED', 'message' => 'File is already in trash.']); }
                $lock = $db->prepare('SELECT user_id FROM checkouts WHERE file_id = ? AND expires_at > UTC_TIMESTAMP(3) FOR UPDATE'); $lock->execute([$fileId]); $checkout = $lock->fetch();
                if ($checkout && $checkout['user_id'] !== $principal['userId']) { $db->rollBack(); Runtime::respond(409, ['error' => 'CHECKED_OUT', 'message' => 'File is checked out by another user.']); }
                $db->prepare('UPDATE files SET deleted_at = UTC_TIMESTAMP(3), deleted_by = ? WHERE id = ?')->execute([$principal['userId'], $fileId]);
                Runtime::emitEvent($db, $principal['organizationId'], 'file.trashed', $fileId, ['userId' => $principal['userId']]);
            } else {
                if ($file['deleted_at'] === null) { $db->rollBack(); Runtime::respond(409, ['error' => 'NOT_TRASHED', 'message' => 'File is not in trash.']); }
                $collision = $db->prepare('SELECT id FROM files WHERE vault_id = ? AND canonical_path = ? AND deleted_at IS NULL AND id <> ? FOR UPDATE'); $collision->execute([$file['vault_id'], $file['canonical_path'], $fileId]);
                if ($collision->fetch()) { $db->rollBack(); Runtime::respond(409, ['error' => 'PATH_EXISTS', 'message' => 'A file already exists at this path.']); }
                $db->prepare('UPDATE files SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([$fileId]);
                Runtime::emitEvent($db, $principal['organizationId'], 'file.restored', $fileId, ['userId' => $principal['userId']]);
            }
            $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(200, ['success' => true, 'fileId' => $fileId]);
    }
    if ($method === 'DELETE' && preg_match('#^/files/([0-9a-f-]{36})$#i', $path, $matches)) {
        if (!in_array($principal['role'], ['owner', 'admin'], true)) Runtime::respond(403, ['error' => 'FORBIDDEN', 'message' => 'Administrator access is required.']);
        $fileId = $matches[1];
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT vault_id, deleted_at FROM files WHERE id = ? AND organization_id = ? FOR UPDATE'); $query->execute([$fileId, $principal['organizationId']]); $file = $query->fetch();
            if (!$file) { $db->rollBack(); Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'File not found.']); }
            Runtime::requireVault($db, $principal, $file['vault_id']);
            if ($file['deleted_at'] === null) { $db->rollBack(); Runtime::respond(409, ['error' => 'NOT_TRASHED', 'message' => 'File must be in trash before permanent deletion.']); }
            $db->prepare('DELETE FROM files WHERE id = ?')->execute([$fileId]); $db->commit();
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        Runtime::respond(204);
    }
    Runtime::respond(404, ['error' => 'NOT_FOUND', 'message' => 'API route not found.']);
} catch (Throwable $error) {
    error_log('[BluePLM PHP API] ' . $error->getMessage());
    Runtime::respond(500, ['error' => 'INTERNAL_ERROR', 'message' => 'The API could not process this request.']);
}
