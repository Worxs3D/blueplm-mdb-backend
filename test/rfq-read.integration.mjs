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
assert.equal(serverUrl.protocol, 'http:', 'Test API must use HTTP on the isolated loopback interface.')
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(serverUrl.hostname.toLowerCase()), 'Test API must use a loopback hostname.')
assert.ok(serverUrl.port !== '' && Number.isInteger(Number(serverUrl.port)) && Number(serverUrl.port) >= 1 && Number(serverUrl.port) <= 65535, 'Test API must use an explicit valid port.')
assert.equal(serverUrl.pathname, '/', 'Test API URL must not select an application path.')
assert.equal(serverUrl.username, '', 'Test API URL must not include credentials.')
assert.equal(serverUrl.password, '', 'Test API URL must not include credentials.')
assert.equal(serverUrl.search, '', 'Test API URL must not include a query string.')
assert.equal(serverUrl.hash, '', 'Test API URL must not include a fragment.')
const server = serverUrl.origin

const compose = ['compose', '-f', 'docker-compose.test.yml', '-f', composeOverride, '-p', composeProject]
function composeExec(service, args, extraEnv = {}) {
  const envArgs = Object.entries(extraEnv).flatMap(([key, value]) => ['-e', `${key}=${value}`])
  return execFileSync(docker, [...compose, 'exec', '-T', ...envArgs, service, ...args], {
    cwd: process.cwd(),
    encoding: 'utf8',
    env: process.env,
    stdio: ['ignore', 'pipe', 'pipe'],
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
  assert.ok(result.status >= 200 && result.status < 300, `${init.method ?? 'GET'} ${path} returned ${result.status}`)
  return result.body
}

async function login(email, password) {
  return request('/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) })
}

const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const ownerPassword = 'RFQ fixture owner password 123!'
const memberPassword = 'RFQ fixture member password 123!'
const viewerPassword = 'RFQ fixture viewer password 123!'
const guestPassword = 'RFQ fixture guest password 123!'
const blockedPassword = 'RFQ fixture blocked password 123!'

composeExec('api', ['sh', '-c', 'cp /app/test/install-env.fixture /app/.env.install && chown www-data:www-data /app/.env.install'])
await request('/health')
const installed = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken,
    action: 'install',
    bootstrap: {
      organizationName: 'RFQ Integration Organization',
      organizationSlug: 'rfq-integration',
      email: 'rfq-owner@example.test',
      displayName: 'RFQ Owner',
      password: ownerPassword,
    },
  }),
})
assert.equal(installed.bootstrapped, true)
composeExec('api', ['php', 'test/rfq-read-migration.php'])
composeExec('api', ['php', 'tests/rfq-read-route-contract.php'])

const ownerSession = await login('rfq-owner@example.test', ownerPassword)
const ownerToken = ownerSession.token
const owner = await request('/auth/me', {}, ownerToken)
const organization = await request('/organizations/current', {}, ownerToken)

async function createUser(email, displayName, password, role = 'member') {
  return request('/users', {
    method: 'POST',
    body: JSON.stringify({ email, displayName, password, role }),
  }, ownerToken)
}

const member = await createUser('rfq-member@example.test', 'RFQ Member', memberPassword)
const viewer = await createUser('rfq-viewer@example.test', 'RFQ Viewer', viewerPassword, 'viewer')
const guest = await createUser('rfq-guest@example.test', 'RFQ Guest', guestPassword, 'guest')
const blocked = await createUser('rfq-blocked@example.test', 'RFQ Blocked', blockedPassword)
const memberSession = await login('rfq-member@example.test', memberPassword)
const viewerSession = await login('rfq-viewer@example.test', viewerPassword)
const guestSession = await login('rfq-guest@example.test', guestPassword)
const blockedSession = await login('rfq-blocked@example.test', blockedPassword)

const fixtureJson = composeExec('api', ['php', 'test/rfq-read-fixtures.php'], {
  BLUEPLM_RFQ_FIXTURE_ORG_ID: organization.organization.id,
  BLUEPLM_RFQ_FIXTURE_OWNER_ID: owner.user.userId,
  BLUEPLM_RFQ_FIXTURE_MEMBER_ID: member.id,
  BLUEPLM_RFQ_FIXTURE_GUEST_ID: guest.id,
})
const fixtures = JSON.parse(fixtureJson)

await request('/module-access/rfq', {
  method: 'PUT',
  body: JSON.stringify({ teamIds: [fixtures.team], userIds: [viewer.id, guest.id] }),
}, ownerToken)

const blockedList = await requestStatus('/rfqs', {}, blockedSession.token)
assert.equal(blockedList.status, 403)
assert.equal(blockedList.body.error, 'MODULE_ACCESS_DENIED')
const deniedModules = await request('/module-access/denied', {}, blockedSession.token)
assert.ok(deniedModules.moduleIds.includes('rfq'))

const unauthenticated = await requestStatus('/rfqs')
assert.equal(unauthenticated.status, 401)
assert.equal((await request('/rfqs', {}, ownerToken)).rfqs.length, 2)
const firstPage = await request('/rfqs?limit=1', {}, memberSession.token)
assert.equal(firstPage.rfqs.length, 1)
assert.equal(firstPage.hasMore, true)
assert.ok(firstPage.rfqs[0].itemCount >= 0)
assert.ok(firstPage.rfqs[0].supplierCount >= 0)
const secondPage = await request('/rfqs?page=2&limit=1', {}, memberSession.token)
assert.equal(secondPage.rfqs.length, 1)
assert.equal(secondPage.hasMore, false)
assert.ok(!JSON.stringify([firstPage, secondPage]).includes('RFQ FOREIGN SECRET'))
assert.equal((await requestStatus('/rfqs?limit=201', {}, ownerToken)).status, 400)
assert.equal((await requestStatus('/rfqs?status=unknown', {}, ownerToken)).body.error, 'INVALID_REQUEST')
assert.equal((await requestStatus(`/rfqs/${fixtures.rfqForeign}`, {}, ownerToken)).status, 404)
assert.equal((await requestStatus('/rfqs/not-a-uuid', {}, ownerToken)).status, 400)
assert.equal((await requestStatus('/rfqs/00000000-0000-0000-0000-000000000000', {}, ownerToken)).status, 404)

const detailPath = `/rfqs/${fixtures.rfqPrimary}`
const memberDetail = await request(detailPath, {}, memberSession.token)
assert.equal(memberDetail.rfq.id, fixtures.rfqPrimary)
assert.equal(memberDetail.rfq.itemCount, 5)
assert.equal(memberDetail.rfq.supplierCount, 3)
assert.equal(memberDetail.items.length, 5)
assert.equal(memberDetail.suppliers.length, 3)
assert.equal(memberDetail.rfq.sentAt, '2026-10-01 10:11:12.123')
assert.equal(memberDetail.rfq.sentBy, owner.user.userId)
assert.equal(memberDetail.rfq.completedAt, null)
assert.equal(memberDetail.items[0].file.id, fixtures.fileAccessible)
assert.equal(memberDetail.items[0].swConfiguration, '  Mirror Left - 2x  ')
assert.equal(memberDetail.items[0].releaseFilesStatus, 'complete')
assert.equal(memberDetail.items[0].releaseFilesError, null)
assert.equal(memberDetail.items[0].stepFileGenerated, true)
assert.equal(memberDetail.items[0].stepFilePath, 'rfq-release/step/access.step')
assert.equal(memberDetail.items[0].stepStoragePath, 'rfq-release/step/access.step')
assert.equal(memberDetail.items[0].stepFileSize, 4321)
assert.equal(memberDetail.items[0].pdfFileGenerated, true)
assert.equal(memberDetail.items[0].pdfFilePath, 'rfq-release/pdf/access.pdf')
assert.equal(memberDetail.items[0].pdfStoragePath, 'rfq-release/pdf/access.pdf')
assert.equal(memberDetail.items[0].pdfFileSize, 8765)
assert.deepEqual(memberDetail.items[0].attachments, [{
  name: 'Spec.pdf',
  path: 'Attachments/Spec.pdf',
  size: 1234,
  storagePath: 'rfq-attachments/spec.pdf',
}])
assert.equal(memberDetail.items[0].createdAt, '2026-10-03 08:09:10.111')
assert.equal(memberDetail.items[0].updatedAt, '2026-10-04 09:10:11.222')
assert.equal(memberDetail.items[1].file.id, fixtures.filePrivate, 'ordinary member access through team_vault_access is included')
assert.equal(memberDetail.items[1].swConfiguration, 'Alternate Fixture Config')
assert.equal(memberDetail.items[1].releaseFilesStatus, 'complete')
assert.equal(memberDetail.items[1].releaseFilesError, 'Private release error')
assert.equal(memberDetail.items[1].stepStoragePath, 'rfq-release/step/private.step')
assert.equal(memberDetail.items[1].pdfStoragePath, 'rfq-release/pdf/private.pdf')
assert.deepEqual(memberDetail.items[1].attachments, [{
  name: 'No storage key.txt',
  path: 'Attachments/No storage key.txt',
  size: 17,
}])
function assertNoVaultScopedReleaseMetadata(item) {
  assert.equal(item.releaseFilesStatus, 'pending')
  assert.equal(item.releaseFilesError, null)
  assert.equal(item.stepFileGenerated, false)
  assert.equal(item.stepFilePath, null)
  assert.equal(item.stepStoragePath, null)
  assert.equal(item.stepFileSize, null)
  assert.equal(item.pdfFileGenerated, false)
  assert.equal(item.pdfFilePath, null)
  assert.equal(item.pdfStoragePath, null)
  assert.equal(item.pdfFileSize, null)
  assert.deepEqual(item.attachments, [])
}
assert.equal(memberDetail.items[2].swConfiguration, null)
assert.equal(memberDetail.items[2].file, null, 'soft-deleted source files are not visible')
assertNoVaultScopedReleaseMetadata(memberDetail.items[2])
assert.equal(memberDetail.items[3].file, null)
assertNoVaultScopedReleaseMetadata(memberDetail.items[3])
assert.equal(memberDetail.items[4].fileId, null)
assert.equal(memberDetail.items[4].file, null, 'unlinked RFQ items have no source-file visibility proof')
assertNoVaultScopedReleaseMetadata(memberDetail.items[4])
const quotedSupplier = memberDetail.suppliers.find((supplier) => supplier.supplierId === fixtures.supplier)
assert.ok(quotedSupplier)
assert.equal(quotedSupplier.supplier.name, 'RFQ Local Supplier')
assert.equal(quotedSupplier.supplier.contactName, 'Avery Quoter')
assert.equal(quotedSupplier.quotedAt, '2026-10-13 13:14:15.456')
assert.equal(quotedSupplier.declinedAt, null)
assert.equal(quotedSupplier.declinedReason, null)
assert.equal(quotedSupplier.totalQuotedAmount, '123456789012.340005')
assert.equal(quotedSupplier.currency, 'EUR')
assert.equal(quotedSupplier.leadTimeDays, 37)
assert.equal(quotedSupplier.isSelected, true)
assert.equal(quotedSupplier.selectedAt, '2026-10-14 14:15:16.567')
assert.equal(quotedSupplier.selectedBy, owner.user.userId)
assert.equal(quotedSupplier.notes, 'Fixture quote notes')
assert.equal(quotedSupplier.supplier.contactEmail, 'quotes@rfq-local.example.test')
const declinedSupplier = memberDetail.suppliers.find((supplier) => supplier.supplierId === fixtures.supplierDeclined)
assert.ok(declinedSupplier)
assert.equal(declinedSupplier.declinedAt, '2026-10-15 15:16:17.678')
assert.equal(declinedSupplier.declinedReason, 'Capacity unavailable')
assert.equal(declinedSupplier.quotedAt, null)
assert.equal(declinedSupplier.totalQuotedAmount, null)
assert.equal(declinedSupplier.isSelected, false)
assert.equal(declinedSupplier.selectedAt, null)
assert.equal(declinedSupplier.selectedBy, null)
assert.equal(declinedSupplier.notes, null)
assert.equal(declinedSupplier.supplier.contactName, null)
const pendingSupplier = memberDetail.suppliers.find((supplier) => supplier.supplierId === fixtures.supplierPending)
assert.ok(pendingSupplier)
assert.equal(pendingSupplier.quotedAt, null)
assert.equal(pendingSupplier.declinedAt, null)
assert.equal(pendingSupplier.totalQuotedAmount, null)
assert.equal(pendingSupplier.leadTimeDays, null)
assert.equal(pendingSupplier.isSelected, false)
assert.equal(pendingSupplier.selectedAt, null)
assert.equal(pendingSupplier.selectedBy, null)
assert.equal(pendingSupplier.notes, null)
const serializedMemberDetail = JSON.stringify(memberDetail)
assert.ok(!serializedMemberDetail.includes('RFQ-FOREIGN-SECRET'))
assert.ok(!serializedMemberDetail.includes('RFQ FOREIGN SUPPLIER SECRET'))
assert.ok(!serializedMemberDetail.includes('secret@foreign.example.test'))
assert.ok(!serializedMemberDetail.includes('password_hash'))
const itemPage = await request(`${detailPath}?itemLimit=2`, {}, memberSession.token)
assert.equal(itemPage.items.length, 2)
assert.equal(itemPage.itemsHasMore, true)
const itemPageTwo = await request(`${detailPath}?itemPage=2&itemLimit=2`, {}, memberSession.token)
assert.equal(itemPageTwo.items.length, 2)
assert.equal(itemPageTwo.itemsHasMore, true)
const itemPageThree = await request(`${detailPath}?itemPage=3&itemLimit=2`, {}, memberSession.token)
assert.equal(itemPageThree.items.length, 1)
assert.equal(itemPageThree.itemsHasMore, false)
assert.equal((await requestStatus(`${detailPath}?itemLimit=201`, {}, ownerToken)).status, 400)

const viewerDetail = await request(detailPath, {}, viewerSession.token)
assert.equal(viewerDetail.rfq.id, fixtures.rfqPrimary)
assert.ok(viewerDetail.items.every((item) => item.file === null))
viewerDetail.items.forEach(assertNoVaultScopedReleaseMetadata)
assert.ok(!JSON.stringify(viewerDetail).includes('RFQ-PRIVATE-SECRET'))
assert.ok(!JSON.stringify(viewerDetail).includes('rfq-release/step/private.step'))
assert.ok(!JSON.stringify(viewerDetail).includes('rfq-attachments/spec.pdf'))
const guestDetail = await request(detailPath, {}, guestSession.token)
assert.equal(guestDetail.items[0].file.id, fixtures.fileAccessible)
assert.ok(guestDetail.items.slice(1).every((item) => item.file === null))
guestDetail.items.slice(1).forEach(assertNoVaultScopedReleaseMetadata)
assert.ok(!JSON.stringify(guestDetail).includes('RFQ-PRIVATE-SECRET'))
const ownerDetail = await request(detailPath, {}, ownerToken)
assert.equal(ownerDetail.items[1].file.id, fixtures.filePrivate)
assert.ok(ownerDetail.items.slice(2).every((item) => item.file === null))
ownerDetail.items.slice(2).forEach(assertNoVaultScopedReleaseMetadata)
const completedDetail = await request(`/rfqs/${fixtures.rfqSecond}`, {}, ownerToken)
assert.equal(completedDetail.rfq.sentAt, null)
assert.equal(completedDetail.rfq.sentBy, null)
assert.equal(completedDetail.rfq.completedAt, '2026-10-02 11:12:13.456')
assert.deepEqual(completedDetail.items, [])
assert.deepEqual(completedDetail.suppliers, [])
assert.equal((await requestStatus('/rfqs', { method: 'POST', body: '{}' }, viewerSession.token)).status, 403)

for (const malformedStoredValue of ['{"not":"an attachment list"}', '{}']) {
  composeExec('api', ['php', '-r', 'require "src/Runtime.php"; $root=getcwd(); $env=BluePlm\\Runtime::env($root . "/.env"); $db=BluePlm\\Runtime::database($env); $q=$db->prepare("UPDATE rfq_items SET attachments=? WHERE id=?"); $q->execute([getenv("BLUEPLM_RFQ_BAD_ATTACHMENTS"), getenv("BLUEPLM_RFQ_BAD_ITEM_ID")]);'], {
    BLUEPLM_RFQ_BAD_ATTACHMENTS: malformedStoredValue,
    BLUEPLM_RFQ_BAD_ITEM_ID: fixtures.itemAccessible,
  })
  const malformedAttachments = await requestStatus(detailPath, {}, ownerToken)
  assert.equal(malformedAttachments.status, 500, `malformed stored attachment value must fail closed: ${malformedStoredValue}`)
}

process.stdout.write('MDB RFQ list/detail scope, expanded source DTO fields, strict attachments, migration, roles, module access, tenant joins, vault visibility, paging, and null metadata passed.\n')
