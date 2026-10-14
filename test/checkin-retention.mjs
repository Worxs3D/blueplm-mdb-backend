import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'

const project = process.env.COMPOSE_PROJECT_NAME
const server = process.env.BLUEPLM_PHP_TEST_SERVER
assert.ok(project && server, 'Use an isolated compose project and explicit test server URL.')

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

function database(sql) {
  return execFileSync('docker', [
    'compose', '-p', project, '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
}

function databaseAsRoot(sql) {
  return execFileSync('docker', [
    'compose', '-p', project, '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-uroot', '-proot-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
}

function fileRow(fileId) {
  const row = database(`SELECT CONCAT(current_revision, '|', canonical_path) FROM files WHERE id='${fileId}'`)
  return row.split('|')
}

function count(sql) {
  return Number(database(sql))
}

const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Retention owner password 123!'
const installed = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken,
    action: 'install',
    bootstrap: {
      organizationName: 'Check-in Retention Org',
      organizationSlug: 'checkin-retention-org',
      email: 'retention-owner@example.test',
      displayName: 'Retention Owner',
      password,
    },
  }),
})
assert.equal(installed.status, 200, JSON.stringify(installed.body))
const ownerToken = installed.body.token
const organizationId = database("SELECT id FROM organizations WHERE slug='checkin-retention-org'")
const ownerId = database("SELECT id FROM users WHERE email='retention-owner@example.test'")
const vault = await request('/vaults', {
  method: 'POST',
  body: JSON.stringify({ name: 'Retention Vault', networkRoot: 'retention-root' }),
}, ownerToken)
assert.equal(vault.status, 201, JSON.stringify(vault.body))

const member = await request('/users', {
  method: 'POST',
  body: JSON.stringify({
    email: 'retention-member@example.test',
    displayName: 'Retention Member',
    password: 'Retention member password 123!',
  }),
}, ownerToken)
assert.equal(member.status, 201, JSON.stringify(member.body))
await request(`/users/${member.body.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.body.id] }),
}, ownerToken)
const memberLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'retention-member@example.test', password: 'Retention member password 123!' }),
})
assert.equal(memberLogin.status, 200, JSON.stringify(memberLogin.body))
const memberToken = memberLogin.body.token
const member2 = await request('/users', {
  method: 'POST',
  body: JSON.stringify({
    email: 'retention-member-two@example.test',
    displayName: 'Retention Member Two',
    password: 'Retention member two password 123!',
  }),
}, ownerToken)
assert.equal(member2.status, 201, JSON.stringify(member2.body))
await request(`/users/${member2.body.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.body.id] }),
}, ownerToken)
const member2Login = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'retention-member-two@example.test', password: 'Retention member two password 123!' }),
})
assert.equal(member2Login.status, 200, JSON.stringify(member2Login.body))
const member2Token = member2Login.body.token

async function createFile(name, targetVault = vault.body.id, token = ownerToken) {
  const response = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: targetVault,
      canonicalPath: `${name}.sldprt`,
      fileName: `${name}.sldprt`,
      storageRelativePath: `${name}.sldprt`,
    }),
  }, token)
  assert.equal(response.status, 201, JSON.stringify(response.body))
  return { ...response.body, fileName: `${name}.sldprt` }
}

async function checkout(file, token) {
  const response = await request(`/files/${file.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: `retention/${file.id}` }),
  }, token)
  assert.equal(response.status, 200, JSON.stringify(response.body))
  return response.body.checkoutToken
}

function metadataBody(file, checkoutToken, requestId = randomUUID()) {
  return {
    mode: 'metadata-only', requestId, checkoutToken,
    canonicalPath: `${file.fileName}`,
    fileName: file.fileName,
  }
}

async function successfulCheckin(file, token, requestId = randomUUID()) {
  const checkoutToken = await checkout(file, token)
  const body = metadataBody(file, checkoutToken, requestId)
  const result = await request(`/files/${file.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, token)
  assert.equal(result.status, 200, JSON.stringify(result.body))
  return { body, result: result.body }
}

async function rejectedCheckin(file, token, expectedScope) {
  const checkoutToken = await checkout(file, token)
  const body = metadataBody(file, checkoutToken)
  const before = fileRow(file.id)
  const revisionCount = count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${file.id}'`)
  const journalCount = count(`SELECT COUNT(*) FROM file_checkin_requests WHERE request_id='${body.requestId}'`)
  const response = await request(`/files/${file.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, token)
  assert.equal(response.status, 429, JSON.stringify(response.body))
  assert.equal(response.body.error, 'CHECKIN_RECEIPT_QUOTA_EXCEEDED')
  if (expectedScope) assert.match(response.body.message, new RegExp(expectedScope, 'u'))
  assert.deepEqual(fileRow(file.id), before)
  assert.equal(count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${file.id}'`), revisionCount)
  assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE request_id='${body.requestId}'`), journalCount)
  assert.equal(count(`SELECT COUNT(*) FROM checkouts WHERE file_id='${file.id}'`), 1, 'quota rejection must preserve the checkout lease')
  return { body, checkoutToken }
}

const perFile = await createFile('per-file-limit')
const originalGlobalTimeZone = databaseAsRoot('SELECT @@GLOBAL.time_zone')
let first
databaseAsRoot("SET GLOBAL time_zone = '+09:00'")
try {
  first = await successfulCheckin(perFile, ownerToken)
  const [receiptOffset, sessionOffset] = databaseAsRoot(`SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), created_at), TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), NOW()) FROM file_checkin_requests WHERE organization_id='${organizationId}' AND request_id='${first.body.requestId}'`).split('\t').map(Number)
  assert.ok(Math.abs(receiptOffset) <= 2, `new receipt timestamp must use UTC regardless of session timezone (offset ${receiptOffset}s)`)
  assert.ok(sessionOffset >= 8 * 60 * 60, `the regression must use a non-UTC session (offset ${sessionOffset}s)`)
} finally {
  databaseAsRoot(`SET GLOBAL time_zone = '${originalGlobalTimeZone.replaceAll("'", "''")}'`)
}
await successfulCheckin(perFile, ownerToken)
const replayAtFileLimit = await request(`/files/${perFile.id}/checkin`, {
  method: 'POST', body: JSON.stringify(first.body),
}, ownerToken)
assert.equal(replayAtFileLimit.status, 200, JSON.stringify(replayAtFileLimit.body))
assert.equal(replayAtFileLimit.body.replayed, true)
const fileLimitFailure = await rejectedCheckin(perFile, ownerToken, 'file')

const userFile = await createFile('global-user-limit')
await successfulCheckin(userFile, ownerToken)
await successfulCheckin(userFile, ownerToken)
assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE user_id='${ownerId}' AND created_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)`), 4)

const secondOrganizationId = randomUUID()
const crossOrganizationRequestId = randomUUID()
database(`INSERT INTO organizations (id, name, slug) VALUES ('${secondOrganizationId}', 'Retention Secondary Org', 'retention-secondary-org'); INSERT INTO file_checkin_requests (request_id, organization_id, file_id, user_id, request_fingerprint, response, created_at) VALUES ('${crossOrganizationRequestId}', '${secondOrganizationId}', '${perFile.id}', '${ownerId}', REPEAT('a', 64), '{}', UTC_TIMESTAMP(3))`)
assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE user_id='${ownerId}' AND created_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)`), 5)
const userLimitFile = await createFile('cross-org-user-limit')
await rejectedCheckin(userLimitFile, ownerToken, 'user')

const exactEdge = first.body.requestId
database(`UPDATE file_checkin_requests SET created_at=DATE_ADD(DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY), INTERVAL 5 MINUTE) WHERE organization_id='${organizationId}' AND request_id='${exactEdge}'`)
const edgeReplay = await request(`/files/${perFile.id}/checkin`, { method: 'POST', body: JSON.stringify(first.body) }, ownerToken)
assert.equal(edgeReplay.status, 200, JSON.stringify(edgeReplay.body))
assert.equal(edgeReplay.body.replayed, true, 'a receipt inside the TTL must replay')
database(`UPDATE file_checkin_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY) WHERE organization_id='${organizationId}' AND request_id='${exactEdge}'`)
const boundaryReplay = await request(`/files/${perFile.id}/checkin`, { method: 'POST', body: JSON.stringify(first.body) }, ownerToken)
assert.equal(boundaryReplay.status, 409, JSON.stringify(boundaryReplay.body))
assert.equal(boundaryReplay.body.error, 'INVALID_CHECKOUT', 'a receipt exactly at the TTL boundary is expired')

database(`UPDATE file_checkin_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE user_id='${ownerId}'`)
const expiredRetry = metadataBody(perFile, fileLimitFailure.checkoutToken, first.body.requestId)
const expiredRequest = await request(`/files/${perFile.id}/checkin`, { method: 'POST', body: JSON.stringify(expiredRetry) }, ownerToken)
assert.equal(expiredRequest.status, 200, JSON.stringify(expiredRequest.body))
assert.equal(expiredRequest.body.replayed, false, 'an expired request ID is handled as a new check-in')

database(`UPDATE file_checkin_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE organization_id='${organizationId}'`)
database(`INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${secondOrganizationId}', '${ownerId}', 'owner')`)
const secondOrganizationToken = randomUUID()
const secondOrganizationTokenHash = createHash('sha256')
  .update(`test-session-secret-must-be-at-least-thirty-two-characters:${secondOrganizationToken}`)
  .digest('hex')
database(`INSERT INTO sessions (token_hash, user_id, organization_id, expires_at) VALUES ('${secondOrganizationTokenHash}', '${ownerId}', '${secondOrganizationId}', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 DAY))`)
const secondOrganizationVault = await request('/vaults', {
  method: 'POST', body: JSON.stringify({ name: 'Retention Secondary Vault', networkRoot: 'retention-secondary-root' }),
}, secondOrganizationToken)
assert.equal(secondOrganizationVault.status, 201, JSON.stringify(secondOrganizationVault.body))
const crossOrgRaceFileA = await createFile('global-user-race-a', vault.body.id, ownerToken)
const crossOrgRaceFileB = await createFile('global-user-race-b', secondOrganizationVault.body.id, secondOrganizationToken)
await successfulCheckin(crossOrgRaceFileA, ownerToken)
await successfulCheckin(crossOrgRaceFileA, ownerToken)
await successfulCheckin(crossOrgRaceFileB, secondOrganizationToken)
await successfulCheckin(crossOrgRaceFileB, secondOrganizationToken)
const crossOrgRaceFileC = await createFile('global-user-race-c', vault.body.id, ownerToken)
const crossOrgRaceFileD = await createFile('global-user-race-d', secondOrganizationVault.body.id, secondOrganizationToken)
const crossOrgRaced = await Promise.all([
  (async () => {
    const checkoutToken = await checkout(crossOrgRaceFileC, ownerToken)
    const body = metadataBody(crossOrgRaceFileC, checkoutToken)
    const before = fileRow(crossOrgRaceFileC.id)
    const revisions = count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${crossOrgRaceFileC.id}'`)
    const response = await request(`/files/${crossOrgRaceFileC.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, ownerToken)
    return { file: crossOrgRaceFileC, body, before, revisions, response }
  })(),
  (async () => {
    const checkoutToken = await checkout(crossOrgRaceFileD, secondOrganizationToken)
    const body = metadataBody(crossOrgRaceFileD, checkoutToken)
    const before = fileRow(crossOrgRaceFileD.id)
    const revisions = count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${crossOrgRaceFileD.id}'`)
    const response = await request(`/files/${crossOrgRaceFileD.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, secondOrganizationToken)
    return { file: crossOrgRaceFileD, body, before, revisions, response }
  })(),
])
assert.deepEqual(crossOrgRaced.map((entry) => entry.response.status).sort(), [200, 429])
const deniedCrossOrgRace = crossOrgRaced.find((entry) => entry.response.status === 429)
assert.equal(deniedCrossOrgRace.response.body.error, 'CHECKIN_RECEIPT_QUOTA_EXCEEDED')
assert.match(deniedCrossOrgRace.response.body.message, /user/u)
assert.deepEqual(fileRow(deniedCrossOrgRace.file.id), deniedCrossOrgRace.before)
assert.equal(count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${deniedCrossOrgRace.file.id}'`), deniedCrossOrgRace.revisions)
assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE request_id='${deniedCrossOrgRace.body.requestId}'`), 0)
assert.equal(count(`SELECT COUNT(*) FROM checkouts WHERE file_id='${deniedCrossOrgRace.file.id}'`), 1)

database(`UPDATE file_checkin_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE organization_id='${organizationId}'`)
const memberFile = await createFile('organization-race-a')
const memberFile2 = await createFile('organization-race-b')
const memberFile3 = await createFile('organization-race-c')
await successfulCheckin(memberFile, memberToken)
await successfulCheckin(memberFile2, memberToken)
await successfulCheckin(memberFile3, member2Token)
const memberFile4 = await createFile('organization-race-d')
await successfulCheckin(memberFile4, member2Token)
const memberFile5 = await createFile('organization-race-e')
const raced = await Promise.all([
  (async () => {
    const checkoutToken = await checkout(memberFile4, memberToken)
    const body = metadataBody(memberFile4, checkoutToken)
    const before = fileRow(memberFile4.id)
    const revisions = count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${memberFile4.id}'`)
    const response = await request(`/files/${memberFile4.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, memberToken)
    return { file: memberFile4, body, checkoutToken, before, revisions, response }
  })(),
  (async () => {
    const checkoutToken = await checkout(memberFile5, member2Token)
    const body = metadataBody(memberFile5, checkoutToken)
    const before = fileRow(memberFile5.id)
    const revisions = count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${memberFile5.id}'`)
    const response = await request(`/files/${memberFile5.id}/checkin`, { method: 'POST', body: JSON.stringify(body) }, member2Token)
    return { file: memberFile5, body, checkoutToken, before, revisions, response }
  })(),
])
assert.deepEqual(raced.map((entry) => entry.response.status).sort(), [200, 429])
const deniedRace = raced.find((entry) => entry.response.status === 429)
assert.equal(deniedRace.response.body.error, 'CHECKIN_RECEIPT_QUOTA_EXCEEDED')
assert.match(deniedRace.response.body.message, /organization/u)
assert.deepEqual(fileRow(deniedRace.file.id), deniedRace.before)
assert.equal(count(`SELECT COUNT(*) FROM file_revisions WHERE file_id='${deniedRace.file.id}'`), deniedRace.revisions)
assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE request_id='${deniedRace.body.requestId}'`), 0)
assert.equal(count(`SELECT COUNT(*) FROM checkouts WHERE file_id='${deniedRace.file.id}'`), 1)

database(`INSERT INTO file_checkin_requests (request_id, organization_id, file_id, user_id, request_fingerprint, response, created_at) SELECT UUID(), '${secondOrganizationId}', '${perFile.id}', '${ownerId}', REPEAT('b', 64), '{}', DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 3 DAY) FROM seq_1_to_600`)
database(`UPDATE file_checkin_requests SET created_at=UTC_TIMESTAMP(3) WHERE organization_id='${secondOrganizationId}' AND request_id='${crossOrganizationRequestId}'`)
database(`UPDATE file_checkin_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE organization_id='${organizationId}'`)
const cleanupCheckout = await checkout(perFile, memberToken)
const cleanupRequest = metadataBody(perFile, cleanupCheckout)
const cleanupResult = await request(`/files/${perFile.id}/checkin`, { method: 'POST', body: JSON.stringify(cleanupRequest) }, memberToken)
assert.equal(cleanupResult.status, 200, JSON.stringify(cleanupResult.body))
assert.equal(count(`SELECT COUNT(*) FROM file_checkin_requests WHERE organization_id='${secondOrganizationId}' AND created_at <= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)`), 100)

console.log('check-in receipt TTL, quota, replay, concurrency, and bounded cleanup tests passed')
