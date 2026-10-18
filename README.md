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
snapshot. An exact replay returns that original result with `replayed: true` while
its receipt is logically retained for 30 days by default; set
`BLUEPLM_CHECKIN_REQUEST_TTL_DAYS` to a value from 1 to 3650 to change the
retention period. The active journal is limited to 200 receipts per file, 1000
per global user ID across all organizations, and 10000 per organization by
default. These limits can be changed
with `BLUEPLM_CHECKIN_REQUEST_MAX_PER_FILE`,
`BLUEPLM_CHECKIN_REQUEST_MAX_PER_USER`, and
`BLUEPLM_CHECKIN_REQUEST_MAX_PER_ORGANIZATION`; each must be a positive integer.
At a limit, a new check-in returns `429 CHECKIN_RECEIPT_QUOTA_EXCEEDED` before
changing file metadata, creating a revision, consuming the lease, or recording
the request. An exact retained replay is still returned at quota. After expiry,
the receipt is deleted in bounded batches and replay of that request ID is no
longer guaranteed. Reusing a retained request ID with a different payload
returns `409 IDEMPOTENCY_KEY_REUSED`; a request rejected before its durable
result is written can be corrected and retried with that ID. Expired receipts
stop being eligible for replay as soon as their age reaches the TTL. The TTL is
evaluated against `created_at` on every request, so changing the TTL immediately
changes the replay window for existing receipts. Expired receipts are excluded
from replay and quota counts. Physical cleanup is opportunistic: each successful
new check-in prunes an indexed batch of at most 500 expired receipts and may
also delete the current request ID's expired receipt if it remains afterward;
therefore at most 501 rows can be deleted by one check-in. Other expired rows
may remain stored until a later successful check-in reaches them.
Receipt `created_at` values are stored as UTC wall-clock values: new check-ins
write `UTC_TIMESTAMP(3)` explicitly, so their retention window is independent
of the database connection time zone. Older receipts created before this rule
used the session's implicit timestamp time zone; `DATETIME` stores no zone
metadata, so the original offset cannot be inferred or safely backfilled.
Those legacy values are interpreted as UTC and may therefore have a shifted
replay window if an earlier database session used a non-UTC time zone.
Invalid retention settings fail closed with
`503 CHECKIN_RECOVERY_UNAVAILABLE` before database access or check-in mutation.
If a quota is reached, an administrator can raise the corresponding positive
limit in the service configuration to restore new check-ins while retaining
unresolved receipts, or wait for those receipts to expire; exact retained
replays continue to work while at quota. Do not manually delete journal rows to
relieve pressure, since that can discard a result needed for safe recovery.
Physical cleanup only runs after a successful new check-in and deletes up to 500
expired rows in the indexed batch, plus at most one targeted expired row for the
current request ID (501 total at most), so increasing the relevant limit may be
needed to allow cleanup to proceed. Code that requires durable check-in recovery
must ship with migration `038_checkin_request_retention_indexes.sql`; until both
migrations `035` and `038` are applied, request-ID check-ins fail closed.
Check-in validates
the final merged custom-properties object against
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
`checkoutRequestIdempotency` health capability only when migrations
`036_checkout_request_idempotency.sql` and
`039_checkout_request_retention_indexes.sql` are applied and the request
journal is present. If request-ID locking is busy, the server returns retryable
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

The checkout acquisition journal retains terminal request records for 30 days
by default, measured from terminal `updated_at`; configure
`BLUEPLM_CHECKOUT_REQUEST_TTL_DAYS` from 1 to 3650 to change this window.
Active requests are never pruned because their encrypted token is required to
recover a still-live lease, including a lease renewed after the request was
created. Quotas count every active request plus terminal requests still inside
the retention window: 200 per file, 1000 per global user ID across all
organizations, and 10000 per organization by default. Configure these
independently with `BLUEPLM_CHECKOUT_REQUEST_MAX_PER_FILE`,
`BLUEPLM_CHECKOUT_REQUEST_MAX_PER_USER`, and
`BLUEPLM_CHECKOUT_REQUEST_MAX_PER_ORGANIZATION`; all values must be positive
integers. Invalid retention settings fail closed with
`503 CHECKOUT_RECOVERY_UNAVAILABLE` before checkout journal locking or mutation. An exact active
retry is resolved before quota checks and continues to recover the same token
at quota. A new acquisition rejected at any quota returns
`429 CHECKOUT_REQUEST_QUOTA_EXCEEDED` before file, lease, or journal mutation;
the error message identifies the exhausted scope (`file`, `user`, or
`organization`).

TTL applies only after a request becomes terminal. Before quota checks, each
durable acquisition performs bounded maintenance over up to 500 candidate
expired leases in the caller's global-user or current-organization scope.
Candidates are rechecked under stable file-row locks; the current
checkout token and expiry must still prove that the lease is expired or no
longer owns the file. A concurrently renewed live lease keeps its active
receipt and encrypted replay token. A missing or replaced checkout can only
terminalize a due receipt; a different current checkout is never deleted. This
maintenance commits separately from the new acquisition, so quota rejection
creates no new lease or journal record, though it may clean up already-expired
state. Terminal TTL starts when this safe transition is confirmed: a stale
active receipt remains quota-counted until the sweep discovers it, then remains
counted for the configured terminal-retention window. Terminal cleanup
separately deletes up to 500 expired terminal rows on an accepted acquisition,
plus at most one additional expired row for that request ID. Active rows are
never deleted based on journal age. Once a terminal row has expired and been
pruned, reusing
its request ID can start a new acquisition; exact idempotent replay is no longer
guaranteed after that point. To recover from quota pressure, an administrator
can raise the corresponding checkout limit in service configuration or wait
for terminal requests to reach retention expiry; exact live replays remain
available at quota. Do not manually delete checkout journal rows because that
can destroy recovery for a live lease. Checkout request-ID recovery now
requires both `036_checkout_request_idempotency.sql` and
`039_checkout_request_retention_indexes.sql`; until both are applied, the
health capability is withheld and durable acquisition fails closed. Terminal
timestamps are written explicitly in UTC, so their TTL basis is independent of
the MariaDB session time zone. The legacy `created_at` timestamp is not used as
the retention clock.

## Color swatch contract

All color swatch routes require an authenticated organization session. `GET
/color-swatches` returns `{ "swatches": [...] }`, ordered by creation time and
ID. It includes organization swatches and only the current user's personal
swatches. Each item has exactly `id`, `color`, `isOrg`, and `createdAt`.

`POST /color-swatches` accepts `{ "color": "#RRGGBB", "isOrg": boolean }`
and returns `201` with the created item. Color spelling and case are preserved;
duplicate colors are allowed. Personal entries belong to the authenticated
user. Organization entries can be created only by an Owner or Administrator.
Invalid colors or non-boolean scope values return `400`; an unauthorized
organization write returns `403`.

`DELETE /color-swatches/{id}` returns an empty `204` response. Users can delete
their own personal entries; only Owners and Administrators can delete
organization entries. A missing entry, another user's personal entry, or an
entry from another organization returns `404`, keeping tenant and personal
ownership boundaries opaque. Requests without a valid session return `401`.
Viewer and Guest roles can read their organization and personal swatch view,
but the API's read-only role guard rejects all swatch writes with `403`.
Migration `040_color_swatches.sql` adds this storage to existing databases
without modifying existing organization or membership records.

## Workflow review rounds

Migration `041_workflow_review_rounds.sql` preserves existing review rows and
decisions while assigning server-owned round identifiers. New submissions may
start a new round after the preceding round reaches a terminal outcome; previous
decisions remain historical and do not count toward the new round's quorum.
The round identifier is internal workflow state, not a client-supplied
authorization value or an addition to the existing review API response shape.

Older rows do not record their original round boundaries. If legacy pending and
terminal decisions for the same workflow scope cannot be separated safely, the
server marks that legacy state ambiguous and fails closed with
`409 REVIEW_ROUND_STATE_AMBIGUOUS` for approval rather than guessing which
decisions belong together or changing historical rows. An already assigned
reviewer may close an ambiguous pending task using the existing reject or
return-for-changes decision, subject to the normal authorization, expiry, and
stale-state checks. This does not approve the workflow, advance the file state,
or alter prior terminal decisions. When the outstanding legacy tasks are
closed, a new submission receives a fresh server-owned round; decisions from
the ambiguous legacy bucket do not count toward its quorum. Do not delete or
relabel historical decisions to force a retry. Ambiguous tasks that cannot be
closed through those existing actions remain fail-closed for operator review;
there is no separate administrator recovery endpoint or automatic
reconstruction of their original rounds.

Migration `041_workflow_review_rounds.sql` requires coordinated deployment of
the PHP code and schema. Before upgrading an existing service, take and verify
a restorable database backup, then enable a maintenance/write block outside
the API. Allow already-running PHP requests to finish before replacing the
server code. Deploy the matching PHP code and migration together while writes
remain blocked; then verify the migration ledger/schema and run the health and
workflow-review smoke checks. Re-enable writes only after those checks succeed.
If a check fails, keep writes blocked and investigate before resuming service.
The installer and `DatabaseLifecycle` inspect database state and apply pending
migrations. The `/admin/migrate` endpoint also applies pending migrations when
presented with the maintenance token. None of these mechanisms establishes an
external write block or drains in-flight requests. This sequence is an
operational requirement, not a verified zero-downtime or rolling-upgrade
procedure, and no installer-specific maintenance gate has been verified.

## Tests

The integration test covers installer authorization, new installation,
database-state detection, in-place migration, login, user and team management,
network vault operations, and the removal of browser setup/admin pages.

The color swatch integration and migration-upgrade cases use a separately named
Compose project and MariaDB volume. The API binds to loopback. Docker Compose
2.24.4 or later is required for the port override. Pick a unique project name
and unused host port, set `BLUEPLM_PHP_TEST_SUBNET` to a verified unused
private CIDR, and set the project name, port, and server URL for the full test
run. The integration runner inherits the project name for its `docker compose
exec` calls. Leave the test resources available for inspection:

```powershell
$env:COMPOSE_PROJECT_NAME = 'mdb-color-swatches-php-local'
$env:BLUEPLM_PHP_TEST_PORT = '18462'
$env:BLUEPLM_PHP_TEST_SERVER = 'http://127.0.0.1:18462'
$env:BLUEPLM_PHP_TEST_SUBNET = '<verified unused private CIDR>'
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml -f docker-compose.swatches-test.yml config
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml -f docker-compose.swatches-test.yml up --build -d
node test/integration.mjs
Remove-Item Env:COMPOSE_PROJECT_NAME, Env:BLUEPLM_PHP_TEST_PORT, Env:BLUEPLM_PHP_TEST_SERVER, Env:BLUEPLM_PHP_TEST_SUBNET -ErrorAction SilentlyContinue
```

The environment cleanup above affects the PowerShell session only. Leave the
swatch test containers and volume available for review; do not point tests at a
production service or run a volume-removal or prune command while results are
under review.

```powershell
docker compose -f docker-compose.test.yml up --build -d
node test/integration.mjs
docker compose -f docker-compose.test.yml exec -T api php test/database-lifecycle.php
docker compose -f docker-compose.test.yml exec -T api php test/module-defaults-migration.php
docker compose -f docker-compose.test.yml exec -T api php test/checkout-lifecycle-lock-release.php
docker compose -f docker-compose.test.yml down -v
```

The focused retention contract test requires intentionally small test-only
limits. Run it in a separate disposable Compose project with a free host port;
do not use the shared/default project, the standard integration project, or a
production database. Keep all three isolation variables explicit and choose a
project name that is not already in use:

```powershell
$env:COMPOSE_PROJECT_NAME = 'blueplm-checkin-retention-local'
$env:BLUEPLM_PHP_TEST_PORT = '18123'
$env:BLUEPLM_PHP_TEST_SERVER = 'http://127.0.0.1:18123'
$env:BLUEPLM_CHECKIN_REQUEST_TTL_DAYS = '1'
$env:BLUEPLM_CHECKIN_REQUEST_MAX_PER_FILE = '2'
$env:BLUEPLM_CHECKIN_REQUEST_MAX_PER_USER = '5'
$env:BLUEPLM_CHECKIN_REQUEST_MAX_PER_ORGANIZATION = '5'
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml up --build -d
node test/checkin-retention.mjs
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml down -v
```

Checkout retention has its own isolated test stack and independent limits. The
test verifies terminal expiry/reuse, preservation and replay of aged active
leases, the per-file/user/organization caps, exact replay at quota, concurrent
cross-organization acquisitions by one global user, UTC terminal timestamps
under a non-UTC database session, and a renewal/cleanup race. Choose a fresh
project name and confirm the port is free before running; never use the shared
default project or a production database.

```powershell
$env:COMPOSE_PROJECT_NAME = 'blueplm-checkout-retention-local'
$env:BLUEPLM_PHP_TEST_PORT = '18124'
$env:BLUEPLM_PHP_TEST_SERVER = 'http://127.0.0.1:18124'
$env:BLUEPLM_CHECKOUT_REQUEST_TTL_DAYS = '1'
$env:BLUEPLM_CHECKOUT_REQUEST_MAX_PER_FILE = '2'
$env:BLUEPLM_CHECKOUT_REQUEST_MAX_PER_USER = '5'
$env:BLUEPLM_CHECKOUT_REQUEST_MAX_PER_ORGANIZATION = '6'
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml up --build -d
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml exec -T api php test/checkout-retention-config.php
node test/checkout-retention.mjs
docker compose -p $env:COMPOSE_PROJECT_NAME -f docker-compose.test.yml down -v
```

Use the standard integration commands above separately with their normal test
configuration. Before running either suite, confirm the named project and host
port are not already in use; never drop `-p` or point these commands at a live
service.

All credentials in `test/install-env.fixture` are isolated test values for the
disposable Docker database.
