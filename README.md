# BluePLM MDB PHP API

Provider-neutral PHP/MariaDB backend for BluePLM MDB. The BluePLM desktop app
contains initial setup and administration. This package exposes only the
authenticated JSON API and database migrations; `/setup/` and `/admin/` do not
serve browser interfaces.

The domain document root must point to this package's `public/` directory. The
private parent directory contains `.env`, PHP source, and migrations and must
not be web-accessible. Clients connect only to the public HTTPS URL. MariaDB
credentials never leave the PHP host.

## Desktop installer and existing databases

The desktop installer uploads the API over explicit FTPS on port 21 or implicit
FTPS on port 990. The selected mode and port must match; both modes verify the
server certificate and hostname. The installer writes a short-lived
`.env.install` beside the private `.env`. It then authenticates to the installer
API with a random one-use token and inspects the selected MariaDB database
before changing it:

- **Empty database:** BluePLM offers a new installation.
- **Versioned BluePLM database:** BluePLM asks whether to migrate it in place or
  erase it and reinstall. Migration preserves existing records.
- **Recognized legacy BluePLM schema:** BluePLM can adopt the database by
  creating the migration ledger and applying the known migrations that are not
  recorded there. Adoption-aware migrations inspect existing schema objects
  before adding missing ones; for example, the module-default migration accepts
  both complete and partial sets of its team and organization columns without
  replacing existing values.
- **Foreign or unrecognized schema:** automatic migration remains blocked. An
  explicitly confirmed erase-and-reinstall operation or manual remediation is
  required.

Legacy adoption applies only to schema shapes supported by the bundled BluePLM
migrations. It is not a general repair mechanism and does not guarantee that an
arbitrarily modified or unrelated database can be migrated safely.

Erasing requires the exact confirmation `DELETE ALL DATABASE DATA`. The server
checks the database state again immediately before committing, so a stale UI
decision cannot overwrite a database whose state changed after inspection.

After a successful new installation, `.env.install` is atomically promoted to
`.env`; the installation and bootstrap tokens are retired. When an already
bootstrapped installation is migrated, its existing `.env` remains unchanged.
Failed or abandoned operations remove the pending environment file.

## Required server values

The installer creates the private environment. A manual deployment can use
`.env.example`. These values are required:

```dotenv
MARIADB_HOST=localhost
MARIADB_PORT=3306
MARIADB_DATABASE=<database>
MARIADB_USER=<user>
MARIADB_PASSWORD=<password>
BLUEPLM_SESSION_SECRET=<independent random value, at least 32 characters>
BLUEPLM_BOOTSTRAP_TOKEN=<independent random value, at least 32 characters>
BLUEPLM_MAINTENANCE_TOKEN=<independent random value, at least 32 characters>
BLUEPLM_CORS_ORIGINS=null,file://,http://localhost:5173
```

Generate manual secrets locally with `node scripts/generate-secrets.mjs`. Never
commit `.env`, FTP credentials, database credentials, tokens, host-specific
addresses, or customer data.

`public/.htaccess` routes API requests to `public/index.php` and disables
directory listings. The health check is `GET /health`; a healthy MDB response
contains `ok: true`, `runtime: "php"`, `supabase: false`, and the supported
`apiVersion`.

## Tests

The integration test covers installer authorization, new installation,
database-state detection, in-place migration, login, user and team management,
network vault operations, and the removal of browser setup/admin pages.

```powershell
docker compose -f docker-compose.test.yml up --build -d
node test/integration.mjs
docker compose -f docker-compose.test.yml exec -T api php test/database-lifecycle.php
docker compose -f docker-compose.test.yml exec -T api php test/module-defaults-migration.php
docker compose -f docker-compose.test.yml down -v
```

All credentials in `test/install-env.fixture` are isolated test values for the
disposable Docker database.
