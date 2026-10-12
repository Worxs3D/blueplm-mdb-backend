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

## File check-in contract

`POST /files/{fileId}/checkin` accepts an explicit `mode` (`content` or
`metadata-only`) and a client-generated UUID `requestId`. The same request ID
must be reused for retries of one logical operation and must not be reused for
another payload. Check-in, metadata persistence, request-result recording, and
checkout-lease consumption are committed in one database transaction.

Both explicit modes require `canonicalPath` and `fileName`. The path must be a
normalized, safe vault-relative path using `/`, and `fileName` must exactly
match its final segment. The active path is unique within its vault. The server
persists this location in the same transaction as the check-in result. In
content mode, `storageRelativePath` remains the separate immutable content
revision location; it is not the canonical path.

Explicit content mode also requires `storageRelativePath`, a SHA-256 hex
`contentHash`, and a non-negative integer `sizeBytes`; it accepts `comment`,
`partNumber`, `pendingMetadata`, and `committedCustomProperties`. It creates
one file revision even if the content hash matches the preceding revision.
Legacy content check-ins without `mode` remain compatible with callers that
omit or send null for the hash or size. Metadata-only mode omits all content
fields, persists the new canonical location, pending datacard values, and
custom properties, consumes the lease, and does not increment the content
revision or create a revision row. `pendingMetadata` supports `part_number`,
`description`, `revision`, `config_tabs`, and `config_descriptions`;
configuration maps are merged into `_config_tabs` and `_config_descriptions` in
custom properties.

The success response includes `fileId`, `revision`, `mode`,
`contentChanged`, `metadataChanged`, `replayed`, and the persisted `file`
snapshot. An exact replay returns that original result with `replayed: true`.
Reusing a persisted request ID with a different payload returns `409 IDEMPOTENCY_KEY_REUSED`;
a request rejected before its durable result is written can be corrected and retried
with that ID. Check-in validates the final merged custom-properties object against
a 200,000-byte encoded JSON limit before changing files, revisions, leases, or the
request journal. Over-limit requests fail with `400 INVALID_REQUEST` and preserve
the lease and stored properties; legacy over-limit properties require an explicit
bounded replacement. Exact retries of an already committed request replay its
stored result before evaluating the current properties budget.
a missing, expired, consumed, or mismatched checkout lease returns
`409 INVALID_CHECKOUT`. Calls using the legacy body without
`mode` remain accepted as content check-ins when `storageRelativePath` is
provided, but clients should use the explicit contract for durable retries.
When request-ID serialization is busy or unavailable, check-in returns
`503 CHECKIN_RECOVERY_BUSY` or `503 CHECKIN_RECOVERY_UNAVAILABLE`; retry with
the same request ID.

## Durable checkout acquisition

`POST /files/{fileId}/checkout` remains compatible with legacy callers that send
only `clientWorkingPath`. New clients may include a UUID `requestId` and must
reuse it only for retries of that same acquisition. An exact retry returns the
same active lease token and current expiry; changing the file or path with that
ID returns `409 IDEMPOTENCY_KEY_REUSED`. A different request ID cannot replace
an active lease (`409 CHECKED_OUT`). The server exposes the
`checkoutRequestIdempotency` health capability only when migration
`036_checkout_request_idempotency.sql` is applied and its request journal is
present. If request-ID locking is busy, the server returns retryable
`503 CHECKOUT_RECOVERY_BUSY`; when the durable journal or lock service is
unavailable it returns `503 CHECKOUT_RECOVERY_UNAVAILABLE`. Retries must retain
the original request ID. Cancellation, check-in, and replacement by a legacy
checkout terminalize the request and erase its encrypted token so it cannot be
replayed later. An expired request is terminalized when the server next
observes the expired lease. The API relies on MariaDB named-lock support for
bounded per-request serialization. The per-user checkout lifecycle mutex waits
up to five seconds; contention fails closed with `503 CHECKOUT_BUSY`. This may
affect durable or legacy checkout and user/account removal. Only durable
checkout uses a request ID, so reuse the same ID when retrying that checkout.
Legacy checkout and deletion have no request-ID idempotency; inspect the current
lease/account state before retrying when the response may have been lost after
the operation completed.

## Tests

The integration test covers installer authorization, new installation,
database-state detection, in-place migration, login, user and team management,
network vault operations, and the removal of browser setup/admin pages.

```powershell
docker compose -f docker-compose.test.yml up --build -d
node test/integration.mjs
docker compose -f docker-compose.test.yml exec -T api php test/database-lifecycle.php
docker compose -f docker-compose.test.yml exec -T api php test/module-defaults-migration.php
docker compose -f docker-compose.test.yml exec -T api php test/checkout-lifecycle-lock-release.php
docker compose -f docker-compose.test.yml down -v
```

All credentials in `test/install-env.fixture` are isolated test values for the
disposable Docker database.
