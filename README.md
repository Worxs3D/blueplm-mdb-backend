# BluePLM MDB PHP API

Provider-neutral PHP/MariaDB runtime for BluePLM MDB. Configure the domain's
document root as `blueplm-community-php/public`, not as the project directory.
The parent directory holds `.env`, source code and SQL migrations and must not
be web-accessible.

The API reads JSON, returns JSON and authenticates clients with opaque bearer
tokens. MariaDB credentials are used only by PHP through PDO; they are never
returned to Electron.

For a standalone upload, copy `.env.example` to the private parent directory
and populate it. In this workspace, the PHP adapter can instead use the
existing root `.env`: its `[Mariadb]` section is mapped to `MARIADB_*` without
reading the `[webspaceFTP]` section. In either case,
`BLUEPLM_SESSION_SECRET`, `BLUEPLM_BOOTSTRAP_TOKEN`, and `BLUEPLM_MAINTENANCE_TOKEN` are mandatory and must
be separate random values of at least 32 characters. Generate them locally with
`node scripts/generate-secrets.mjs`, then paste them into the private server `.env`.

The matching, versioned MariaDB migrations are included in this package. Run
`php bin/migrate.php` from the private project directory, then call
`GET /health`. On shared hosting without SSH, make one authenticated request to
`POST /admin/migrate` with JSON `{ "maintenanceToken": "..." }`; it uses the
separate maintenance secret and returns only applied migration filenames. Do not
place the migration command, `.env`, or `src/` under the domain's document
root.

`public/.htaccess` is part of the deployment. It routes `/health`, `/auth/*`
and all other API paths to `public/index.php` while leaving no directory listing
enabled. On All-Inkl, set the domain document root to the package's `public`
directory; do not use `/public` in the client URL.

## Vault provider at first setup

The one-time `/setup/` page creates the primary vault. Choose either an
**Archive/NAS network path** or a **Google Drive Shared Drive folder**. A Google
Drive vault stores only its folder ID in MariaDB; every Windows user authorizes
their own Google account and Shared Drive membership controls file access.

Google Drive support is **not production-tested**. Validate it using a separate
database and test Shared Drive before storing production CAD revisions. The
desktop implementation guide is available in
[`../bluePLM/docs/mdb-google-drive.md`](../bluePLM/docs/mdb-google-drive.md)
and [`../bluePLM/docs/mdb-google-drive.de.md`](../bluePLM/docs/mdb-google-drive.de.md).
