import assert from 'node:assert/strict'
import { execFileSync, spawn } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'

const project = process.env.COMPOSE_PROJECT_NAME
const server = process.env.BLUEPLM_PHP_TEST_SERVER
assert.ok(project && server, 'Use an isolated Compose project and explicit test server URL.')

async function request(path, init = {}, token) {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Connection', 'close')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(`${server}${path}`, { ...init, headers })
  const body = response.status === 204 ? undefined : await response.json()
  return { status: response.status, body }
}

function database(sql, root = false) {
  return execFileSync('docker', [
    'compose', '-p', project, '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', ...(root ? ['-uroot', '-proot-test-password'] : ['-ublueplm', '-pblueplm-test-password']), 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
}

function count(sql) { return Number(database(sql)) }

function spawnFileLockHolder(fileId, orgId) {
  const child = spawn('docker', [
    'compose', '-p', project, '-f', 'docker-compose.test.yml', 'exec', '-T', 'api',
    'php', 'test/checkout-cancel-lock-holder.php', fileId, orgId, 'file-only',
  ], { cwd: process.cwd(), stdio: ['pipe', 'pipe', 'pipe'] })
  let stdout = ''
  let stderr = ''
  child.stdout.setEncoding('utf8')
  child.stderr.setEncoding('utf8')
  child.stdout.on('data', chunk => { stdout += chunk })
  child.stderr.on('data', chunk => { stderr += chunk })
  const done = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('close', code => code === 0 ? resolve(stdout) : reject(new Error(`File lock holder exited ${code}: ${stderr}`)))
  })
  return { child, done, output: () => stdout }
}

async function waitForLine(worker, expected, timeoutMs = 20000) {
  const started = Date.now()
  while (!worker.output().includes(expected)) {
    if (Date.now() - started >= timeoutMs) throw new Error(`Timed out waiting for helper output ${expected}.`)
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}

async function waitForDatabaseLockWait(timeoutMs = 20000, minimumWaits = 1) {
  const started = Date.now()
  // Lock-wait visibility requires PROCESS on MariaDB; inspect only this isolated
  // test container with its disposable root credential, never the app user.
  while (Number(database('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS', true)) < minimumWaits) {
    if (Date.now() - started >= timeoutMs) throw new Error('Renewal did not wait on the deliberately held file lock.')
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}

const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const sessionSecret = 'test-session-secret-must-be-at-least-thirty-two-characters'
const installed = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken,
    action: 'install',
    bootstrap: {
      organizationName: 'Checkout Retention Org',
      organizationSlug: 'checkout-retention-org',
      email: 'checkout-retention-owner@example.test',
      displayName: 'Checkout Retention Owner',
      password: 'Checkout retention owner password 123!',
    },
  }),
})
assert.equal(installed.status, 200, JSON.stringify(installed.body))
const ownerToken = installed.body.token
const organizationId = database("SELECT id FROM organizations WHERE slug='checkout-retention-org'")
const ownerId = database("SELECT id FROM users WHERE email='checkout-retention-owner@example.test'")
const terminalDeletePlan = database("EXPLAIN DELETE FROM checkout_acquisition_requests WHERE status <> 'active' AND updated_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY) ORDER BY updated_at ASC LIMIT 500")
assert.match(terminalDeletePlan, /idx_checkout_request_terminal_retention/u, 'the bounded terminal sweep must use its updated_at-leading index')
assert.doesNotMatch(terminalDeletePlan, /Using filesort/u, 'the terminal sweep index must satisfy the bounded updated_at ordering')
const vault = await request('/vaults', {
  method: 'POST', body: JSON.stringify({ name: 'Checkout Retention Vault', networkRoot: 'checkout-retention-root' }),
}, ownerToken)
assert.equal(vault.status, 201, JSON.stringify(vault.body))

async function createFile(name, vaultId = vault.body.id, token = ownerToken) {
  const response = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId,
      canonicalPath: `${name}.sldprt`,
      fileName: `${name}.sldprt`,
      storageRelativePath: `${name}.sldprt`,
    }),
  }, token)
  assert.equal(response.status, 201, JSON.stringify(response.body))
  return response.body
}

async function acquire(file, token = ownerToken, requestId = randomUUID()) {
  const response = await request(`/files/${file.id}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: `retention/${file.id}`, requestId }),
  }, token)
  return { ...response, requestId }
}

async function cancel(file, checkoutToken, token = ownerToken) {
  const response = await request(`/files/${file.id}/checkout/cancel`, {
    method: 'POST', body: JSON.stringify({ checkoutToken }),
  }, token)
  assert.equal(response.status, 204, JSON.stringify(response.body))
}

function ageRequest(requestId, organization = organizationId) {
  database(`UPDATE checkout_acquisition_requests SET updated_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE organization_id='${organization}' AND request_id='${requestId.toLowerCase()}';`)
}

function requestState(requestId, organization = organizationId) {
  return database(`SELECT CONCAT(status, '|', token_ciphertext IS NULL, '|', updated_at) FROM checkout_acquisition_requests WHERE organization_id='${organization}' AND request_id='${requestId.toLowerCase()}';`)
}

const originalGlobalTimeZone = database('SELECT @@GLOBAL.time_zone', true)
const utcFile = await createFile('non-utc-terminal-clock')
let utcAcquisition
database("SET GLOBAL time_zone = '+09:00'", true)
try {
  utcAcquisition = await acquire(utcFile)
  assert.equal(utcAcquisition.status, 200, JSON.stringify(utcAcquisition.body))
  await cancel(utcFile, utcAcquisition.body.checkoutToken)
  const [terminalOffset, sessionOffset] = database(`SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), updated_at), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), NOW()) FROM checkout_acquisition_requests WHERE organization_id='${organizationId}' AND request_id='${utcAcquisition.requestId}'`).split('\t').map(Number)
  assert.ok(Math.abs(terminalOffset) <= 2, `terminal updated_at must use UTC under a non-UTC session (offset ${terminalOffset}s)`)
  assert.ok(sessionOffset >= 8 * 60 * 60, `the regression must use a non-UTC session (offset ${sessionOffset}s)`)
} finally {
  database(`SET GLOBAL time_zone = '${originalGlobalTimeZone.replaceAll("'", "''")}'`, true)
}
ageRequest(utcAcquisition.requestId)

const agedActiveFile = await createFile('old-but-live-checkout')
const agedActive = await acquire(agedActiveFile)
assert.equal(agedActive.status, 200, JSON.stringify(agedActive.body))
ageRequest(agedActive.requestId)
const replayOldActive = await acquire(agedActiveFile, ownerToken, agedActive.requestId)
assert.equal(replayOldActive.status, 200, JSON.stringify(replayOldActive.body))
assert.equal(replayOldActive.body.replayed, true, 'an old active row must preserve live-token recovery')
assert.equal(replayOldActive.body.checkoutToken, agedActive.body.checkoutToken)
assert.equal(requestState(agedActive.requestId).split('|')[0], 'active')
await cancel(agedActiveFile, agedActive.body.checkoutToken)
ageRequest(agedActive.requestId)

const perFile = await createFile('checkout-file-cap')
const perFileRequests = []
for (let attempt = 0; attempt < 2; attempt += 1) {
  const acquired = await acquire(perFile)
  assert.equal(acquired.status, 200, JSON.stringify(acquired.body))
  perFileRequests.push(acquired.requestId)
  await cancel(perFile, acquired.body.checkoutToken)
}
const perFileBefore = database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM file_revisions WHERE file_id='${perFile.id}'), '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${perFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${perFile.id}')) FROM files WHERE id='${perFile.id}'`)
const perFileRejected = await acquire(perFile)
assert.equal(perFileRejected.status, 429, JSON.stringify(perFileRejected.body))
assert.equal(perFileRejected.body.error, 'CHECKOUT_REQUEST_QUOTA_EXCEEDED')
assert.match(perFileRejected.body.message, /file/u)
assert.equal(database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM file_revisions WHERE file_id='${perFile.id}'), '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${perFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${perFile.id}')) FROM files WHERE id='${perFile.id}'`), perFileBefore, 'per-file rejection must not mutate the file, revisions, lease, or journal')
for (const requestId of perFileRequests) ageRequest(requestId)

const terminalExpiryFile = await createFile('checkout-terminal-expiry')
const terminalExpiredId = randomUUID()
const firstTerminalUse = await acquire(terminalExpiryFile, ownerToken, terminalExpiredId)
assert.equal(firstTerminalUse.status, 200, JSON.stringify(firstTerminalUse.body))
await cancel(terminalExpiryFile, firstTerminalUse.body.checkoutToken)
database(`UPDATE checkout_acquisition_requests SET updated_at=DATE_ADD(DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${terminalExpiredId}';`)
const replayInsideRetention = await acquire(terminalExpiryFile, ownerToken, terminalExpiredId)
assert.equal(replayInsideRetention.status, 409, JSON.stringify(replayInsideRetention.body))
assert.equal(replayInsideRetention.body.error, 'CHECKOUT_REQUEST_TERMINAL', 'terminal IDs remain reserved just inside the TTL')
ageRequest(terminalExpiredId)
const reusedAfterRetention = await acquire(terminalExpiryFile, ownerToken, terminalExpiredId)
assert.equal(reusedAfterRetention.status, 200, JSON.stringify(reusedAfterRetention.body))
assert.equal(reusedAfterRetention.body.replayed, false, 'a terminal request ID may start a new acquisition after retention')
assert.notEqual(reusedAfterRetention.body.checkoutToken, firstTerminalUse.body.checkoutToken)
await cancel(terminalExpiryFile, reusedAfterRetention.body.checkoutToken)
ageRequest(terminalExpiredId)

// Create one member in each organization and use a second session for the same
// user to exercise the global (not per-organization) user cap under a race.
const secondaryOrgId = randomUUID()
database(`INSERT INTO organizations (id, name, slug) VALUES ('${secondaryOrgId}', 'Checkout Retention Secondary', 'checkout-retention-secondary'); INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${secondaryOrgId}', '${ownerId}', 'owner');`)
const secondaryToken = randomUUID()
const secondaryTokenHash = createHash('sha256').update(`${sessionSecret}:${secondaryToken}`).digest('hex')
database(`INSERT INTO sessions (token_hash, user_id, organization_id, expires_at) VALUES ('${secondaryTokenHash}', '${ownerId}', '${secondaryOrgId}', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 DAY));`)
const secondaryVault = await request('/vaults', {
  method: 'POST', body: JSON.stringify({ name: 'Checkout Retention Secondary Vault', networkRoot: 'checkout-retention-secondary-root' }),
}, secondaryToken)
assert.equal(secondaryVault.status, 201, JSON.stringify(secondaryVault.body))

const userFiles = []
userFiles.push(await createFile('user-cap-a'))
userFiles.push(await createFile('user-cap-b'))
userFiles.push(await createFile('user-cap-c'))
userFiles.push(await createFile('user-cap-race-primary', vault.body.id, ownerToken))
userFiles.push(await createFile('user-cap-race-secondary', secondaryVault.body.id, secondaryToken))
userFiles.push(await createFile('user-cap-rejected', vault.body.id, ownerToken))
for (const file of userFiles.slice(0, 3)) {
  const acquired = await acquire(file)
  assert.equal(acquired.status, 200, JSON.stringify(acquired.body))
  await cancel(file, acquired.body.checkoutToken)
}
const secondaryOld = await acquire(userFiles[4], secondaryToken)
assert.equal(secondaryOld.status, 200, JSON.stringify(secondaryOld.body))
await cancel(userFiles[4], secondaryOld.body.checkoutToken, secondaryToken)
assert.equal(count(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE user_id='${ownerId}' AND (status='active' OR updated_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY))`), 4)

const raced = await Promise.all([
  acquire(userFiles[3], ownerToken),
  acquire(userFiles[4], secondaryToken),
])
assert.deepEqual(raced.map(result => result.status).sort(), [200, 429], 'the global user quota must serialize concurrent checkouts across organizations')
const raceWinnerIndex = raced.findIndex(result => result.status === 200)
const raceWinnerFile = userFiles[raceWinnerIndex === 0 ? 3 : 4]
const raceWinnerToken = raceWinnerIndex === 0 ? ownerToken : secondaryToken
const raceDeniedIndex = raced.findIndex(result => result.status === 429)
const raceDeniedFile = userFiles[raceDeniedIndex === 0 ? 3 : 4]
const rejectedState = database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${raceDeniedFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id IN ('${organizationId}','${secondaryOrgId}') AND file_id='${raceDeniedFile.id}')) FROM files WHERE id='${raceDeniedFile.id}'`)
assert.equal(raced[raceDeniedIndex].body.error, 'CHECKOUT_REQUEST_QUOTA_EXCEEDED')
assert.match(raced[raceDeniedIndex].body.message, /user/u)
assert.equal(database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${raceDeniedFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id IN ('${organizationId}','${secondaryOrgId}') AND file_id='${raceDeniedFile.id}')) FROM files WHERE id='${raceDeniedFile.id}'`), rejectedState, 'quota rejection must leave file, lease, and journal unchanged')
assert.equal(count(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE user_id='${ownerId}' AND (status='active' OR updated_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY))`), 5)
const replayAtLimit = await acquire(raceWinnerFile, raceWinnerToken, raced[raceWinnerIndex].requestId)
assert.equal(replayAtLimit.status, 200, JSON.stringify(replayAtLimit.body))
assert.equal(replayAtLimit.body.replayed, true, 'an exact live replay must pass at the global user cap')
assert.equal(replayAtLimit.body.checkoutToken, raced[raceWinnerIndex].body.checkoutToken)
await cancel(raceWinnerFile, raced[raceWinnerIndex].body.checkoutToken, raceWinnerToken)

// The user cap is global; this same-user row in the secondary organization
// participates in the main-org count above. Organization scope is independent:
// a second user can fill the remaining org capacity and then be rejected.
const member = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'checkout-retention-member@example.test', displayName: 'Retention Member', password: 'Checkout retention member password 123!' }),
}, ownerToken)
assert.equal(member.status, 201, JSON.stringify(member.body))
await request(`/users/${member.body.id}/vault-access`, { method: 'PUT', body: JSON.stringify({ vaultIds: [vault.body.id] }) }, ownerToken)
const memberLogin = await request('/auth/login', { method: 'POST', body: JSON.stringify({ email: 'checkout-retention-member@example.test', password: 'Checkout retention member password 123!' }) })
assert.equal(memberLogin.status, 200, JSON.stringify(memberLogin.body))
let organizationCount = count(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id='${organizationId}' AND (status='active' OR updated_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY))`)
let memberFileIndex = 0
while (organizationCount < 6) {
  const memberFile = await createFile(`org-cap-${memberFileIndex++}`)
  const acquired = await acquire(memberFile, memberLogin.body.token)
  assert.equal(acquired.status, 200, JSON.stringify(acquired.body))
  await cancel(memberFile, acquired.body.checkoutToken, memberLogin.body.token)
  organizationCount = count(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id='${organizationId}' AND (status='active' OR updated_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY))`)
}
const orgDeniedFile = await createFile('org-cap-rejected')
const orgBefore = database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${orgDeniedFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${orgDeniedFile.id}')) FROM files WHERE id='${orgDeniedFile.id}'`)
const organizationRejected = await acquire(orgDeniedFile, memberLogin.body.token)
assert.equal(organizationRejected.status, 429, JSON.stringify(organizationRejected.body))
assert.equal(organizationRejected.body.error, 'CHECKOUT_REQUEST_QUOTA_EXCEEDED')
assert.match(organizationRejected.body.message, /organization/u)
assert.equal(database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${orgDeniedFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${orgDeniedFile.id}')) FROM files WHERE id='${orgDeniedFile.id}'`), orgBefore, 'organization quota rejection must leave file, lease, and journal unchanged')

// Renewal and cleanup may overlap, but cleanup must never prune active token
// recovery even when its journal timestamp is old.
database(`UPDATE checkout_acquisition_requests SET updated_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE user_id='${ownerId}' AND status <> 'active'`)
const renewalRaceFile = await createFile('renewal-cleanup-race', secondaryVault.body.id, secondaryToken)
const renewalRace = await acquire(renewalRaceFile, secondaryToken)
assert.equal(renewalRace.status, 200, JSON.stringify(renewalRace.body))
ageRequest(renewalRace.requestId, secondaryOrgId)
const cleanupTrigger = await createFile('renewal-cleanup-trigger', secondaryVault.body.id, secondaryToken)
const holder = spawnFileLockHolder(renewalRaceFile.id, secondaryOrgId)
await waitForLine(holder, 'READY')
const renewalPromise = request(`/files/${renewalRaceFile.id}/checkout/renew`, { method: 'POST', body: JSON.stringify({ checkoutToken: renewalRace.body.checkoutToken }) }, secondaryToken)
let cleanupCheckout
let renewed
try {
  await waitForDatabaseLockWait()
  cleanupCheckout = await acquire(cleanupTrigger, secondaryToken)
} finally {
  if (holder.child.exitCode === null) {
    holder.child.stdin.on('error', () => {})
    holder.child.stdin.write('continue\n')
  }
  await holder.done
}
renewed = await renewalPromise
assert.equal(renewed.status, 200, JSON.stringify(renewed.body))
assert.equal(cleanupCheckout.status, 200, JSON.stringify(cleanupCheckout.body))
const renewalJournal = database(`SELECT CONCAT(status, '|', token_ciphertext IS NULL, '|', expires_at = (SELECT expires_at FROM checkouts WHERE file_id='${renewalRaceFile.id}')) FROM checkout_acquisition_requests WHERE organization_id='${secondaryOrgId}' AND request_id='${renewalRace.requestId}'`)
assert.equal(renewalJournal, 'active|0|1', 'concurrent terminal cleanup must preserve the renewed active request and token')
const renewalReplay = await acquire(renewalRaceFile, secondaryToken, renewalRace.requestId)
assert.equal(renewalReplay.status, 200, JSON.stringify(renewalReplay.body))
assert.equal(renewalReplay.body.checkoutToken, renewalRace.body.checkoutToken)
await cancel(renewalRaceFile, renewalRace.body.checkoutToken, secondaryToken)
await cancel(cleanupTrigger, cleanupCheckout.body.checkoutToken, secondaryToken)

assert.equal(count(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id='${organizationId}' AND status <> 'active' AND updated_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)`), 0, 'successful acquisitions must opportunistically prune expired terminal rows')

// Lease expiry, rather than journal age, is the authority for transitioning an
// abandoned active receipt. The next durable acquisition performs bounded
// maintenance before quota evaluation and terminalizes only after file-lock
// revalidation.
database("UPDATE checkout_acquisition_requests SET updated_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE status <> 'active'")
const abandonedFile = await createFile('abandoned-checkout')
const abandoned = await acquire(abandonedFile)
assert.equal(abandoned.status, 200, JSON.stringify(abandoned.body))
database(`UPDATE checkouts SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id='${abandonedFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${abandoned.requestId}'`)
const abandonedTrigger = await createFile('abandoned-checkout-trigger')
const afterAbandoned = await acquire(abandonedTrigger)
assert.equal(afterAbandoned.status, 200, JSON.stringify(afterAbandoned.body))
assert.equal(requestState(abandoned.requestId).split('|')[0], 'expired', 'an expired lease must not leave an active quota row forever')
assert.equal(requestState(abandoned.requestId).split('|')[1], '1', 'terminalization must erase the encrypted recovery token')
assert.equal(count(`SELECT COUNT(*) FROM checkouts WHERE file_id='${abandonedFile.id}'`), 0, 'the expired checkout row must be removed')
await cancel(abandonedTrigger, afterAbandoned.body.checkoutToken)
ageRequest(abandoned.requestId)
ageRequest(afterAbandoned.requestId)

// A candidate selected from a stale journal expiry is not enough: if renewal
// has left a valid current checkout, locked revalidation must preserve it.
const renewalRevalidationFile = await createFile('renewal-expiry-sweep-race', secondaryVault.body.id, secondaryToken)
const renewalRevalidation = await acquire(renewalRevalidationFile, secondaryToken)
assert.equal(renewalRevalidation.status, 200, JSON.stringify(renewalRevalidation.body))
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${secondaryOrgId}' AND request_id='${renewalRevalidation.requestId}'`)
const renewalRevalidationTrigger = await createFile('renewal-expiry-sweep-trigger', secondaryVault.body.id, secondaryToken)
const revalidationHolder = spawnFileLockHolder(renewalRevalidationFile.id, secondaryOrgId)
await waitForLine(revalidationHolder, 'READY')
const revalidationRenewalPromise = request(`/files/${renewalRevalidationFile.id}/checkout/renew`, { method: 'POST', body: JSON.stringify({ checkoutToken: renewalRevalidation.body.checkoutToken }) }, secondaryToken)
const revalidationCleanupPromise = acquire(renewalRevalidationTrigger, secondaryToken)
let revalidationCleanup
let revalidationRenewal
try {
  await waitForDatabaseLockWait(20000, 2)
} finally {
  if (revalidationHolder.child.exitCode === null) {
    revalidationHolder.child.stdin.on('error', () => {})
    revalidationHolder.child.stdin.write('continue\n')
  }
  await revalidationHolder.done
}
revalidationCleanup = await revalidationCleanupPromise
revalidationRenewal = await revalidationRenewalPromise
assert.equal(revalidationCleanup.status, 200, JSON.stringify(revalidationCleanup.body))
assert.equal(revalidationRenewal.status, 200, JSON.stringify(revalidationRenewal.body))
assert.equal(requestState(renewalRevalidation.requestId, secondaryOrgId).split('|')[0], 'active', 'the sweeper must revalidate the current renewed checkout under its file lock')
const revalidationReplay = await acquire(renewalRevalidationFile, secondaryToken, renewalRevalidation.requestId)
assert.equal(revalidationReplay.status, 200, JSON.stringify(revalidationReplay.body))
assert.equal(revalidationReplay.body.checkoutToken, renewalRevalidation.body.checkoutToken)
await cancel(renewalRevalidationFile, renewalRevalidation.body.checkoutToken, secondaryToken)
await cancel(renewalRevalidationTrigger, revalidationCleanup.body.checkoutToken, secondaryToken)
ageRequest(renewalRevalidation.requestId, secondaryOrgId)
ageRequest(revalidationCleanup.requestId, secondaryOrgId)

// Missing checkout rows are recoverable orphan receipts only once their
// recorded lease expiry is due; the sweeper must not delete unrelated leases.
const orphanFile = await createFile('orphaned-checkout-journal')
const orphan = await acquire(orphanFile)
assert.equal(orphan.status, 200, JSON.stringify(orphan.body))
database(`DELETE FROM checkouts WHERE file_id='${orphanFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${orphan.requestId}'`)
const orphanTrigger = await createFile('orphaned-checkout-trigger')
const afterOrphan = await acquire(orphanTrigger)
assert.equal(afterOrphan.status, 200, JSON.stringify(afterOrphan.body))
assert.equal(requestState(orphan.requestId).split('|')[0], 'expired', 'an expired journal without its checkout row must terminalize safely')
await cancel(orphanTrigger, afterOrphan.body.checkoutToken)
ageRequest(orphan.requestId)
ageRequest(afterOrphan.requestId)

const replacedLeaseFile = await createFile('replaced-checkout-token')
const replacedRequest = await acquire(replacedLeaseFile)
assert.equal(replacedRequest.status, 200, JSON.stringify(replacedRequest.body))
const replacementTokenHash = createHash('sha256').update(randomUUID()).digest('hex')
database(`UPDATE checkouts SET token_hash='${replacementTokenHash}', expires_at=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR) WHERE file_id='${replacedLeaseFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${replacedRequest.requestId}'`)
const replacementTrigger = await createFile('replaced-checkout-trigger')
const afterReplacement = await acquire(replacementTrigger)
assert.equal(afterReplacement.status, 200, JSON.stringify(afterReplacement.body))
assert.equal(requestState(replacedRequest.requestId).split('|')[0], 'expired', 'a due receipt with a replaced current token must become terminal')
assert.equal(database(`SELECT token_hash FROM checkouts WHERE file_id='${replacedLeaseFile.id}'`), replacementTokenHash, 'the sweeper must preserve a different current checkout token')
database(`DELETE FROM checkouts WHERE file_id='${replacedLeaseFile.id}' AND token_hash='${replacementTokenHash}'`)
await cancel(replacementTrigger, afterReplacement.body.checkoutToken)
ageRequest(replacedRequest.requestId)
ageRequest(afterReplacement.requestId)

// A user's own aged lease in another organization can be swept under the
// global-user scope, but unrelated users' receipts in that foreign tenant must
// remain untouched until an acquisition in their own organization sweeps them.
database(`INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${secondaryOrgId}', '${member.body.id}', 'member')`)
const scopedSweepFile = await createFile('global-user-sweep-scope', secondaryVault.body.id, secondaryToken)
const scopedSweepOwnLease = await acquire(scopedSweepFile, secondaryToken)
assert.equal(scopedSweepOwnLease.status, 200, JSON.stringify(scopedSweepOwnLease.body))
const unrelatedDueRequestId = randomUUID()
const unrelatedDueTokenHash = createHash('sha256').update(randomUUID()).digest('hex')
const unrelatedDueFingerprint = createHash('sha256').update(randomUUID()).digest('hex')
database(`INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES ('${secondaryOrgId}', '${unrelatedDueRequestId}', '${member.body.id}', '${scopedSweepFile.id}', '${secondaryVault.body.id}', 'unrelated-user-due-receipt', '${unrelatedDueFingerprint}', '${unrelatedDueTokenHash}', NULL, DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND), 'active')`)
database(`UPDATE checkouts SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id='${scopedSweepFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${secondaryOrgId}' AND request_id='${scopedSweepOwnLease.requestId}'`)
const globalUserSweepTrigger = await createFile('global-user-sweep-trigger')
const afterGlobalUserSweep = await acquire(globalUserSweepTrigger, ownerToken)
assert.equal(afterGlobalUserSweep.status, 200, JSON.stringify(afterGlobalUserSweep.body))
assert.equal(requestState(scopedSweepOwnLease.requestId, secondaryOrgId).split('|')[0], 'expired', 'global-user maintenance may terminalize the caller’s own expired lease across organizations')
assert.equal(requestState(unrelatedDueRequestId, secondaryOrgId).split('|')[0], 'active', 'global-user maintenance must not terminalize another user’s receipt in a foreign organization')
assert.equal(database(`SELECT COUNT(*) FROM checkouts WHERE file_id='${scopedSweepFile.id}'`), '0', 'global-user maintenance may remove only the caller’s expired checkout')
const organizationScopeTrigger = await createFile('organization-scope-sweep-trigger', secondaryVault.body.id, secondaryToken)
const afterOrganizationScopeSweep = await acquire(organizationScopeTrigger, secondaryToken)
assert.equal(afterOrganizationScopeSweep.status, 200, JSON.stringify(afterOrganizationScopeSweep.body))
assert.equal(requestState(unrelatedDueRequestId, secondaryOrgId).split('|')[0], 'expired', 'maintenance in the owning organization may terminalize that organization’s expired receipts')
await cancel(globalUserSweepTrigger, afterGlobalUserSweep.body.checkoutToken)
await cancel(organizationScopeTrigger, afterOrganizationScopeSweep.body.checkoutToken, secondaryToken)
ageRequest(scopedSweepOwnLease.requestId, secondaryOrgId)
ageRequest(unrelatedDueRequestId, secondaryOrgId)
ageRequest(afterGlobalUserSweep.requestId)
ageRequest(afterOrganizationScopeSweep.requestId, secondaryOrgId)

// Quota enforcement still rejects a new request without creating a lease or
// request row; expired-state maintenance above is a separate committed pass.
const expiredQuotaFile = await createFile('expired-lease-quota-reject')
const retained = await acquire(expiredQuotaFile)
assert.equal(retained.status, 200, JSON.stringify(retained.body))
await cancel(expiredQuotaFile, retained.body.checkoutToken)
const expiredQuotaLease = await acquire(expiredQuotaFile)
assert.equal(expiredQuotaLease.status, 200, JSON.stringify(expiredQuotaLease.body))
database(`UPDATE checkouts SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id='${expiredQuotaFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${expiredQuotaLease.requestId}'`)
const expiredQuotaBefore = database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${expiredQuotaFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${expiredQuotaFile.id}')) FROM files WHERE id='${expiredQuotaFile.id}'`)
const expiredQuotaDenied = await acquire(expiredQuotaFile)
assert.equal(expiredQuotaDenied.status, 429, JSON.stringify(expiredQuotaDenied.body))
assert.equal(database(`SELECT CONCAT(current_revision, '|', (SELECT COUNT(*) FROM checkouts WHERE file_id='${expiredQuotaFile.id}'), '|', (SELECT COUNT(*) FROM checkout_acquisition_requests WHERE file_id='${expiredQuotaFile.id}')) FROM files WHERE id='${expiredQuotaFile.id}'`), `${expiredQuotaBefore.split('|')[0]}|0|2`, 'quota rejection creates no new checkout/request after expired-lease maintenance')
assert.equal(requestState(expiredQuotaLease.requestId).split('|')[0], 'expired', 'maintenance terminalizes the expired lease without creating a new journal row')

// User removal, renewal, and an organization-scoped sweep follow a compatible
// lifecycle: the sweep's stable file order must not deadlock removal's own
// user-lifecycle -> ascending-file cleanup path.
database("UPDATE checkout_acquisition_requests SET updated_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE status <> 'active'")
const removalLeaseFile = await createFile('member-removal-expired-lease')
const removalLease = await acquire(removalLeaseFile, memberLogin.body.token)
assert.equal(removalLease.status, 200, JSON.stringify(removalLease.body))
database(`UPDATE checkouts SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id='${removalLeaseFile.id}'`)
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${organizationId}' AND request_id='${removalLease.requestId}'`)
const concurrentRenewFile = await createFile('concurrent-removal-renewal', secondaryVault.body.id, secondaryToken)
const concurrentRenew = await acquire(concurrentRenewFile, secondaryToken)
assert.equal(concurrentRenew.status, 200, JSON.stringify(concurrentRenew.body))
database(`UPDATE checkout_acquisition_requests SET expires_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE organization_id='${secondaryOrgId}' AND request_id='${concurrentRenew.requestId}'`)
const removalSweepTrigger = await createFile('concurrent-removal-sweep-trigger', secondaryVault.body.id, secondaryToken)
const removalHolder = spawnFileLockHolder(concurrentRenewFile.id, secondaryOrgId)
await waitForLine(removalHolder, 'READY')
const renewDuringRemovalPromise = request(`/files/${concurrentRenewFile.id}/checkout/renew`, { method: 'POST', body: JSON.stringify({ checkoutToken: concurrentRenew.body.checkoutToken }) }, secondaryToken)
const sweepDuringRemovalPromise = acquire(removalSweepTrigger, secondaryToken)
const removeMemberPromise = request(`/users/${member.body.id}`, { method: 'DELETE' }, ownerToken)
try {
  await waitForDatabaseLockWait(20000, 2)
} finally {
  if (removalHolder.child.exitCode === null) {
    removalHolder.child.stdin.on('error', () => {})
    removalHolder.child.stdin.write('continue\n')
  }
  await removalHolder.done
}
const [removedMember, sweepDuringRemoval, renewedDuringRemoval] = await Promise.all([
  removeMemberPromise,
  sweepDuringRemovalPromise,
  renewDuringRemovalPromise,
])
assert.equal(removedMember.status, 204, JSON.stringify(removedMember.body))
assert.equal(sweepDuringRemoval.status, 200, JSON.stringify(sweepDuringRemoval.body))
assert.equal(renewedDuringRemoval.status, 200, JSON.stringify(renewedDuringRemoval.body))
assert.notEqual(requestState(removalLease.requestId).split('|')[0], 'active', 'user removal and sweep must terminalize, not retain, the removed member lease')
const postRemovalReplay = await acquire(concurrentRenewFile, secondaryToken, concurrentRenew.requestId)
assert.equal(postRemovalReplay.status, 200, JSON.stringify(postRemovalReplay.body))
assert.equal(postRemovalReplay.body.checkoutToken, concurrentRenew.body.checkoutToken, 'renewal must remain recoverable despite concurrent removal and expiry sweep')
await cancel(concurrentRenewFile, concurrentRenew.body.checkoutToken, secondaryToken)
await cancel(removalSweepTrigger, sweepDuringRemoval.body.checkoutToken, secondaryToken)
console.log('Checkout request retention, quotas, orphan/expired lease recovery, replay, UTC clock, and renewal/removal races passed.')
