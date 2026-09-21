<?php
declare(strict_types=1);

namespace BluePlm;

/**
 * Small server-side locale boundary for the setup and administration portals.
 * The API itself remains locale-neutral; clients localize their own UI.
 */
final class Locale
{
    private const COOKIE = 'blueplm_locale';

    public static function code(): string
    {
        $requested = $_GET['lang'] ?? $_COOKIE[self::COOKIE] ?? '';
        if (is_string($requested) && in_array($requested, ['de', 'en'], true)) {
            if (isset($_GET['lang'])) setcookie(self::COOKIE, $requested, ['expires' => time() + 31536000, 'path' => '/', 'secure' => self::https(), 'httponly' => true, 'samesite' => 'Lax']);
            return $requested;
        }
        return str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'de') ? 'de' : 'en';
    }

    public static function picker(string $path): string
    {
        $current = self::code();
        $separator = str_contains($path, '?') ? '&' : '?';
        $de = $current === 'de' ? '<strong>Deutsch</strong>' : '<a href="' . self::escape($path . $separator . 'lang=de') . '">Deutsch</a>';
        $en = $current === 'en' ? '<strong>English</strong>' : '<a href="' . self::escape($path . $separator . 'lang=en') . '">English</a>';
        return '<nav class="locale" aria-label="Language">' . $de . ' · ' . $en . '</nav>';
    }

    public static function translate(string $value): string
    {
        $value = str_replace('Company slug', 'Company slug (lowercase only)', $value);
        if (self::code() !== 'de') return $value;
        return strtr($value, self::german());
    }

    private static function https(): bool { return (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** @return array<string,string> */
    private static function german(): array
    {
        return [
            'Enter only <code>BLUEPLM_BOOTSTRAP_TOKEN</code> below. The session secret and maintenance token remain on the server and must be stored securely.' => 'Trage unten nur den <code>BLUEPLM_BOOTSTRAP_TOKEN</code> ein. Das Sitzungsgeheimnis und der Wartungs-Token bleiben auf dem Server und m&uuml;ssen sicher verwahrt werden.',
            'Company name is required and must not exceed 200 characters.' => 'Der Firmenname ist erforderlich und darf höchstens 200 Zeichen lang sein.',
            'Company slug must use 2–100 lowercase letters, digits, or hyphens (for example: doener).' => 'Das Firmen-Kürzel muss 2–100 Kleinbuchstaben, Ziffern oder Bindestriche enthalten (zum Beispiel: doener).',
            'Enter a valid owner email address.' => 'Gib eine gültige E-Mail-Adresse des Eigentümers ein.',
            'Owner name is required and must not exceed 200 characters.' => 'Der Name des Eigentümers ist erforderlich und darf höchstens 200 Zeichen lang sein.',
            'Owner password must contain at least 12 characters.' => 'Das Passwort des Eigentümers muss mindestens 12 Zeichen enthalten.',
            'Company slug (lowercase only)' => 'Firmen-Kürzel (nur Kleinbuchstaben)',
            'Installation complete. The bootstrap token was automatically removed from the private environment file.' => 'Einrichtung abgeschlossen. Der Einrichtungs-Token wurde automatisch aus der privaten Umgebungsdatei entfernt.',
            'Installation complete. The setup endpoint is now permanently disabled. The bootstrap token could not be removed automatically because the private environment file is read-only or externally managed; remove or rotate it manually.' => 'Einrichtung abgeschlossen. Der Setup-Endpunkt ist dauerhaft deaktiviert. Der Einrichtungs-Token konnte nicht automatisch entfernt werden, weil die private Umgebungsdatei schreibgeschützt oder extern verwaltet wird; entferne oder ersetze ihn manuell.',
            'Enter only <code>BLUEPLM_BOOTSTRAP_TOKEN</code> below. The session secret and maintenance token remain on the server and must be stored securely.' => 'Trage unten nur den <code>BLUEPLM_BOOTSTRAP_TOKEN</code> ein. Das Sitzungsgeheimnis und der Wartungs-Token bleiben auf dem Server und m&uuml;ssen sicher verwahrt werden.',
            'BluePLM MDB initial setup' => 'BluePLM MDB Ersteinrichtung', 'BluePLM MDB setup complete' => 'BluePLM MDB Einrichtung abgeschlossen',
            'Setup unavailable' => 'Einrichtung nicht verfügbar', 'Setup could not continue' => 'Einrichtung konnte nicht fortgesetzt werden',
            'This BluePLM MDB installation is already configured.' => 'Diese BluePLM-MDB-Installation ist bereits eingerichtet.',
            'This page is available only until the first owner account is created. Database and deployment secrets stay in the private server environment file.' => 'Diese Seite ist nur verfügbar, bis das erste Eigentümerkonto angelegt wurde. Datenbank- und Deployment-Geheimnisse bleiben in der privaten Server-Umgebungsdatei.',
            'Bootstrap token' => 'Einrichtungs-Token', 'Company name' => 'Firmenname', 'Company slug' => 'Firmen-Kürzel', 'Owner name' => 'Name des Eigentümers', 'Owner email' => 'E-Mail des Eigentümers', 'Owner password' => 'Passwort des Eigentümers',
            'Archive/NAS vault name' => 'Name des Archiv-/NAS-Vaults', 'Archive/NAS path' => 'Archiv-/NAS-Pfad', 'Require an authenticator app for this owner' => 'Authenticator-App für diesen Eigentümer verlangen', 'Complete secure setup' => 'Sichere Einrichtung abschließen',
            'The NAS path is metadata for connected Windows clients. Access credentials remain in each client’s Windows Credential Manager.' => 'Der NAS-Pfad ist Metadaten für verbundene Windows-Clients. Zugangsdaten bleiben im Windows-Anmeldeinformationsmanager jedes Clients.',
            'Optional hosting recommendation' => 'Optionale Hosting-Empfehlung', 'Need a PHP/MariaDB web host?' => 'PHP/MariaDB-Webhosting benötigt?', 'This is an affiliate link. BluePLM MDB works with any suitable PHP/MariaDB host; choosing this provider is entirely optional.' => 'Dies ist ein Affiliate-Link. BluePLM MDB funktioniert mit jedem geeigneten PHP/MariaDB-Hoster; dieser Anbieter ist vollkommen optional.',
            'Installation complete. Remove or rotate the bootstrap token in the private environment file.' => 'Einrichtung abgeschlossen. Entferne oder ersetze den Einrichtungs-Token in der privaten Umgebungsdatei.', 'Authenticator setup key (showing once):' => 'Authenticator-Einrichtungsschlüssel (wird nur einmal angezeigt):', 'Add it as a time-based, six-digit token in your authenticator application, then sign in to the admin portal.' => 'Füge ihn als zeitbasierten sechsstelligen Token in deiner Authenticator-App hinzu und melde dich dann im Adminbereich an.', 'Manual authenticator URI' => 'Manuelle Authenticator-URI', 'Open the administration portal' => 'Adminbereich öffnen',
            'BluePLM administration' => 'BluePLM Verwaltung', 'No administration access' => 'Kein Verwaltungszugriff', 'Your account does not have an administrative role.' => 'Dein Konto hat keine administrative Rolle.', 'Forbidden' => 'Verboten', 'Administrative login required.' => 'Administrative Anmeldung erforderlich.', 'Invalid form token.' => 'Ungültiges Formular-Token.',
            'Log out' => 'Abmelden', 'Company configuration' => 'Firmenkonfiguration', 'Phone' => 'Telefon', 'Website' => 'Webseite', 'Contact email' => 'Kontakt-E-Mail', 'Logo path in vault' => 'Logo-Pfad im Vault', 'Default team for new users' => 'Standardteam für neue Benutzer', 'No default team' => 'Kein Standardteam', 'Save company' => 'Firma speichern', 'Addresses' => 'Adressen', 'No company address configured.' => 'Keine Firmenadresse konfiguriert.',
            'Users' => 'Benutzer', 'Name' => 'Name', 'Email' => 'E-Mail', 'Role' => 'Rolle', 'Create user' => 'Benutzer anlegen', 'Initial password' => 'Initiales Passwort', 'Member' => 'Mitglied', 'Administrator' => 'Administrator', 'Owner' => 'Eigentümer',
            'Authenticator protection' => 'Authenticator-Schutz', 'Enter this setup key in an RFC 6238-compatible authenticator app. It is shown only until it is verified.' => 'Gib diesen Einrichtungsschlüssel in eine RFC-6238-kompatible Authenticator-App ein. Er wird nur bis zur Bestätigung angezeigt.', 'Current six-digit code' => 'Aktueller sechsstelliger Code', 'Enable authenticator protection' => 'Authenticator-Schutz aktivieren', 'Enabled for this administrator.' => 'Für diesen Administrator aktiviert.', 'Disable authenticator protection' => 'Authenticator-Schutz deaktivieren', 'Optional for administrators. Use a time-based code from an authenticator app.' => 'Optional für Administratoren. Verwende einen zeitbasierten Code aus einer Authenticator-App.', 'Set up authenticator protection' => 'Authenticator-Schutz einrichten',
            'Invalid administrator credentials.' => 'Ungültige Administrator-Zugangsdaten.', 'The authenticator challenge expired. Sign in again.' => 'Die Authenticator-Abfrage ist abgelaufen. Bitte erneut anmelden.', 'Invalid authenticator code.' => 'Ungültiger Authenticator-Code.', 'Password' => 'Passwort', 'Sign in' => 'Anmelden', 'Verify' => 'Bestätigen',
            'Add the setup key to an authenticator app, then enter its current six-digit code.' => 'Füge den Einrichtungsschlüssel in einer Authenticator-App hinzu und gib dann den aktuellen sechsstelligen Code ein.', 'Authenticator code could not be verified.' => 'Authenticator-Code konnte nicht bestätigt werden.', 'Authenticator protection is enabled.' => 'Authenticator-Schutz ist aktiviert.', 'Authenticator protection is disabled.' => 'Authenticator-Schutz ist deaktiviert.', 'Company data is invalid.' => 'Firmendaten sind ungültig.', 'Default team is invalid.' => 'Standardteam ist ungültig.', 'Company configuration saved.' => 'Firmenkonfiguration gespeichert.', 'User data is invalid.' => 'Benutzerdaten sind ungültig.', 'User created.' => 'Benutzer angelegt.', 'User could not be created.' => 'Benutzer konnte nicht angelegt werden.',
        ];
    }
}
