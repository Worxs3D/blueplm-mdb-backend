import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { resolve } from 'node:path'

const ownerTop = process.env.BLUEPLM_JOB_TITLES_OWNER_TOP
const composeFile = process.env.BLUEPLM_JOB_TITLES_COMPOSE_FILE
const project = process.env.BLUEPLM_JOB_TITLES_COMPOSE_PROJECT
const server = process.env.BLUEPLM_JOB_TITLES_TEST_SERVER
assert.ok(ownerTop && composeFile && project && server, 'Owner, Compose, project, and server boundaries must be provided.')
assert.equal(resolve(process.cwd()), resolve(ownerTop), 'Integration process cwd must be the verified PHP owner top.')

const compose = ['compose', '-p', project, '-f', composeFile]
const run = args => execFileSync('docker', args, { cwd: process.cwd(), stdio: 'pipe' })
const sql = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
const rows = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
  .toString().trim().split(/\r?\n/u).filter(Boolean).map(line => line.split('\t'))
const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Job title owner password 123!'
const sessionSecret = 'test-session-secret-must-be-at-least-thirty-two-characters'

async function call(path, init = {}, token, expected = 200) {
  const response = await fetch(`${server}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })
  const body = response.status === 204 ? undefined : await response.json()
  assert.equal(response.status, expected, `${init.method ?? 'GET'} ${path}: ${JSON.stringify(body)}`)
  return body
}

async function waitForHealth() {
  let lastError
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const health = await call('/health')
      if (health.ok === true && health.supabase === false) return
    } catch (error) { lastError = error }
    await new Promise(resolveDelay => setTimeout(resolveDelay, 250))
  }
  throw new Error(`PHP API did not become healthy: ${String(lastError)}`)
}

function createMember(organizationId, name, role) {
  const id = randomUUID()
  const token = `job-title-${randomUUID()}`
  const tokenHash = createHash('sha256').update(`${sessionSecret}:${token}`).digest('hex')
  sql(`INSERT INTO users(id,email,display_name,password_hash) VALUES('${id}','${name}-${id}@job-title.test','${name}','fixture'); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${organizationId}','${id}','${role}'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${tokenHash}','${id}','${organizationId}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)
  return { id, token }
}

await waitForHealth()
const installed = await call('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken,
    action: 'install',
    bootstrap: {
      organizationName: 'Job Title Org',
      organizationSlug: `job-title-${randomUUID()}`,
      email: 'owner@job-title.test',
      displayName: 'Owner',
      password,
    },
  }),
})
const owner = installed.token
const ownerMe = await call('/auth/me', {}, owner)
const organizationId = ownerMe.user.organizationId

const member = createMember(organizationId, 'member', 'member')
const viewer = createMember(organizationId, 'viewer', 'viewer')
const guest = createMember(organizationId, 'guest', 'guest')
const admin = createMember(organizationId, 'admin', 'admin')
const foreignOrganizationId = randomUUID()
const foreignOwnerId = randomUUID()
const foreignToken = `job-title-foreign-${randomUUID()}`
const foreignTokenHash = createHash('sha256').update(`${sessionSecret}:${foreignToken}`).digest('hex')
sql(`INSERT INTO organizations(id,name,slug) VALUES('${foreignOrganizationId}','Foreign Job Title Org','foreign-${randomUUID()}'); INSERT INTO users(id,email,display_name,password_hash) VALUES('${foreignOwnerId}','foreign-${foreignOwnerId}@job-title.test','Foreign owner','fixture'); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${foreignOrganizationId}','${foreignOwnerId}','owner'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${foreignTokenHash}','${foreignOwnerId}','${foreignOrganizationId}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)

const initialRoles = rows(`SELECT user_id,role FROM organization_memberships WHERE organization_id='${organizationId}' ORDER BY user_id`)
for (const token of [owner, member.token, viewer.token, guest.token]) {
  assert.deepEqual((await call('/job-titles', {}, token)).jobTitles, [])
}
assert.deepEqual((await call('/job-titles', {}, foreignToken)).jobTitles, [])

const title = (await call('/job-titles', {
  method: 'POST',
  body: JSON.stringify({ name: 'Quality Engineer', color: '#2468AC', icon: 'BadgeCheck', assignToUserId: member.id }),
}, owner, 201)).jobTitle
assert.deepEqual(title, { id: title.id, name: 'Quality Engineer', color: '#2468AC', icon: 'BadgeCheck' })
let catalog = await call('/job-titles', {}, member.token)
assert.equal(catalog.jobTitles.length, 1)
assert.deepEqual(catalog.assignments, [{ userId: member.id, jobTitle: title }])

await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Quality Engineer', color: '#111111', icon: 'Wrench' }) }, owner, 409)
await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Invalid color', color: 'blue', icon: 'Wrench' }) }, owner, 400)
await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Rolled back title', color: '#111111', icon: 'Wrench', assignToUserId: foreignOwnerId }) }, owner, 404)
catalog = await call('/job-titles', {}, owner)
assert.equal(catalog.jobTitles.length, 1, 'duplicate, invalid, and cross-organization writes must not leave catalog rows')

await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Member denied', color: '#112233', icon: 'User' }) }, member.token, 403)
await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Viewer denied', color: '#112233', icon: 'User' }) }, viewer.token, 403)
await call('/job-titles', { method: 'POST', body: JSON.stringify({ name: 'Guest denied', color: '#112233', icon: 'User' }) }, guest.token, 403)

const second = (await call('/job-titles', {
  method: 'POST',
  body: JSON.stringify({ name: 'Production Lead', color: '#C04221', icon: 'Factory', assignToUserId: admin.id }),
}, admin.token, 201)).jobTitle
await call(`/job-titles/${second.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ name: 'Quality Engineer', color: '#C04221', icon: 'Factory' }),
}, owner, 409)
catalog = await call('/job-titles', {}, owner)
assert.equal(catalog.jobTitles.find(jobTitle => jobTitle.id === second.id)?.name, 'Production Lead')
await call(`/users/${member.id}/job-title`, { method: 'PUT', body: JSON.stringify({ jobTitleId: second.id }) }, admin.token)
catalog = await call('/job-titles', {}, owner)
assert.equal(catalog.assignments.length, 2)
assert.deepEqual(catalog.assignments.map(assignment => assignment.userId).sort(), [admin.id, member.id].sort())
assert.ok(catalog.assignments.every(assignment => assignment.jobTitle.id === second.id))

await call(`/users/${member.id}/job-title`, { method: 'PUT', body: JSON.stringify({ jobTitleId: null }) }, owner)
await call(`/users/${foreignOwnerId}/job-title`, { method: 'PUT', body: JSON.stringify({ jobTitleId: second.id }) }, owner, 404)
await call(`/users/${member.id}/job-title`, { method: 'PUT', body: JSON.stringify({ jobTitleId: randomUUID() }) }, owner, 404)
await call(`/users/${member.id}/job-title`, { method: 'PUT', body: JSON.stringify({}) }, owner, 400)

await call(`/job-titles/${second.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ name: 'Production Director', color: '#AABBCC', icon: 'Factory' }),
}, owner)
catalog = await call('/job-titles', {}, owner)
assert.ok(catalog.assignments.every(assignment => assignment.jobTitle.name === 'Production Director'))
await call(`/job-titles/${second.id}`, { method: 'DELETE' }, owner, 204)
catalog = await call('/job-titles', {}, owner)
assert.deepEqual(catalog.assignments, [], 'deleting a catalog title must clear all user assignments')

await call(`/users/${foreignOwnerId}/job-title`, { method: 'PUT', body: JSON.stringify({ jobTitleId: title.id }) }, foreignToken, 404)
await call(`/job-titles/${title.id}`, { method: 'PATCH', body: JSON.stringify({ name: 'Tamper', color: '#123456', icon: 'Wrench' }) }, foreignToken, 404)
await call(`/job-titles/${title.id}`, { method: 'DELETE' }, member.token, 403)
assert.deepEqual(rows(`SELECT user_id,role FROM organization_memberships WHERE organization_id='${organizationId}' ORDER BY user_id`), initialRoles)

console.log('MDB job-title API permissions, tenant isolation, validation, assignment, cascade, and role preservation passed.')
