# BluePLM MDB auf Shared Hosting bereitstellen

Der BluePLM-Desktop-Client verbindet sich ausschließlich per HTTPS mit der
PHP-API. MariaDB bleibt intern beim Hoster und ist kein Client-Endpunkt. Die
Ersteinrichtung und Administration erfolgen in BluePLM; der Server stellt keine
Browser-Adminoberfläche bereit.

## 1. Hosting vorbereiten

Beim Hoster werden benötigt:

- eine leere oder bereits von BluePLM verwendete MariaDB-Datenbank,
- ein FTP-Benutzer mit FTPS-Unterstützung,
- eine Domain oder Subdomain mit gültigem TLS-Zertifikat,
- PHP 8.2 oder neuer mit PDO MySQL und OpenSSL.

Der Dokumentenstamm der Domain muss auf den Ordner `public/` innerhalb des
BluePLM-MDB-Serverpakets zeigen. `.env`, `src/` und `migrations/` liegen eine
Ebene darüber und dürfen nicht öffentlich erreichbar sein.

## 2. Einrichtung in BluePLM starten

In der Backend-Auswahl **BluePLM MDB** und anschließend **Neuen MDB-Server
einrichten** wählen. Einzutragen sind:

- die öffentliche HTTPS-URL ohne `/public`,
- die FTPS-Server-URL einschließlich Port,
- optional der FTP-Zielordner; leer bedeutet Wurzel des FTP-Benutzers,
- FTP-Benutzer und FTP-Passwort,
- MariaDB-Host, Port, Datenbankname, Benutzer und Passwort.

Unterstützt werden explizites FTPS auf Port 21 und implizites FTPS auf Port 990.
Die im Client gewählte Verbindungsart muss zum angegebenen Port passen.
Unverschlüsseltes FTP wird nicht akzeptiert. TLS-Zertifikat und Hostname werden
bei beiden Varianten geprüft. Zugangsdaten erscheinen weder in der Prozessliste
noch in den Anwendungslogs.

## 3. Vorhandene Datenbank prüfen

Vor jeder Installation prüft BluePLM den ausgewählten Datenbankstand:

- **Leer:** Neuinstallation ist möglich.
- **Versionierte BluePLM-Datenbank:** BluePLM fragt, ob die vorhandenen Daten
  migriert oder vollständig gelöscht werden sollen.
- **Altes BluePLM-Schema oder fremde Tabellen:** Eine automatische Migration
  wird aus Sicherheitsgründen nicht angeboten. Möglich ist nur Löschen und
  Neuinstallation.

Die Löschoption entfernt alle Tabellen und Ansichten der ausgewählten
Datenbank. Sie wird erst freigeschaltet, nachdem exakt
`DELETE ALL DATABASE DATA` eingegeben wurde. Unmittelbar vor der Ausführung
prüft der Server den Zustand erneut.

Bei einer Migration bleiben bestehende Benutzer, Firmen, Teams, Vaults und
Dateimetadaten erhalten. Bei einer bereits eingerichteten Installation bleibt
auch die vorhandene private `.env` unverändert.

## 4. Firma und ersten Eigentümer anlegen

Bei einer Neuinstallation oder einer noch nicht eingerichteten Datenbank werden
Firmenname, Firmenkürzel, Eigentümername, E-Mail, Passwort und optional der
Netzwerk-Vault direkt in der Desktop-App eingegeben. Das Firmenkürzel darf nur
Kleinbuchstaben, Ziffern und Bindestriche enthalten. Das Eigentümerpasswort muss
mindestens zwölf Zeichen lang sein.

Die Werte werden ausschließlich an die mit einem kurzlebigen Installationstoken
geschützte HTTPS-Schnittstelle gesendet. Nach erfolgreichem Abschluss werden
Installationstoken und Bootstrap-Token entfernt. Die temporäre `.env.install`
wird atomar aktiviert oder bei einem Fehler gelöscht.

## 5. Prüfung

Nach der Einrichtung muss der öffentliche Health-Check eine MDB-Laufzeit melden:

```powershell
Invoke-RestMethod 'https://blueplm.example/health'
```

Erwartet werden `ok: true`, `runtime: php` und `supabase: false`. Anschließend
öffnet BluePLM die Anmeldung beziehungsweise übernimmt bei einer Neuinstallation
die einmalig ausgestellte Sitzung.

Keine realen Domains, IP-Adressen, Datenbanknamen, Kennwörter, Tokens oder
Kundendaten in GitHub-Issues, Logs oder Screenshots veröffentlichen.
