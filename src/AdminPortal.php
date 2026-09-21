<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

/** Server-rendered administration module. Its only public interface is handle(). */
final class AdminPortal
{
    /** @param array<string,string> $env */
    public static function handle(PDO $db, array $env): void
    {
        self::startSession();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') self::post($db, $env);
        $actor = self::actor($db);
        if ($actor === null) { self::loginPage(); return; }
        if (!in_array($actor['role'], ['owner', 'admin'], true)) { http_response_code(403); self::page('No administration access', '<p>Your account does not have an administrative role.</p>'); return; }
        self::dashboard($db, $actor);
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('blueplm_admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }

    /** @param array<string,mixed> $actor */
    private static function dashboard(PDO $db, array $actor): void
    {
        $org = $db->prepare('SELECT o.id,o.name,o.slug,o.phone,o.website,o.contact_email,o.logo_storage_path,s.default_new_user_team_id FROM organizations o LEFT JOIN organization_settings s ON s.organization_id=o.id WHERE o.id=?');
        $org->execute([$actor['organizationId']]); $organization = $org->fetch();
        $teams = $db->prepare('SELECT id,name FROM teams WHERE organization_id=? ORDER BY name'); $teams->execute([$actor['organizationId']]); $teams = $teams->fetchAll();
        $users = $db->prepare('SELECT u.id,u.email,u.display_name,m.role,u.created_at FROM users u JOIN organization_memberships m ON m.user_id=u.id WHERE m.organization_id=? AND u.disabled_at IS NULL ORDER BY u.display_name,u.email'); $users->execute([$actor['organizationId']]); $users = $users->fetchAll();
        $addresses = $db->prepare('SELECT label,address_type,address_line1,postal_code,city,country FROM organization_addresses WHERE organization_id=? ORDER BY address_type,is_default DESC,label'); $addresses->execute([$actor['organizationId']]); $addresses = $addresses->fetchAll();
        $csrf = self::escape((string)$_SESSION['blueplm_admin_csrf']);
        $flash = isset($_SESSION['blueplm_admin_flash']) ? '<p class="notice">' . self::escape((string)$_SESSION['blueplm_admin_flash']) . '</p>' : '';
        unset($_SESSION['blueplm_admin_flash']);
        $teamOptions = '<option value="">No default team</option>';
        foreach ($teams as $team) $teamOptions .= '<option value="' . self::escape($team['id']) . '"' . ($organization['default_new_user_team_id'] === $team['id'] ? ' selected' : '') . '>' . self::escape($team['name']) . '</option>';
        $userRows = '';
        foreach ($users as $user) $userRows .= '<tr><td>' . self::escape($user['display_name']) . '</td><td>' . self::escape($user['email']) . '</td><td>' . self::escape($user['role']) . '</td></tr>';
        $addressRows = $addresses === [] ? '<li>No company address configured.</li>' : implode('', array_map(static fn(array $address): string => '<li>' . self::escape($address['label']) . ' — ' . self::escape($address['address_line1']) . ', ' . self::escape($address['postal_code']) . ' ' . self::escape($address['city']) . ', ' . self::escape($address['country']) . '</li>', $addresses));
        $totp = $db->prepare('SELECT 1 FROM admin_totp_credentials WHERE user_id = ?'); $totp->execute([$actor['userId']]);
        $enrollment = $_SESSION['blueplm_admin_totp_enrollment'] ?? null;
        if (is_string($enrollment)) {
            $uri = Totp::provisioningUri('BluePLM MDB', (string)$actor['displayName'], $enrollment);
            $mfaSection = '<section><h2>Authenticator protection</h2><p>Enter this setup key in an RFC 6238-compatible authenticator app. It is shown only until it is verified.</p><code>' . self::escape($enrollment) . '</code><details><summary>Manual authenticator URI</summary><code>' . self::escape($uri) . '</code></details><form method="post" class="grid"><input type="hidden" name="action" value="confirm-totp-enrollment"><input type="hidden" name="csrf" value="' . $csrf . '"><label>Current six-digit code<input required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" autocomplete="one-time-code"></label><button>Enable authenticator protection</button></form></section>';
        } elseif ($totp->fetchColumn()) {
            $mfaSection = '<section><h2>Authenticator protection</h2><p>Enabled for this administrator.</p><form method="post" class="grid"><input type="hidden" name="action" value="disable-totp"><input type="hidden" name="csrf" value="' . $csrf . '"><label>Current six-digit code<input required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" autocomplete="one-time-code"></label><button>Disable authenticator protection</button></form></section>';
        } else {
            $mfaSection = '<section><h2>Authenticator protection</h2><p>Optional for administrators. Use a time-based code from an authenticator app.</p><form method="post"><input type="hidden" name="action" value="start-totp-enrollment"><input type="hidden" name="csrf" value="' . $csrf . '"><button>Set up authenticator protection</button></form></section>';
        }
        self::page('BluePLM administration', '<header><strong>BluePLM administration</strong><span>' . self::escape($actor['displayName']) . ' (' . self::escape($actor['role']) . ')</span><form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="' . $csrf . '"><button>Log out</button></form></header>' . $flash . '
        <section><h2>Company configuration</h2><form method="post" class="grid"><input type="hidden" name="action" value="save-company"><input type="hidden" name="csrf" value="' . $csrf . '"><label>Company name<input required maxlength="255" name="name" value="' . self::escape($organization['name']) . '"></label><label>Phone<input maxlength="128" name="phone" value="' . self::escape((string)$organization['phone']) . '"></label><label>Website<input type="url" maxlength="2048" name="website" value="' . self::escape((string)$organization['website']) . '"></label><label>Contact email<input type="email" maxlength="320" name="contactEmail" value="' . self::escape((string)$organization['contact_email']) . '"></label><label>Logo path in vault<input maxlength="2048" name="logoStoragePath" value="' . self::escape((string)$organization['logo_storage_path']) . '"></label><label>Default team for new users<select name="defaultNewUserTeamId">' . $teamOptions . '</select></label><button>Save company</button></form><h3>Addresses</h3><ul>' . $addressRows . '</ul></section>
        <section><h2>Users</h2><table><thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead><tbody>' . $userRows . '</tbody></table><h3>Create user</h3><form method="post" class="grid"><input type="hidden" name="action" value="create-user"><input type="hidden" name="csrf" value="' . $csrf . '"><label>Name<input required maxlength="200" name="displayName"></label><label>Email<input required type="email" maxlength="320" name="email"></label><label>Initial password<input required minlength="12" type="password" name="password"></label><label>Role<select name="role"><option value="member">Member</option><option value="admin">Administrator</option>' . ($actor['role'] === 'owner' ? '<option value="owner">Owner</option>' : '') . '</select></label><button>Create user</button></form></section>' . $mfaSection);
    }

    /** @param array<string,string> $env */
    private static function post(PDO $db, array $env): void
    {
        $action = $_POST['action'] ?? '';
        if ($action === 'login') { self::login($db, $env); return; }
        if ($action === 'verify-totp-login') { self::verifyTotpLogin($db, $env); return; }
        $actor = self::actor($db);
        if ($actor === null || !in_array($actor['role'], ['owner', 'admin'], true)) { http_response_code(403); self::page('Forbidden', '<p>Administrative login required.</p>'); exit; }
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals((string)$_SESSION['blueplm_admin_csrf'], $_POST['csrf'])) { http_response_code(403); self::page('Forbidden', '<p>Invalid form token.</p>'); exit; }
        if ($action === 'logout') { $_SESSION = []; session_destroy(); self::redirect('/admin/'); }
        if ($action === 'save-company') self::saveCompany($db, $actor);
        if ($action === 'create-user') self::createUser($db, $actor);
        if ($action === 'start-totp-enrollment') self::startTotpEnrollment();
        if ($action === 'confirm-totp-enrollment') self::confirmTotpEnrollment($db, $env, $actor);
        if ($action === 'disable-totp') self::disableTotp($db, $env, $actor);
        self::redirect('/admin/');
    }

    /** @param array<string,string> $env */
    private static function login(PDO $db, array $env): void
    {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $query = $db->prepare('SELECT u.id,u.password_hash,u.display_name,m.organization_id,m.role FROM users u JOIN organization_memberships m ON m.user_id=u.id WHERE u.email=? AND u.disabled_at IS NULL ORDER BY m.created_at LIMIT 1');
        $query->execute([$email]); $user = $query->fetch();
        if (!$user || !Runtime::passwordVerify($password, $user['password_hash']) || !in_array($user['role'], ['owner', 'admin'], true)) { usleep(250000); self::loginPage('Invalid administrator credentials.'); return; }
        $totp = $db->prepare('SELECT secret_ciphertext FROM admin_totp_credentials WHERE user_id = ?');
        $totp->execute([$user['id']]);
        if ($totp->fetch()) {
            session_regenerate_id(true);
            $_SESSION['blueplm_admin_totp_pending'] = ['userId' => $user['id'], 'organizationId' => $user['organization_id'], 'role' => $user['role'], 'displayName' => $user['display_name'], 'issuedAt' => time()];
            self::loginPage('', true);
            return;
        }
        self::completeLogin($user);
    }

    /** @param array<string,string> $env */
    private static function verifyTotpLogin(PDO $db, array $env): void
    {
        $pending = $_SESSION['blueplm_admin_totp_pending'] ?? null;
        if (!is_array($pending) || !is_string($pending['userId'] ?? null) || !is_int($pending['issuedAt'] ?? null) || time() - $pending['issuedAt'] > 300) {
            unset($_SESSION['blueplm_admin_totp_pending']); self::loginPage('The authenticator challenge expired. Sign in again.'); return;
        }
        $query = $db->prepare('SELECT secret_ciphertext FROM admin_totp_credentials WHERE user_id = ?'); $query->execute([$pending['userId']]); $credential = $query->fetch();
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if (!$credential || !Totp::verify(Runtime::decryptSecret($credential['secret_ciphertext'], $env), $code)) { usleep(250000); self::loginPage('Invalid authenticator code.', true); return; }
        unset($_SESSION['blueplm_admin_totp_pending']); self::completeLogin($pending);
    }

    /** @param array<string,mixed> $user */
    private static function completeLogin(array $user): never
    {
        session_regenerate_id(true);
        $_SESSION['blueplm_admin_actor'] = ['userId' => $user['id'] ?? $user['userId'], 'organizationId' => $user['organization_id'] ?? $user['organizationId'], 'role' => $user['role'], 'displayName' => $user['display_name'] ?? $user['displayName']];
        $_SESSION['blueplm_admin_csrf'] = bin2hex(random_bytes(32));
        self::redirect('/admin/');
    }

    private static function startTotpEnrollment(): void
    {
        $_SESSION['blueplm_admin_totp_enrollment'] = Totp::generateSecret();
        $_SESSION['blueplm_admin_flash'] = 'Add the setup key to an authenticator app, then enter its current six-digit code.';
    }

    /** @param array<string,string> $env @param array<string,mixed> $actor */
    private static function confirmTotpEnrollment(PDO $db, array $env, array $actor): void
    {
        $secret = $_SESSION['blueplm_admin_totp_enrollment'] ?? null;
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if (!is_string($secret) || !Totp::verify($secret, $code)) { $_SESSION['blueplm_admin_flash'] = 'Authenticator code could not be verified.'; return; }
        $db->prepare('INSERT INTO admin_totp_credentials (user_id, secret_ciphertext) VALUES (?, ?) ON DUPLICATE KEY UPDATE secret_ciphertext=VALUES(secret_ciphertext), enabled_at=UTC_TIMESTAMP(3)')
            ->execute([$actor['userId'], Runtime::encryptSecret($secret, $env)]);
        unset($_SESSION['blueplm_admin_totp_enrollment']);
        $_SESSION['blueplm_admin_flash'] = 'Authenticator protection is enabled.';
    }

    /** @param array<string,string> $env @param array<string,mixed> $actor */
    private static function disableTotp(PDO $db, array $env, array $actor): void
    {
        $query = $db->prepare('SELECT secret_ciphertext FROM admin_totp_credentials WHERE user_id = ?'); $query->execute([$actor['userId']]); $credential = $query->fetch();
        $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
        if (!$credential || !Totp::verify(Runtime::decryptSecret($credential['secret_ciphertext'], $env), $code)) { $_SESSION['blueplm_admin_flash'] = 'Authenticator code could not be verified.'; return; }
        $db->prepare('DELETE FROM admin_totp_credentials WHERE user_id = ?')->execute([$actor['userId']]);
        $_SESSION['blueplm_admin_flash'] = 'Authenticator protection is disabled.';
    }

    /** @param array<string,mixed> $actor */
    private static function saveCompany(PDO $db, array $actor): void
    {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['contactEmail'] ?? null) ? trim($_POST['contactEmail']) : '';
        $website = is_string($_POST['website'] ?? null) ? trim($_POST['website']) : '';
        if ($name === '' || strlen($name) > 255 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL))) { $_SESSION['blueplm_admin_flash'] = 'Company data is invalid.'; return; }
        $teamId = is_string($_POST['defaultNewUserTeamId'] ?? null) && $_POST['defaultNewUserTeamId'] !== '' ? $_POST['defaultNewUserTeamId'] : null;
        if ($teamId !== null) { $team = $db->prepare('SELECT id FROM teams WHERE id=? AND organization_id=?'); $team->execute([$teamId, $actor['organizationId']]); if (!$team->fetch()) { $_SESSION['blueplm_admin_flash'] = 'Default team is invalid.'; return; } }
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE organizations SET name=?,phone=?,website=?,contact_email=?,logo_storage_path=? WHERE id=?')->execute([$name, self::text('phone', 128), $website ?: null, $email ?: null, self::text('logoStoragePath', 2048), $actor['organizationId']]);
            $db->prepare('INSERT INTO organization_settings (organization_id,default_new_user_team_id) VALUES (?,?) ON DUPLICATE KEY UPDATE default_new_user_team_id=VALUES(default_new_user_team_id)')->execute([$actor['organizationId'], $teamId]);
            Runtime::emitEvent($db, $actor['organizationId'], 'organization.profile_updated', $actor['organizationId'], ['userId' => $actor['userId']]);
            $db->commit(); $_SESSION['blueplm_admin_flash'] = 'Company configuration saved.';
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    /** @param array<string,mixed> $actor */
    private static function createUser(PDO $db, array $actor): void
    {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $name = is_string($_POST['displayName'] ?? null) ? trim($_POST['displayName']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : 'member';
        if ($role === 'owner' && $actor['role'] !== 'owner') $role = 'member';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 200 || strlen($password) < 12 || !in_array($role, ['owner','admin','member'], true)) { $_SESSION['blueplm_admin_flash'] = 'User data is invalid.'; return; }
        $id = Runtime::uuid();
        try { $db->beginTransaction(); $db->prepare('INSERT INTO users (id,email,display_name,password_hash) VALUES (?,?,?,?)')->execute([$id,$email,$name,Runtime::passwordHash($password)]); $db->prepare('INSERT INTO organization_memberships (organization_id,user_id,role) VALUES (?,?,?)')->execute([$actor['organizationId'],$id,$role]); Runtime::emitEvent($db,$actor['organizationId'],'user.created',$id,['userId'=>$id,'createdBy'=>$actor['userId']]); $db->commit(); $_SESSION['blueplm_admin_flash'] = 'User created.'; } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); $_SESSION['blueplm_admin_flash'] = 'User could not be created.'; }
    }

    /** @return array<string,mixed>|null */
    private static function actor(PDO $db): ?array
    {
        $stored = $_SESSION['blueplm_admin_actor'] ?? null;
        if (!is_array($stored) || !is_string($stored['userId'] ?? null) || !is_string($stored['organizationId'] ?? null)) return null;
        $query = $db->prepare('SELECT u.display_name,m.role FROM users u JOIN organization_memberships m ON m.user_id=u.id WHERE u.id=? AND m.organization_id=? AND u.disabled_at IS NULL'); $query->execute([$stored['userId'],$stored['organizationId']]); $current = $query->fetch();
        if (!$current) { unset($_SESSION['blueplm_admin_actor']); return null; }
        return ['userId'=>$stored['userId'],'organizationId'=>$stored['organizationId'],'role'=>$current['role'],'displayName'=>$current['display_name']];
    }

    private static function text(string $name, int $max): ?string { $value = is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : ''; return $value === '' ? null : substr($value, 0, $max); }
    private static function loginPage(string $error = '', bool $totp = false): never { self::page('BluePLM administration', ($error === '' ? '' : '<p class="error">' . self::escape($error) . '</p>') . ($totp ? '<form method="post" class="login"><input type="hidden" name="action" value="verify-totp-login"><label>Authenticator code<input required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" autocomplete="one-time-code"></label><button>Verify</button></form>' : '<form method="post" class="login"><input type="hidden" name="action" value="login"><label>Email<input required type="email" name="email" autocomplete="username"></label><label>Password<input required type="password" name="password" autocomplete="current-password"></label><button>Sign in</button></form>')); exit; }
    private static function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function page(string $title, string $content): void { $language = Locale::code(); $title = Locale::translate($title); $content = Locale::translate($content); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html lang="' . $language . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::escape($title) . '</title><style>body{font:15px system-ui;max-width:1000px;margin:2rem auto;background:#101827;color:#e5e7eb;padding:0 1rem}.locale{text-align:right}.locale a{color:#93c5fd}header{display:flex;gap:1rem;align-items:center;justify-content:space-between}section,.login{background:#1f2937;padding:1.25rem;border-radius:.6rem;margin:1rem 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem}label{display:grid;gap:.3rem}input,select,button{font:inherit;padding:.55rem;border-radius:.35rem;border:1px solid #4b5563;background:#111827;color:inherit}button{cursor:pointer;background:#2563eb;border:0}table{width:100%;border-collapse:collapse}td,th{padding:.5rem;text-align:left;border-bottom:1px solid #4b5563}.notice{background:#14532d;padding:.75rem}.error{background:#7f1d1d;padding:.75rem}</style><body>' . Locale::picker('/admin/') . '<h1>' . self::escape($title) . '</h1>' . $content . '</body></html>'; }
}
