# BluePLM Community auf All-Inkl bereitstellen

Der Desktop-Client verbindet sich ausschlieÃŸlich mit der HTTPS-PHP-API. MariaDB
bleibt intern auf `localhost` des Webspace und ist kein Client-Endpunkt.

## 1. Verzeichnis und Domain

Per FTP den gesamten Inhalt dieses Ordners in ein privates Projektverzeichnis
laden, beispielsweise `blueplm-community-php/`. Die Struktur muss erhalten
bleiben:

```
blueplm-community-php/
  .env                 # nur auf dem Server, niemals im Webroot
  bin/
  migrations/
  src/
  public/              # einziger Webroot
```

Im KAS der Domain `blueplm.worxs3d.de` als Document Root genau
`blueplm-community-php/public` festlegen. Das verhindert den Abruf von
Migrationen, Quellcode und Zugangsdaten. Die Datei `public/.htaccess` muss
mit hochgeladen werden; sie aktiviert sichere API-Routen wie `/health` und
reicht den Bearer-Token an PHP weiter.

## 2. Server-Konfiguration und Datenbank

Lege auf dem Server `blueplm-community-php/.env` an. Als Vorlage dient
`.env.example`. Bei Nutzung der gemeinsamen Projekt-`.env` werden die Werte
aus `[Mariadb]` automatisch gelesen. Erforderlich sind insbesondere:

```
MARIADB_HOST=localhost
MARIADB_DATABASE=<All-Inkl-Datenbankname>
MARIADB_USER=<All-Inkl-Datenbankbenutzer>
MARIADB_PASSWORD=<Datenbankpasswort>
BLUEPLM_SESSION_SECRET=<mindestens 32 zufÃ¤llige Zeichen>
BLUEPLM_BOOTSTRAP_TOKEN=<anderes Geheimnis mit mindestens 32 Zeichen>
BLUEPLM_MAINTENANCE_TOKEN=<anderes Geheimnis mit mindestens 32 Zeichen>
BLUEPLM_CORS_ORIGINS=null,file://,http://localhost:5173
```

Die drei BluePLM-Secrets erzeugst du vor dem Upload lokal mit
`node scripts/generate-secrets.mjs`. Die Ausgabe ausschließlich in die private
Server-`.env` kopieren, niemals in den Webroot, den Client oder ein Git-Repository.

Auf Shared Hosting ohne SSH werden Migrationen mit dem separaten Wartungs-Token
ausgefÃ¼hrt:

```powershell
Invoke-RestMethod -Method Post -Uri 'https://blueplm.worxs3d.de/admin/migrate' `
  -ContentType 'application/json' -Body '{"maintenanceToken":"<Wartungs-Token>"}'
```

Der Endpunkt gibt nur die ausgefÃ¼hrten Migrationsdateien zurÃ¼ck. Es gibt keine
MariaDB-Zugangsdaten aus. AnschlieÃŸend muss dies funktionieren:

```powershell
Invoke-RestMethod 'https://blueplm.worxs3d.de/health'
```

Erwartet wird `ok: true`, `runtime: php` und `supabase: false`.

## 3. Geführte Server-Ersteinrichtung

Nach dem Upload öffne `https://<deine-domain>/setup/`. Der Assistent führt die
Migrationen aus und legt Firma, ersten Eigentümer sowie einen optionalen
Netzwerk-/NAS-Vault an. Der Bootstrap-Token wird nur dort eingegeben und nie an
den Desktop-Client übermittelt. Optional richtet der Assistent ein TOTP-Secret
für Authenticator-Apps ein. Nach erfolgreichem Abschluss ist `/setup/` dauerhaft
gesperrt; den Bootstrap-Token anschließend aus der privaten `.env` entfernen.
Der davon getrennte Wartungs-Token bleibt ausschließlich für spätere
Schema-Updates auf dem Deployment-Rechner und dem Server.

## 4. Ersteinrichtung von Client und Vault

Auf dem ersten Windows-Client BluePLM starten und in der Backend-Auswahl **MDB
(MariaDB/PHP)** wählen. Anschließend die URL
`https://blueplm.worxs3d.de` eintragen. Kein `/public`, kein `localhost` und
keine MariaDB-URL eintragen.

Der erste Administrator kann mit der CLI eingerichtet werden:

```powershell
blueplm-community configure --server https://blueplm.worxs3d.de --workspace C:\BluePLM-Work
blueplm-community bootstrap --token <Bootstrap-Token> --organization 'Worxs3D' --slug worxs3d --email <Admin-E-Mail> --name <Admin-Name>
blueplm-community vault-add --name 'Konstruktionsvault' --network-root '\\server\freigabe\BluePLM-Vault'
blueplm-community vault-import <Vault-ID>
```

Jeder Benutzer bekommt ein eigenes lokales Arbeitsverzeichnis, etwa
`C:\BluePLM-Work`. Der Netzwerk-Vault muss für diese Benutzer lesbar und
schreibbar sein. Checkout kopiert in das lokale Verzeichnis; Check-in legt eine
unverÃ¤nderliche Revision unter `.blueplm/revisions/` im Netzwerk-Vault an und
schreibt die Revisionsmetadaten atomar in MariaDB.
