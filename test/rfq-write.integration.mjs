import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const runnerRoot = resolve(fileURLToPath(new URL('..', import.meta.url)))
const processRoot = resolve(process.cwd())
const expectedRoot = process.env.BLUEPLM_RFQ_EXPECTED_ROOT ? resolve(process.env.BLUEPLM_RFQ_EXPECTED_ROOT) : null
const gitRoot = resolve(execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: process.cwd(), encoding: 'utf8' }).trim())
assert.equal(processRoot, runnerRoot, 'Node process cwd must match the integration test repository root.')
if (expectedRoot) assert.equal(processRoot, expectedRoot, 'Runner cwd must match the externally verified repository root.')
assert.equal(gitRoot, runnerRoot, 'Git top-level must match the integration test repository root.')

const serverValue = process.env.BLUEPLM_PHP_TEST_SERVER
const composeOverride = process.env.BLUEPLM_RFQ_TEST_COMPOSE_OVERRIDE
const composeProject = process.env.COMPOSE_PROJECT_NAME
const docker = process.env.BLUEPLM_DOCKER_EXE
assert.ok(serverValue, 'Test API loopback URL is required.')
assert.ok(composeOverride, 'External Compose override path is required.')
assert.ok(composeProject, 'An isolated Compose project name is required.')
assert.ok(docker, 'Use the verified absolute Docker executable path.')
const serverUrl = new URL(serverValue)
assert.equal(serverUrl.protocol, 'http:')
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(serverUrl.hostname.toLowerCase()))
assert.ok(serverUrl.port !== '' && Number.isInteger(Number(serverUrl.port)) && Number(serverUrl.port) >= 1 && Number(serverUrl.port) <= 65535)
assert.equal(serverUrl.pathname, '/')
assert.equal(serverUrl.username, '')
assert.equal(serverUrl.password, '')
assert.equal(serverUrl.search, '')
assert.equal(serverUrl.hash, '')
const server = serverUrl.origin

const compose = ['compose', '-f', 'docker-compose.test.yml', '-f', composeOverride, '-p', composeProject]
function composeExec(service, args, extraEnv = {}) {
  const envArgs = Object.entries(extraEnv).flatMap(([key, value]) => ['-e', `${key}=${value}`])
  return execFileSync(docker, [...compose, 'exec', '-T', ...envArgs, service, ...args], {
    cwd: process.cwd(), encoding: 'utf8', env: process.env, stdio: ['ignore', 'pipe', 'pipe'],
  })
}

async function requestStatus(path, init = {}, token) {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(`${server}${path}`, { ...init, headers })
  const body = response.status === 204 ? undefined : await response.json()
  return { status: response.status, body }
}

async function request(path, init = {}, token) {
  const result = await requestStatus(path, init, token)
  assert.ok(result.status >= 200 && result.status < 300, `${init.method ?? 'GET'} ${path} returned ${result.status}: ${JSON.stringify(result.body)}`)
  return result.body
}

async function login(email, password) {
  return request('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) })
}

const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const ownerPassword = 'RFQ draft owner password 123!'
const memberPassword = 'RFQ draft member password 123!'
const blockedPassword = 'RFQ draft blocked password 123!'
const viewerPassword = 'RFQ draft viewer password 123!'
composeExec('api', ['sh', '-c', 'cp /app/test/install-env.fixture /app/.env.install && chown www-data:www-data /app/.env.install'])
await request('/health')
const installed = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken, action: 'install',
    bootstrap: { organizationName: 'RFQ Draft Integration', organizationSlug: 'rfq-draft-integration', email: 'rfq-draft-owner@example.test', displayName: 'RFQ Draft Owner', password: ownerPassword },
  }),
})
assert.equal(installed.bootstrapped, true)
composeExec('api', ['php', 'test/rfq-read-migration.php'])

const ownerSession = await login('rfq-draft-owner@example.test', ownerPassword)
const ownerToken = ownerSession.token
const owner = await request('/auth/me', {}, ownerToken)
const organization = await request('/organizations/current', {}, ownerToken)
async function createUser(email, password, role = 'member') {
  return request('/users', { method: 'POST', body: JSON.stringify({ email, displayName: email.split('@')[0], password, role }) }, ownerToken)
}
const member = await createUser('rfq-draft-member@example.test', memberPassword)
const blocked = await createUser('rfq-draft-blocked@example.test', blockedPassword)
const viewer = await createUser('rfq-draft-viewer@example.test', viewerPassword, 'viewer')
const memberSession = await login('rfq-draft-member@example.test', memberPassword)
const blockedSession = await login('rfq-draft-blocked@example.test', blockedPassword)
const viewerSession = await login('rfq-draft-viewer@example.test', viewerPassword)
const fixtureJson = composeExec('api', ['php', 'test/rfq-write-fixtures.php'], {
  BLUEPLM_RFQ_WRITE_FIXTURE_ORG_ID: organization.organization.id,
  BLUEPLM_RFQ_WRITE_FIXTURE_OWNER_ID: owner.user.userId,
  BLUEPLM_RFQ_WRITE_FIXTURE_MEMBER_ID: member.id,
})
const fixtures = JSON.parse(fixtureJson)
await request('/module-access/rfq', { method: 'PUT', body: JSON.stringify({ teamIds: [], userIds: [member.id] }) }, ownerToken)

assert.equal((await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ rfqNumber: 'NO-AUTH', title: 'No auth' }) })).status, 401)
const denied = await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ rfqNumber: 'DENIED', title: 'Denied' }) }, blockedSession.token)
assert.equal(denied.status, 403)
assert.equal(denied.body.error, 'MODULE_ACCESS_DENIED')
const deniedUpdate = await requestStatus(`/rfqs/${fixtures.draftRfq}`, { method: 'PATCH', body: JSON.stringify({ requiresSamples: true }) }, blockedSession.token)
assert.equal(deniedUpdate.status, 403)
assert.equal(deniedUpdate.body.error, 'MODULE_ACCESS_DENIED')
const readOnly = await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ rfqNumber: 'VIEWER', title: 'Viewer' }) }, viewerSession.token)
assert.equal(readOnly.status, 403)
assert.equal(readOnly.body.error, 'READ_ONLY_ROLE')
const readOnlyItem = await requestStatus(`/rfqs/${fixtures.draftRfq}/items/${fixtures.existingItem}`, { method: 'DELETE' }, viewerSession.token)
assert.equal(readOnlyItem.status, 403)
assert.equal(readOnlyItem.body.error, 'READ_ONLY_ROLE')

const clientNumber = await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ rfqNumber: 'CLIENT-NUMBER', title: 'Client number must be rejected' }) }, ownerToken)
assert.equal(clientNumber.status, 400)
assert.equal(clientNumber.body.error, 'INVALID_REQUEST')
const oversizedBody = await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ title: 'Too large', description: 'x'.repeat(262145) }) }, ownerToken)
assert.equal(oversizedBody.status, 413)
assert.equal(oversizedBody.body.error, 'REQUEST_TOO_LARGE')
const createdRfq = await request('/rfqs', { method: 'POST', body: JSON.stringify({ title: 'Generated RFQ', description: 'Generated on the server' }) }, ownerToken)
assert.match(createdRfq.rfq.rfqNumber, /^RFQ-\d{4}-\d{4}$/)
assert.equal(createdRfq.rfq.title, 'Generated RFQ')
assert.equal(createdRfq.rfq.description, 'Generated on the server')
const numberYear = createdRfq.rfq.rfqNumber.slice(4, 8)
assert.equal(createdRfq.rfq.rfqNumber, `RFQ-${numberYear}-0008`)
const concurrentRfqs = await Promise.all([1, 2].map((index) => request('/rfqs', {
  method: 'POST', body: JSON.stringify({ title: `Concurrent RFQ ${index}` }),
}, ownerToken)))
const concurrentNumbers = concurrentRfqs.map((result) => result.rfq.rfqNumber).sort()
assert.deepEqual(concurrentNumbers, [`RFQ-${numberYear}-0009`, `RFQ-${numberYear}-0010`])

const foreignOwnerToken = fixtures.foreignOwnerToken
const foreignOrganization = await request('/organizations/current', {}, foreignOwnerToken)
assert.equal(foreignOrganization.organization.id, fixtures.foreignOrg)
const foreignGenerated = await request('/rfqs', { method: 'POST', body: JSON.stringify({ title: 'Foreign organization RFQ' }) }, foreignOwnerToken)
assert.equal(foreignGenerated.rfq.rfqNumber, `RFQ-${numberYear}-0008`)

const conflictNumber = `RFQ-${numberYear}-0011`
composeExec('api', ['php', '-r', '$root=getcwd(); require "src/Runtime.php"; $env=BluePlm\\Runtime::env($root . "/.env"); $db=BluePlm\\Runtime::database($env); $db->prepare("INSERT INTO rfqs (id, organization_id, rfq_number, title, status, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([BluePlm\\Runtime::uuid(), getenv("BLUEPLM_RFQ_WRITE_ORG_ID"), getenv("BLUEPLM_RFQ_WRITE_NUMBER"), "Synthetic conflict", "draft", getenv("BLUEPLM_RFQ_WRITE_OWNER_ID"), getenv("BLUEPLM_RFQ_WRITE_OWNER_ID")]);'], {
  BLUEPLM_RFQ_WRITE_ORG_ID: organization.organization.id,
  BLUEPLM_RFQ_WRITE_NUMBER: conflictNumber,
  BLUEPLM_RFQ_WRITE_OWNER_ID: owner.user.userId,
})
const rolledBackCreate = await requestStatus('/rfqs', { method: 'POST', body: JSON.stringify({ title: 'Counter rollback request' }) }, ownerToken)
assert.equal(rolledBackCreate.status, 409)
assert.equal(rolledBackCreate.body.error, 'RFQ_NUMBER_CONFLICT')
composeExec('api', ['php', '-r', '$root=getcwd(); require "src/Runtime.php"; $env=BluePlm\\Runtime::env($root . "/.env"); $db=BluePlm\\Runtime::database($env); $db->prepare("DELETE FROM rfqs WHERE organization_id = ? AND rfq_number = ?")->execute([getenv("BLUEPLM_RFQ_WRITE_ORG_ID"), getenv("BLUEPLM_RFQ_WRITE_NUMBER")]);'], {
  BLUEPLM_RFQ_WRITE_ORG_ID: organization.organization.id,
  BLUEPLM_RFQ_WRITE_NUMBER: conflictNumber,
})
const afterRollback = await request('/rfqs', { method: 'POST', body: JSON.stringify({ title: 'After rollback' }) }, ownerToken)
assert.equal(afterRollback.rfq.rfqNumber, conflictNumber)

const rfqId = fixtures.draftRfq
const patched = await request(`/rfqs/${rfqId}`, {
  method: 'PATCH',
  body: JSON.stringify({ billingAddressId: fixtures.billingAddress, shippingAddressId: fixtures.shippingAddress, requiresSamples: true, requiresFirstArticle: true, requiresQualityReport: false }),
}, memberSession.token)
assert.equal(patched.rfq.status, 'draft')
assert.deepEqual(patched.rfq.updated, ['billing_address_id', 'shipping_address_id', 'requires_samples', 'requires_first_article', 'requires_quality_report'])
const afterHeaderPatch = await request(`/rfqs/${rfqId}`, {}, memberSession.token)
assert.equal(afterHeaderPatch.rfq.billingAddressId, fixtures.billingAddress)
assert.equal(afterHeaderPatch.rfq.shippingAddressId, fixtures.shippingAddress)
assert.equal(afterHeaderPatch.rfq.requiresSamples, true)
const rollbackResponse = await requestStatus(`/rfqs/${rfqId}`, { method: 'PATCH', body: JSON.stringify({ billingAddressId: fixtures.foreignAddress }) }, memberSession.token)
assert.equal(rollbackResponse.status, 400)
assert.equal((await request(`/rfqs/${rfqId}`, {}, memberSession.token)).rfq.billingAddressId, fixtures.billingAddress)
assert.equal((await requestStatus(`/rfqs/${fixtures.foreignRfq}`, { method: 'PATCH', body: JSON.stringify({ billingAddressId: fixtures.billingAddress }) }, ownerToken)).status, 404)
assert.equal((await requestStatus(`/rfqs/${fixtures.foreignRfq}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible }) }, ownerToken)).status, 404)
assert.equal((await requestStatus('/rfqs/not-a-uuid', { method: 'PATCH', body: JSON.stringify({ billingAddressId: fixtures.billingAddress }) }, ownerToken)).status, 400)

const createdItem = await request(`/rfqs/${rfqId}/items`, {
  method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible, quantity: 3 }),
}, memberSession.token)
assert.equal(createdItem.item.lineNumber, 2)
assert.equal(createdItem.item.partNumber, 'PART-A')
assert.equal(createdItem.item.description, 'Persisted part A')
assert.equal(createdItem.item.revision, 'B')
assert.equal(createdItem.item.quantity, 3)
const privateItem = await request(`/rfqs/${rfqId}/items`, {
  method: 'POST', body: JSON.stringify({ fileId: fixtures.filePrivate }),
}, ownerToken)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${privateItem.item.id}`, { method: 'PATCH', body: JSON.stringify({ quantity: 2 }) }, memberSession.token)).status, 404)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${privateItem.item.id}`, { method: 'DELETE' }, memberSession.token)).status, 204)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.filePrivate }) }, memberSession.token)).status, 404)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileForeign }) }, ownerToken)).status, 404)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileDeleted }) }, memberSession.token)).status, 404)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible, stepFilePath: 'untrusted/path' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible, partNumber: 'FORGED' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible, uom: 'set' }) }, ownerToken)).body.error, 'INVALID_REQUEST')

const itemId = createdItem.item.id
composeExec('api', ['php', '-r', '$root=getcwd(); require "src/Runtime.php"; $env=BluePlm\\Runtime::env($root . "/.env"); $db=BluePlm\\Runtime::database($env); $db->prepare("UPDATE files SET part_number=?, description=?, revision_label=? WHERE id=?")->execute(["PART-A-CURRENT", "Persisted part A current", "C", getenv("BLUEPLM_RFQ_WRITE_FILE_ID")]);'], {
  BLUEPLM_RFQ_WRITE_FILE_ID: fixtures.fileAccessible,
})
const refreshedItem = await request(`/rfqs/${rfqId}/items/${itemId}`, {
  method: 'PATCH', body: JSON.stringify({ quantity: 5 }),
}, memberSession.token)
assert.equal(refreshedItem.item.partNumber, 'PART-A')
assert.equal(refreshedItem.item.description, 'Persisted part A')
assert.equal(refreshedItem.item.revision, 'B')
const updatedItem = await request(`/rfqs/${rfqId}/items/${itemId}`, {
  method: 'PATCH', body: JSON.stringify({ quantity: 8, material: 'Steel', finish: 'Anodized', toleranceClass: 'A', notes: 'updated', swConfiguration: 'Config B' }),
}, memberSession.token)
assert.equal(updatedItem.item.partNumber, 'PART-A')
assert.equal(updatedItem.item.description, 'Persisted part A')
assert.equal(updatedItem.item.revision, 'B')
assert.equal(updatedItem.item.quantity, 8)
assert.equal(updatedItem.item.material, 'Steel')
assert.equal(updatedItem.item.finish, 'Anodized')
assert.equal(updatedItem.item.toleranceClass, 'A')
assert.equal(updatedItem.item.notes, 'updated')
assert.equal(updatedItem.item.swConfiguration, 'Config B')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ partNumber: 'FORGED' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ specialRequirements: 'Not exposed in UI' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ uom: 'set' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ fileId: fixtures.fileAccessible }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}`, { method: 'PATCH', body: JSON.stringify({ dueDate: '2026-12-31' }) }, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ quantity: 0 }) }, ownerToken)).status, 400)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ quantity: 1.5 }) }, ownerToken)).status, 400)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ quantity: 4294967296 }) }, ownerToken)).status, 400)
assert.equal((await request(`/rfqs/${fixtures.draftRfq}/items/${fixtures.existingItem}`, { method: 'DELETE' }, ownerToken)), undefined)
composeExec('api', ['php', '-r', '$root=getcwd(); require "src/Runtime.php"; $env=BluePlm\\Runtime::env($root . "/.env"); $db=BluePlm\\Runtime::database($env); $db->prepare("UPDATE files SET deleted_at=UTC_TIMESTAMP(3) WHERE id=?")->execute([getenv("BLUEPLM_RFQ_WRITE_FILE_ID")]);'], {
  BLUEPLM_RFQ_WRITE_FILE_ID: fixtures.fileAccessible,
})
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'PATCH', body: JSON.stringify({ quantity: 9 }) }, ownerToken)).status, 404)
assert.equal((await requestStatus(`/rfqs/${fixtures.sentRfq}`, { method: 'PATCH', body: JSON.stringify({ billingAddressId: fixtures.billingAddress }) }, ownerToken)).status, 409)
assert.equal((await requestStatus(`/rfqs/${fixtures.sentRfq}/items`, { method: 'POST', body: JSON.stringify({ fileId: fixtures.fileAccessible }) }, ownerToken)).status, 409)
assert.equal((await requestStatus(`/rfqs/${fixtures.sentRfq}/items/${fixtures.existingItem}`, { method: 'PATCH', body: JSON.stringify({ quantity: 5 }) }, ownerToken)).status, 409)
assert.equal((await requestStatus(`/rfqs/${fixtures.sentRfq}/items/${fixtures.existingItem}`, { method: 'DELETE' }, ownerToken)).status, 409)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'DELETE' }, memberSession.token)).status, 204)
assert.equal((await requestStatus(`/rfqs/${rfqId}/items/${itemId}`, { method: 'DELETE' }, ownerToken)).status, 404)

console.log('RFQ draft and item write integration: ok')
