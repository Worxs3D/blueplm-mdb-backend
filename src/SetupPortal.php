<?php
declare(strict_types=1);

namespace BluePlm;

use PDO;

/** One-time browser setup for a prepared PHP/MariaDB host. */
final class SetupPortal
{
    /** @param array<string, string> $env */
    public static function handle(PDO $db, array $env, string $migrationDirectory, string $environmentPath): void
    {
        self::startSession();
        if (Installation::isComplete($db)) { http_response_code(404); self::page('Setup unavailable', '<p>This BluePLM MDB installation is already configured.</p>'); return; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') self::submit($db, $env, $migrationDirectory, $environmentPath);
        self::form();
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('blueplm_setup');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/setup', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $_SESSION['blueplm_setup_csrf'] ??= bin2hex(random_bytes(32));
    }

    /** @param array<string, string> $env */
    private static function submit(PDO $db, array $env, string $migrationDirectory, string $environmentPath): never
    {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals((string)$_SESSION['blueplm_setup_csrf'], $_POST['csrf'])) self::fail('The setup form expired. Reload the page and try again.');
        try {
            Installation::requireBootstrapToken((string)($_POST['bootstrapToken'] ?? ''), $env);
            Migrator::apply($db, $migrationDirectory);
            $result = Installation::bootstrap($db, $env, [
                'organizationName' => (string)($_POST['organizationName'] ?? ''),
                'organizationSlug' => (string)($_POST['organizationSlug'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'displayName' => (string)($_POST['displayName'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
                'vaultName' => (string)($_POST['vaultName'] ?? ''),
                'networkRoot' => (string)($_POST['networkRoot'] ?? ''),
                'storageProvider' => (string)($_POST['storageProvider'] ?? 'network'),
                'googleDriveFolderId' => (string)($_POST['googleDriveFolderId'] ?? ''),
                'enableTotp' => isset($_POST['enableTotp']),
            ]);
            session_regenerate_id(true);
            $tokenRetired = Installation::retireBootstrapToken($environmentPath);
            $message = $tokenRetired
                ? '<p>Installation complete. The bootstrap token was automatically removed from the private environment file.</p>'
                : '<p>Installation complete. The setup endpoint is now permanently disabled. The bootstrap token could not be removed automatically because the private environment file is read-only or externally managed; remove or rotate it manually.</p>';
            if ($result['totpSecret'] !== null) {
                $uri = Totp::provisioningUri('BluePLM MDB', (string)($_POST['email'] ?? ''), $result['totpSecret']);
                $message .= '<p><strong>Authenticator setup key (showing once):</strong><br><code>' . self::escape($result['totpSecret']) . '</code></p>'
                    . '<p>Add it as a time-based, six-digit token in your authenticator application, then sign in to the admin portal.</p>'
                    . '<details><summary>Manual authenticator URI</summary><code>' . self::escape($uri) . '</code></details>';
            }
            $message .= '<p><a href="/admin/">Open the administration portal</a></p>';
            self::page('BluePLM MDB setup complete', $message);
            exit;
        } catch (\Throwable $error) {
            error_log('[BluePLM Setup] ' . $error->getMessage());
            if ($error instanceof \InvalidArgumentException) self::fail($error->getMessage());
            self::fail('Setup could not be completed. Verify the token, database configuration, and entered values.');
        }
    }

    private static function form(): never
    {
        $csrf = self::escape((string)$_SESSION['blueplm_setup_csrf']);
        $requestedNetworkRoot = $_GET['vaultPath'] ?? '';
        if (is_string($requestedNetworkRoot) && strlen($requestedNetworkRoot) <= 1024 && !preg_match('/[\r\n\0]/', $requestedNetworkRoot)) {
            $networkRootJson = json_encode($requestedNetworkRoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($networkRootJson)) {
                register_shutdown_function(static function () use ($networkRootJson): void {
                    echo '<script>(function(){const field=document.querySelector(\'input[name="networkRoot"]\');if(field)field.value=' . $networkRootJson . ';})();</script>';
                });
            }
        }
        $requestedGoogleDriveFolderId = $_GET['googleDriveFolderId'] ?? '';
        if (is_string($requestedGoogleDriveFolderId) && strlen($requestedGoogleDriveFolderId) <= 512 && !preg_match('/[\r\n\0]/', $requestedGoogleDriveFolderId)) {
            $googleDriveFolderIdJson = json_encode($requestedGoogleDriveFolderId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($googleDriveFolderIdJson)) {
                register_shutdown_function(static function () use ($googleDriveFolderIdJson): void {
                    echo '<script>(function(){const field=document.querySelector(\'input[name="googleDriveFolderId"]\');const radio=document.querySelector(\'input[name="storageProvider"][value="google_drive"]\');if(field)field.value=' . $googleDriveFolderIdJson . ';if(radio){radio.checked=true;radio.dispatchEvent(new Event(\'change\'));}})();</script>';
                });
            }
        }
        $content = '<p>This page is available only until the first owner account is created. Database and deployment secrets stay in the private server environment file.</p>'
            . '<p class="hint">Enter only <code>BLUEPLM_BOOTSTRAP_TOKEN</code> below. The session secret and maintenance token remain on the server and must be stored securely.</p>'
            . '<form method="post" class="grid"><input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<label>Bootstrap token<input required type="password" name="bootstrapToken" autocomplete="off"></label><label>Company name<input required maxlength="200" name="organizationName"></label><label>Company slug<input required pattern="[a-z0-9-]{2,100}" maxlength="100" name="organizationSlug" placeholder="example-company"></label><label>Owner name<input required maxlength="200" name="displayName"></label><label>Owner email<input required type="email" maxlength="320" name="email"></label><label>Owner password<input required type="password" minlength="12" name="password" autocomplete="new-password"></label><label>Vault name<input maxlength="200" name="vaultName" placeholder="Engineering vault"></label>'
            . '<fieldset class="provider"><legend>Vault storage</legend><label class="check"><input checked type="radio" name="storageProvider" value="network"> Archive/NAS network path</label><label class="check"><input type="radio" name="storageProvider" value="google_drive"> Google Drive Shared Drive folder</label></fieldset>'
            . '<label data-provider="network">Archive/NAS path<input maxlength="1024" name="networkRoot" placeholder="\\\\server\\share\\BluePLM"></label><label data-provider="google_drive" hidden>Google Drive folder ID<input maxlength="512" name="googleDriveFolderId" placeholder="Shared Drive folder ID"></label><label class="check"><input type="checkbox" name="enableTotp" value="1"> Require an authenticator app for this owner</label><button>Complete secure setup</button></form>'
            . '<p class="hint">Choose one primary vault now. Additional Network or Google Drive vaults can be added later in BluePLM. Google Drive uses each Windows userâ€™s own OAuth connection and Shared Drive membership.</p>'
            . '<script>(function(){const radios=document.querySelectorAll(\'input[name="storageProvider"]\');const network=document.querySelector(\'[data-provider="network"]\');const drive=document.querySelector(\'[data-provider="google_drive"]\');const networkInput=document.querySelector(\'input[name="networkRoot"]\');const driveInput=document.querySelector(\'input[name="googleDriveFolderId"]\');const update=()=>{const google=[...radios].some(r=>r.checked&&r.value===\'google_drive\');network.hidden=google;drive.hidden=!google;networkInput.required=!google;driveInput.required=google;};radios.forEach(r=>r.addEventListener(\'change\',update));update();})();</script>';
        self::page('BluePLM MDB initial setup', $content);
        exit;
        self::page('BluePLM MDB initial setup', '<p>This page is available only until the first owner account is created. Database and deployment secrets stay in the private server environment file.</p><p class="hint">Enter only <code>BLUEPLM_BOOTSTRAP_TOKEN</code> below. The session secret and maintenance token remain on the server and must be stored securely.</p><form method="post" class="grid"><input type="hidden" name="csrf" value="' . $csrf . '"><label>Bootstrap token<input required type="password" name="bootstrapToken" autocomplete="off"></label><label>Company name<input required maxlength="200" name="organizationName"></label><label>Company slug<input required pattern="[a-z0-9-]{2,100}" maxlength="100" name="organizationSlug" placeholder="example-company"></label><label>Owner name<input required maxlength="200" name="displayName"></label><label>Owner email<input required type="email" maxlength="320" name="email"></label><label>Owner password<input required type="password" minlength="12" name="password" autocomplete="new-password"></label><label>Archive/NAS vault name<input maxlength="200" name="vaultName" placeholder="Engineering vault"></label><label>Archive/NAS path<input maxlength="1024" name="networkRoot" placeholder="\\\\server\\share\\BluePLM"></label><label class="check"><input type="checkbox" name="enableTotp" value="1"> Require an authenticator app for this owner</label><button>Complete secure setup</button></form><p class="hint">The NAS path is metadata for connected Windows clients. Access credentials remain in each client’s Windows Credential Manager.</p>');
        exit;
    }

    private static function fail(string $message): never { http_response_code(400); self::page('Setup could not continue', '<p class="error">' . self::escape($message) . '</p><p><a href="/setup/">Back to setup</a></p>'); exit; }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function page(string $title, string $content): void { $language = Locale::code(); $title = Locale::translate($title); $content = Locale::translate($content); header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); echo '<!doctype html><html lang="' . $language . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::escape($title) . '</title><style>body{font:15px system-ui;max-width:820px;margin:2rem auto;background:#101827;color:#e5e7eb;padding:0 1rem}.locale{text-align:right}.locale a{color:#93c5fd}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;background:#1f2937;padding:1.25rem;border-radius:.6rem}label{display:grid;gap:.35rem}.check{display:flex;align-items:center;gap:.6rem}input,button{font:inherit;padding:.6rem;border-radius:.35rem;border:1px solid #4b5563;background:#111827;color:inherit}button{cursor:pointer;background:#2563eb;border:0}.error{background:#7f1d1d;padding:.75rem}.hint{color:#9ca3af}code{word-break:break-all}</style><body>' . Locale::picker('/setup/') . '<h1>' . self::escape($title) . '</h1>' . $content . '</body></html>'; }
}
