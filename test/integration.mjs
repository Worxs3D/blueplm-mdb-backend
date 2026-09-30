import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHmac } from 'node:crypto'
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const server = process.env.BLUEPLM_PHP_TEST_SERVER ?? 'http://127.0.0.1:18080'
const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Integration password 123!'

function authenticatorCode(secret, timestamp = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const character of secret.replace(/=+$/u, '').toUpperCase()) {
    const value = alphabet.indexOf(character)
    assert.notEqual(value, -1, 'Authenticator secret must be valid base32.')
    bits += value.toString(2).padStart(5, '0')
  }
  const bytes = []
  for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
    bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2))
  }
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(timestamp / 30_000)))
  const digest = createHmac('sha1', Buffer.from(bytes)).update(counter).digest()
  const dynamicOffset = digest[digest.length - 1] & 0x0f
  const binary =
    ((digest[dynamicOffset] & 0x7f) << 24) |
    ((digest[dynamicOffset + 1] & 0xff) << 16) |
    ((digest[dynamicOffset + 2] & 0xff) << 8) |
    (digest[dynamicOffset + 3] & 0xff)
  return String(binary % 1_000_000).padStart(6, '0')
}

function stagePendingEnvironment() {
  execFileSync(
    'docker',
    [
      'compose',
      '-f',
      'docker-compose.test.yml',
      'exec',
      '-T',
      'api',
      'sh',
      '-c',
      'cp /app/test/install-env.fixture /app/.env.install && chown www-data:www-data /app/.env.install',
    ],
    { cwd: process.cwd(), stdio: 'pipe' },
  )
}

async function request(path, init = {}, token) {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(`${server}${path}`, { ...init, headers })
  const body = response.status === 204 ? undefined : await response.json()
  assert.ok(
    response.ok,
    `${init.method ?? 'GET'} ${path}: ${response.status} ${JSON.stringify(body)}`,
  )
  return body
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

async function waitForHealth() {
  let lastError
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const health = await request('/health')
      if (health.ok === true && health.supabase === false && health.apiVersion === 2) {
        const databaseProbe = await fetch(`${server}/installer/database-status`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ installationToken: 'invalid' }),
        })
        if (databaseProbe.status === 403) return
      }
    } catch (error) {
      lastError = error
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }
  throw new Error(`PHP API did not become healthy: ${String(lastError)}`)
}

await waitForHealth()
const corsResponse = await fetch(`${server}/health`, { headers: { Origin: 'null' } })
assert.equal(corsResponse.headers.get('access-control-allow-origin'), 'null')
const deniedInstall = await fetch(`${server}/installer/commit`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ installationToken: 'invalid', action: 'install' }),
})
assert.equal(deniedInstall.status, 403)
const installed = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({
    installationToken,
    action: 'install',
    bootstrap: {
      organizationName: 'Integration Org',
      organizationSlug: 'integration-org',
      email: 'owner@example.test',
      displayName: 'Owner',
      password,
    },
  }),
})
assert.equal(typeof installed.token, 'string')
assert.equal(installed.bootstrapped, true)
const login = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'owner@example.test', password }),
})
const token = login.token

// Self-registration is opt-in. A request is stored without a membership or
// role and only becomes usable after an administrator explicitly approves it.
const registrationBefore = await request('/auth/registration')
assert.equal(registrationBefore.enabled, false)
await request(
  '/organizations/current/settings/auth-providers',
  {
    method: 'PUT',
    body: JSON.stringify({
      value: {
        selfRegistration: true,
        users: { google: false, email: true, phone: false },
        suppliers: { google: false, email: false, phone: false },
      },
    }),
  },
  token,
)
const registrationAfter = await request('/auth/registration')
assert.equal(registrationAfter.enabled, true)
const registration = await request('/auth/register', {
  method: 'POST',
  body: JSON.stringify({
    email: 'self-registered@example.test',
    displayName: 'Pending User',
    password: 'Self registered password 123!',
  }),
})
assert.equal(registration.status, 'pending')
const pendingLogin = await fetch(`${server}/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'self-registered@example.test', password: 'Self registered password 123!' }),
})
assert.equal(pendingLogin.status, 401)
const pendingRequests = await request('/registration-requests', {}, token)
assert.ok(pendingRequests.requests.some((entry) => entry.email === 'self-registered@example.test'))
const pendingRequest = pendingRequests.requests.find((entry) => entry.email === 'self-registered@example.test')
assert.ok(pendingRequest)
const approved = await request(`/registration-requests/${pendingRequest.id}/approve`, {
  method: 'POST',
  body: JSON.stringify({ role: 'viewer' }),
}, token)
assert.equal(approved.user.role, 'viewer')
const approvedLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'self-registered@example.test', password: 'Self registered password 123!' }),
})
assert.equal(typeof approvedLogin.token, 'string')

// Emergency registration is a separate recovery flow. It creates an admin
// directly, consumes the single-use code, and returns an authenticated session.
const generatedRecoveryCode = await request('/recovery-codes', {
  method: 'POST',
  body: JSON.stringify({ description: 'integration emergency registration', expiresInDays: 1 }),
}, token)
const emergencyRegistration = await request('/auth/recovery-register', {
  method: 'POST',
  body: JSON.stringify({
    recoveryCode: generatedRecoveryCode.code,
    email: 'emergency-admin@example.test',
    displayName: 'Emergency Admin',
    password: 'Emergency admin password 123!',
  }),
})
assert.equal(emergencyRegistration.user.role, 'admin')
const emergencyPrincipal = await request('/auth/me', {}, emergencyRegistration.token)
assert.equal(emergencyPrincipal.user.role, 'admin')
const reusedRecoveryCode = await fetch(`${server}/auth/recovery-register`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    recoveryCode: generatedRecoveryCode.code,
    email: 'second-emergency-admin@example.test',
    displayName: 'Second Emergency Admin',
    password: 'Emergency admin password 123!',
  }),
})
assert.equal(reusedRecoveryCode.status, 401)

// Authenticator enrollment and challenge verification are public client API
// contracts. The secret is returned once during enrollment and never stored by
// the desktop client.
const initialTotpStatus = await request('/account/totp', {}, token)
assert.equal(initialTotpStatus.enabled, false)
const enrollment = await request('/account/totp/enrollment', { method: 'POST' }, token)
assert.match(enrollment.provisioningUri, /^otpauth:\/\/totp\//u)
await request(
  '/account/totp/confirm',
  {
    method: 'POST',
    body: JSON.stringify({
      enrollmentToken: enrollment.enrollmentToken,
      code: authenticatorCode(enrollment.secret),
    }),
  },
  token,
)
const protectedLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'owner@example.test', password }),
})
assert.equal(protectedLogin.totpRequired, true)
const verifiedLogin = await request('/auth/totp/verify', {
  method: 'POST',
  body: JSON.stringify({
    challengeToken: protectedLogin.challengeToken,
    code: authenticatorCode(enrollment.secret),
  }),
})
assert.equal(typeof verifiedLogin.token, 'string')
await request(
  '/account/totp',
  {
    method: 'DELETE',
    body: JSON.stringify({ code: authenticatorCode(enrollment.secret) }),
  },
  token,
)

// MDB user management must remain a first-class API: no Supabase auth
// endpoint is involved when an administrator creates or changes an account.
const createdUser = await request(
  '/users',
  {
    method: 'POST',
    body: JSON.stringify({
      email: 'member@example.test',
      displayName: 'Initial Member',
      password: 'Initial member password 123!',
    }),
  },
  token,
)
assert.equal(createdUser.email, 'member@example.test')
const usersBeforeUpdate = await request('/users', {}, token)
assert.ok(usersBeforeUpdate.users.some((user) => user.id === createdUser.id))
const memberLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'member@example.test', password: 'Initial member password 123!' }),
})
const updatedUser = await request(
  `/users/${createdUser.id}`,
  {
    method: 'PATCH',
    body: JSON.stringify({
      email: 'renamed.member@example.test',
      displayName: 'Renamed Member',
      password: 'Updated member password 123!',
    }),
  },
  token,
)
assert.equal(updatedUser.user.email, 'renamed.member@example.test')
assert.equal(updatedUser.user.displayName, 'Renamed Member')
const revokedSession = await fetch(`${server}/auth/me`, {
  headers: { Authorization: `Bearer ${memberLogin.token}` },
})
assert.equal(revokedSession.status, 401)
const updatedMemberLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({
    email: 'renamed.member@example.test',
    password: 'Updated member password 123!',
  }),
})
assert.equal(typeof updatedMemberLogin.token, 'string')

// Sidebar module defaults are an MDB organization contract.  The owner can
// persist and read them, while a regular member cannot mutate the org record.
const moduleDefaults = {
  enabled_modules: { explorer: true, history: false },
  enabled_groups: {},
  module_order: ['explorer', 'history'],
  dividers: [],
  module_parents: {},
  module_icon_colors: {},
  custom_groups: [],
}
await request('/organizations/current/settings/modules', {
  method: 'PUT',
  body: JSON.stringify({ value: moduleDefaults }),
}, token)
const storedModuleDefaults = await request('/organizations/current/settings/modules', {}, token)
assert.equal(storedModuleDefaults.value.enabled_modules.history, false)
assert.equal((await requestStatus('/organizations/current/settings/modules', {
  method: 'PUT',
  body: JSON.stringify({ value: moduleDefaults }),
}, updatedMemberLogin.token)).status, 403)

// Supplier CRUD is tenant-scoped and role-gated. The list endpoint exposes
// active records only; deactivation keeps historical part assignments intact.
const supplier = await request('/suppliers', {
  method: 'POST',
  body: JSON.stringify({ name: 'Integration Supplier', code: 'INT-SUP-1', contactEmail: 'supplier@example.test', website: 'https://supplier.example.test', isActive: true, isApproved: false }),
}, token)
assert.equal(supplier.supplier.code, 'INT-SUP-1')
assert.equal(supplier.supplier.is_active, true)
assert.equal((await request('/suppliers', {}, token)).suppliers.some((entry) => entry.id === supplier.supplier.id), true)
const updatedSupplier = await request(`/suppliers/${supplier.supplier.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ city: 'Berlin', isApproved: true }),
}, token)
assert.equal(updatedSupplier.supplier.city, 'Berlin')
assert.equal(updatedSupplier.supplier.is_approved, true)
assert.equal((await requestStatus('/suppliers', { method: 'POST', body: JSON.stringify({ name: 'Denied Supplier' }) }, updatedMemberLogin.token)).status, 403)
assert.equal((await requestStatus(`/suppliers/${supplier.supplier.id}`, { method: 'PATCH', body: JSON.stringify({ name: 'Denied Update' }) }, updatedMemberLogin.token)).status, 403)
assert.equal((await requestStatus(`/suppliers/${supplier.supplier.id}`, { method: 'DELETE' }, updatedMemberLogin.token)).status, 403)
assert.equal((await requestStatus(`/suppliers/${supplier.supplier.id}`, { method: 'PATCH', body: JSON.stringify({ isApproved: 'false' }) }, token)).status, 400)
assert.equal((await requestStatus('/suppliers', { method: 'POST', body: JSON.stringify({ name: 'Duplicate Supplier', code: 'INT-SUP-1' }) }, token)).status, 409)
assert.equal((await requestStatus(`/suppliers/00000000-0000-0000-0000-000000000000`, {}, token)).status, 404)
await request(`/suppliers/${supplier.supplier.id}`, { method: 'DELETE' }, token)
assert.equal((await request('/suppliers', {}, token)).suppliers.some((entry) => entry.id === supplier.supplier.id), false)

// Seed a second organization through the test database fixture and obtain a
// real admin session. This proves supplier reads and mutations remain tenant
// isolated rather than merely returning 404 for an arbitrary UUID.
const foreignOrgId = randomUUID()
const foreignPassword = 'Foreign supplier password 123!'
const foreignUser = await request('/users', {
  method: 'POST',
  body: JSON.stringify({ email: 'foreign-owner@example.test', displayName: 'Foreign Owner', password: foreignPassword, role: 'admin' }),
}, token)
const foreignSql = `INSERT INTO organizations (id, name, slug) VALUES ('${foreignOrgId}', 'Foreign Org', 'foreign-org'); DELETE FROM organization_memberships WHERE user_id = '${foreignUser.id}'; INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${foreignOrgId}', '${foreignUser.id}', 'admin');`
execFileSync('docker', [
  'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
  'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', foreignSql,
], { cwd: process.cwd(), stdio: 'pipe' })
const foreignLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'foreign-owner@example.test', password: foreignPassword }),
})
const foreignSupplier = await request('/suppliers', {
  method: 'POST',
  body: JSON.stringify({ name: 'Foreign Supplier', code: 'FOREIGN-1', isActive: true }),
}, foreignLogin.token)
assert.equal(foreignSupplier.supplier.name, 'Foreign Supplier')
assert.equal((await request('/suppliers', {}, token)).suppliers.some((entry) => entry.id === foreignSupplier.supplier.id), false)
assert.equal((await requestStatus(`/suppliers/${foreignSupplier.supplier.id}`, { method: 'PATCH', body: JSON.stringify({ name: 'Cross Org Update' }) }, token)).status, 404)
assert.equal((await requestStatus(`/suppliers/${foreignSupplier.supplier.id}`, { method: 'DELETE' }, token)).status, 404)
const foreignAfterIsolationCheck = await request('/suppliers', {}, foreignLogin.token)
assert.equal(foreignAfterIsolationCheck.suppliers.some((entry) => entry.id === foreignSupplier.supplier.id && entry.name === 'Foreign Supplier'), true)
// Workflow roles are separate organization data. Account roles must not create
// or imply workflow-role assignments, and only organization administrators may
// edit the role catalog or assignments.
const workflowRoles = await request('/workflow-roles', {}, token)
assert.equal(workflowRoles.roles.length, 3)
const initialWorkflowAssignments = await request('/workflow-role-assignments', {}, token)
assert.deepEqual(initialWorkflowAssignments.assignments, {})
const customWorkflowRole = await request('/workflow-roles', {
  method: 'POST',
  body: JSON.stringify({
    name: 'Quality Reviewers',
    color: '#0EA5E9',
    icon: 'check-circle',
    description: 'Review released quality records',
  }),
}, token)
assert.equal(customWorkflowRole.role.name, 'Quality Reviewers')
const renamedWorkflowRole = await request(`/workflow-roles/${customWorkflowRole.role.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ name: 'Quality Approvers' }),
}, token)
assert.equal(renamedWorkflowRole.role.name, 'Quality Approvers')
await request(`/users/${createdUser.id}/workflow-roles`, {
  method: 'PUT',
  body: JSON.stringify({ roleIds: [customWorkflowRole.role.id] }),
}, token)
const assignedWorkflowRoles = await request('/workflow-role-assignments', {}, token)
assert.deepEqual(assignedWorkflowRoles.assignments[createdUser.id], [customWorkflowRole.role.id])
const memberWorkflowRoles = await request('/workflow-roles', {}, updatedMemberLogin.token)
assert.ok(memberWorkflowRoles.roles.some((role) => role.id === customWorkflowRole.role.id))
const deniedWorkflowRoleCreate = await requestStatus('/workflow-roles', {
  method: 'POST',
  body: JSON.stringify({ name: 'Should Be Denied' }),
}, updatedMemberLogin.token)
assert.equal(deniedWorkflowRoleCreate.status, 403)
await request(`/workflow-roles/${customWorkflowRole.role.id}`, { method: 'DELETE' }, token)
const assignmentsAfterDelete = await request('/workflow-role-assignments', {}, token)
assert.deepEqual(assignmentsAfterDelete.assignments, {})

// Teams are used by the desktop MDB backend. A missing GET /teams
// route makes the client surface "Failed to load teams" immediately after
// successful login, so retain this as an end-to-end compatibility contract.
const initialTeams = await request('/teams', {}, token)
assert.deepEqual(initialTeams.teams, [])
const createdTeam = await request(
  '/teams',
  {
    method: 'POST',
    body: JSON.stringify({ name: 'Integration Team', color: '#2563eb', icon: 'Users' }),
  },
  token,
)
assert.equal(createdTeam.name, 'Integration Team')
const teams = await request('/teams', {}, token)
assert.ok(teams.teams.some((team) => team.id === createdTeam.id && team.memberCount === 0))
const updatedTeam = await request(
  `/teams/${createdTeam.id}`,
  {
    method: 'PATCH',
    body: JSON.stringify({
      name: 'Renamed Integration Team',
      color: '#16a34a',
      icon: 'UsersRound',
    }),
  },
  token,
)
assert.equal(updatedTeam.name, 'Renamed Integration Team')
await request(
  `/teams/${createdTeam.id}/members`,
  {
    method: 'POST',
    body: JSON.stringify({ userId: createdUser.id }),
  },
  token,
)
const userTeams = await request(`/users/${createdUser.id}/teams`, {}, token)
assert.ok(userTeams.teams.some((team) => team.id === createdTeam.id))
const teamMembers = await request(`/teams/${createdTeam.id}/members`, {}, token)
assert.ok(teamMembers.members.some((member) => member.userId === createdUser.id))
const memberProfile = await request(`/users/${createdUser.id}/profile`, {}, token)
assert.equal(memberProfile.user.id, createdUser.id)
assert.ok(memberProfile.user.teams.some((team) => team.id === createdTeam.id))

const reviewerWorkflowRole = await request('/workflow-roles', {
  method: 'POST',
  body: JSON.stringify({ name: 'Team Reviewer', color: '#16a34a', icon: 'shield-check' }),
}, token)
await request(`/teams/${createdTeam.id}/reviewers`, {
  method: 'POST',
  body: JSON.stringify({ reviewerType: 'user', userId: createdUser.id }),
}, token)
await request(`/teams/${createdTeam.id}/reviewers`, {
  method: 'POST',
  body: JSON.stringify({ reviewerType: 'workflow_role', workflowRoleId: reviewerWorkflowRole.role.id }),
}, token)
const teamReviewers = await request(`/teams/${createdTeam.id}/reviewers`, {}, token)
assert.equal(teamReviewers.reviewers.length, 2)
assert.ok(teamReviewers.reviewers.some((reviewer) => reviewer.user_id === createdUser.id))
assert.ok(teamReviewers.reviewers.some((reviewer) => reviewer.workflow_role_id === reviewerWorkflowRole.role.id))
await request(`/team-reviewers/${teamReviewers.reviewers[0].id}`, { method: 'DELETE' }, token)

await request(`/teams/${createdTeam.id}/permissions`, {
  method: 'PUT',
  body: JSON.stringify({
    permissions: [
      { resource: 'module:explorer', vaultId: null, actions: ['view', 'edit'] },
      { resource: 'module:history', vaultId: null, actions: ['view'] },
    ],
  }),
}, token)
const teamPermissions = await request(`/teams/${createdTeam.id}/permissions`, {}, token)
assert.deepEqual(teamPermissions.permissions.map((permission) => permission.resource), ['module:explorer', 'module:history'])
const effectivePermissions = await request(`/users/${createdUser.id}/effective-permissions`, {}, updatedMemberLogin.token)
assert.ok(effectivePermissions.permissions.some((permission) => permission.resource === 'module:explorer' && permission.actions.includes('edit')))

const root = await mkdtemp(join(tmpdir(), 'blueplm-php-vault-'))
const vaultRoot = join(root, 'vault')
const workspace = join(root, 'workspace')
await mkdir(vaultRoot)
await mkdir(workspace)
await writeFile(join(vaultRoot, 'drawing.txt'), 'revision 1\n', 'utf8')

const vault = await request(
  '/vaults',
  { method: 'POST', body: JSON.stringify({ name: 'Integration Vault', networkRoot: vaultRoot }) },
  token,
)
await request(
  `/teams/${createdTeam.id}/vault-access`,
  {
    method: 'PUT',
    body: JSON.stringify({ vaultIds: [vault.id] }),
  },
  token,
)
const teamVaultAccess = await request(`/teams/${createdTeam.id}/vault-access`, {}, token)
assert.deepEqual(teamVaultAccess.vaultIds, [vault.id])
const defaultTeam = await request(
  '/organizations/current/settings',
  {
    method: 'PUT',
    body: JSON.stringify({ defaultNewUserTeamId: createdTeam.id }),
  },
  token,
)
assert.equal(defaultTeam.defaultNewUserTeamId, createdTeam.id)

const serializationSettings = {
  enabled: true,
  prefix: 'TEST-',
  suffix: '-A',
  padding_digits: 4,
  letter_prefix: '',
  keepout_zones: [{ start: 2, end_num: 4, description: 'Reserved' }],
  current_counter: 0,
}
const savedSerialization = await request(
  '/organizations/current/settings/serialization',
  {
    method: 'PUT',
    body: JSON.stringify({ value: serializationSettings, replaceCounter: true }),
  },
  token,
)
assert.equal(savedSerialization.value.current_counter, 0)
const loadedSerialization = await request('/organizations/current/settings/serialization', {}, token)
assert.equal(loadedSerialization.value.prefix, 'TEST-')
assert.equal(loadedSerialization.value.current_counter, 0)
assert.equal(
  (await request('/organizations/current/serialization/preview', {}, token)).serialNumber,
  'TEST-0001-A',
)
assert.equal(
  (await request('/organizations/current/serialization/next', { method: 'POST' }, token)).serialNumber,
  'TEST-0001-A',
)
assert.equal(
  (await request('/organizations/current/serialization/next', { method: 'POST' }, token)).serialNumber,
  'TEST-0005-A',
)
assert.equal(
  (await request('/organizations/current/settings/serialization', {}, token)).value.current_counter,
  5,
)

for (const [section, value] of Object.entries({
  export: { filename_pattern: '{partNumber}' },
  rfq: { default_payment_terms: 'Net 30' },
  'auth-providers': { users: { email: true } },
})) {
  await request(
    `/organizations/current/settings/${section}`,
    { method: 'PUT', body: JSON.stringify({ value }) },
    token,
  )
  assert.deepEqual((await request(`/organizations/current/settings/${section}`, {}, token)).value, value)
}

// Viewer accounts inherit team vault grants. Guest accounts deliberately do
// not: they see only explicitly assigned vaults. Both roles remain read-only.
const viewer = await request(
  '/users',
  {
    method: 'POST',
    body: JSON.stringify({
      email: 'viewer@example.test',
      displayName: 'Integration Viewer',
      password: 'Integration viewer password 123!',
      role: 'viewer',
    }),
  },
  token,
)
const guest = await request(
  '/users',
  {
    method: 'POST',
    body: JSON.stringify({
      email: 'guest@example.test',
      displayName: 'Integration Guest',
      password: 'Integration guest password 123!',
      role: 'guest',
    }),
  },
  token,
)
for (const userId of [viewer.id, guest.id]) {
  await request(
    `/teams/${createdTeam.id}/members`,
    { method: 'POST', body: JSON.stringify({ userId }) },
    token,
  )
}
const viewerLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'viewer@example.test', password: 'Integration viewer password 123!' }),
})
const guestLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'guest@example.test', password: 'Integration guest password 123!' }),
})

// Registration moderation is admin-only. A viewer or guest may not inspect,
// approve, or reject pending requests.
await request(
  '/organizations/current/settings/auth-providers',
  {
    method: 'PUT',
    body: JSON.stringify({
      value: {
        selfRegistration: true,
        users: { google: false, email: true, phone: false },
        suppliers: { google: false, email: false, phone: false },
      },
    }),
  },
  token,
)
const restrictedRegistration = await request('/auth/register', {
  method: 'POST',
  body: JSON.stringify({
    email: 'moderation-target@example.test',
    displayName: 'Moderation Target',
    password: 'Moderation target password 123!',
  }),
})
assert.equal(restrictedRegistration.status, 'pending')
assert.equal((await requestStatus('/registration-requests', {}, viewerLogin.token)).status, 403)
assert.equal((await requestStatus('/registration-requests', {}, guestLogin.token)).status, 403)
const moderationRequests = await request('/registration-requests', {}, token)
const moderationRequest = moderationRequests.requests.find((entry) => entry.email === 'moderation-target@example.test')
assert.ok(moderationRequest)
assert.equal((await requestStatus(`/registration-requests/${moderationRequest.id}/approve`, {
  method: 'POST',
  body: JSON.stringify({ role: 'admin' }),
}, viewerLogin.token)).status, 403)
assert.equal((await requestStatus(`/registration-requests/${moderationRequest.id}/reject`, {
  method: 'POST',
}, guestLogin.token)).status, 403)
const moderationApproval = await request(`/registration-requests/${moderationRequest.id}/approve`, {
  method: 'POST',
  body: JSON.stringify({ role: 'member' }),
}, token)
assert.equal(moderationApproval.user.role, 'member')

await request(
  '/module-access/customers',
  { method: 'PUT', body: JSON.stringify({ teamIds: [], userIds: [viewer.id] }) },
  token,
)
const moduleAccess = await request('/module-access', {}, token)
assert.deepEqual(moduleAccess.access, [
  { module_id: 'customers', team_id: null, user_id: viewer.id },
])
assert.deepEqual((await request('/module-access/denied', {}, viewerLogin.token)).moduleIds, [])
assert.deepEqual((await request('/module-access/denied', {}, guestLogin.token)).moduleIds, ['customers'])

const columnDefaults = [
  { id: 'name', width: 320, visible: true },
  { id: 'revision', width: 70, visible: false },
]
await request(
  '/column-defaults/organization',
  { method: 'PUT', body: JSON.stringify({ columnDefaults }) },
  token,
)
assert.deepEqual(
  (await request('/column-defaults/organization', {}, viewerLogin.token)).columnDefaults,
  columnDefaults,
)
await request(
  '/column-defaults/user',
  { method: 'PUT', body: JSON.stringify({ columnDefaults: columnDefaults.slice(0, 1) }) },
  viewerLogin.token,
)
assert.deepEqual(
  (await request('/column-defaults/user', {}, viewerLogin.token)).columnDefaults,
  columnDefaults.slice(0, 1),
)
await request(
  '/column-defaults/organization/force',
  { method: 'POST', body: JSON.stringify({ columnDefaults }) },
  token,
)
assert.deepEqual(
  (await request('/column-defaults/user', {}, guestLogin.token)).columnDefaults,
  columnDefaults,
)

const metadataColumn = await request(
  '/metadata-columns',
  {
    method: 'POST',
    body: JSON.stringify({
      name: 'material_grade',
      label: 'Material grade',
      data_type: 'select',
      select_options: ['A', 'B'],
      width: 180,
      visible: true,
      sortable: true,
      required: false,
      default_value: 'A',
      sort_order: 0,
    }),
  },
  token,
)
let metadataColumns = await request('/metadata-columns', {}, viewerLogin.token)
assert.equal(metadataColumns.columns[0].id, metadataColumn.id)
assert.deepEqual(metadataColumns.columns[0].select_options, ['A', 'B'])
await request(
  `/metadata-columns/${metadataColumn.id}`,
  { method: 'PATCH', body: JSON.stringify({ label: 'Material class', visible: false }) },
  token,
)
await request(
  `/metadata-columns/${metadataColumn.id}`,
  { method: 'PATCH', body: JSON.stringify({ label: 'Material class', visible: false }) },
  token,
)
metadataColumns = await request('/metadata-columns', {}, token)
assert.equal(metadataColumns.columns[0].label, 'Material class')
assert.equal(metadataColumns.columns[0].visible, false)
await request(`/metadata-columns/${metadataColumn.id}`, { method: 'DELETE' }, token)
assert.deepEqual((await request('/metadata-columns', {}, token)).columns, [])

assert.ok((await request('/vaults', {}, viewerLogin.token)).vaults.some((entry) => entry.id === vault.id))
assert.equal((await request('/vaults', {}, guestLogin.token)).vaults.length, 0)
await request(
  `/users/${guest.id}/vault-access`,
  { method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }) },
  token,
)
assert.deepEqual((await request(`/users/${guest.id}/vault-access`, {}, token)).vaultIds, [vault.id])
assert.ok((await request('/vaults', {}, guestLogin.token)).vaults.some((entry) => entry.id === vault.id))
const accessMap = await request('/vaults/access', {}, token)
assert.ok(accessMap.accessMap[vault.id].includes(guest.id))
await request(
  `/users/${viewer.id}/permissions`,
  {
    method: 'PUT',
    body: JSON.stringify({ vaultId: vault.id, permissions: { files: ['view'], metadata: ['view', 'edit'] } }),
  },
  token,
)
const viewerPermissions = await request(`/users/${viewer.id}/permissions?vaultId=${vault.id}`, {}, token)
assert.deepEqual(viewerPermissions.permissions, [
  { resource: 'files', actions: ['view'] },
  { resource: 'metadata', actions: ['view', 'edit'] },
])
for (const readOnlyToken of [viewerLogin.token, guestLogin.token]) {
  const deniedWrite = await fetch(`${server}/teams`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${readOnlyToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'Forbidden Team', color: '#000000', icon: 'Users' }),
  })
  assert.equal(deniedWrite.status, 403)
  assert.equal((await deniedWrite.json()).error, 'READ_ONLY_ROLE')
}

for (const removedPortal of ['/setup/', '/admin/']) {
  const response = await fetch(`${server}${removedPortal}`)
  assert.equal(response.status, 404)
  assert.equal(response.headers.get('content-type'), 'application/json; charset=utf-8')
}

stagePendingEnvironment()
const detected = await request('/installer/database-status', {
  method: 'POST',
  body: JSON.stringify({ installationToken }),
})
assert.equal(detected.database.state, 'managed')
assert.equal(detected.database.bootstrapped, true)
assert.ok(detected.database.tableCount > 0)
stagePendingEnvironment()
const migrated = await request('/installer/commit', {
  method: 'POST',
  body: JSON.stringify({ installationToken, action: 'migrate' }),
})
assert.equal(migrated.bootstrapped, false)
const usersAfterMigration = await request('/users', {}, token)
assert.ok(usersAfterMigration.users.some((user) => user.email === 'renamed.member@example.test'))
const imported = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'drawing.txt',
      fileName: 'drawing.txt',
      partNumber: 'PN-00042',
      storageRelativePath: 'drawing.txt',
    }),
  },
  token,
)
assert.equal(
  (await request('/organizations/current/serialization/exists?serial=PN-00042', {}, token)).exists,
  true,
)
assert.deepEqual(
  (await request('/organizations/current/serialization/files', {}, token)).files,
  [{ partNumber: 'PN-00042', filePath: 'drawing.txt' }],
)
assert.equal((await request(`/vaults/${vault.id}/files`, {}, token)).files[0].partNumber, 'PN-00042')
const legacyFile = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'legacy-part.sldprt',
      fileName: 'legacy-part.sldprt',
      storageRelativePath: 'legacy-part.sldprt',
    }),
  },
  token,
)
const rescannedLegacyFile = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'legacy-part.sldprt',
      fileName: 'legacy-part.sldprt',
      partNumber: 'PN-LEGACY',
      storageRelativePath: 'legacy-part.sldprt',
    }),
  },
  token,
)
assert.equal(rescannedLegacyFile.id, legacyFile.id)
assert.equal(rescannedLegacyFile.created, false)
assert.equal(
  (await request('/organizations/current/serialization/exists?serial=PN-LEGACY', {}, token)).exists,
  true,
)
const duplicatePartNumberResponse = await fetch(`${server}/files/import`, {
  method: 'POST',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
  body: JSON.stringify({
    vaultId: vault.id,
    canonicalPath: 'duplicate-part-number.sldprt',
    fileName: 'duplicate-part-number.sldprt',
    partNumber: 'PN-00042',
    storageRelativePath: 'duplicate-part-number.sldprt',
  }),
})
assert.equal(duplicatePartNumberResponse.status, 409)
assert.equal((await duplicatePartNumberResponse.json()).error, 'PART_NUMBER_EXISTS')
const trashReservedPart = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'trash-reserved-part.sldprt',
      fileName: 'trash-reserved-part.sldprt',
      partNumber: 'PN-TRASH-RESERVED',
      storageRelativePath: 'trash-reserved-part.sldprt',
    }),
  },
  token,
)
await request(`/files/${trashReservedPart.id}/trash`, { method: 'POST' }, token)
assert.equal(
  (
    await request(
      '/organizations/current/serialization/exists?serial=PN-TRASH-RESERVED',
      {},
      token,
    )
  ).exists,
  true,
)
await request(`/files/${trashReservedPart.id}/restore`, { method: 'POST' }, token)
const referencedPart = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'parts/referenced-part.sldprt',
      fileName: 'referenced-part.sldprt',
      storageRelativePath: 'parts/referenced-part.sldprt',
    }),
  },
  token,
)
const assembly = await request(
  '/files/import',
  {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'assemblies/where-used-test.sldasm',
      fileName: 'where-used-test.sldasm',
      storageRelativePath: 'assemblies/where-used-test.sldasm',
    }),
  },
  token,
)

// The desktop client's Where Used panel reads this exact route. Keep both
// directions under test: the sync must persist the reference and the reader
// must return the parent/child shape expected by the panel.
const referenceSync = await request(
  `/files/${assembly.id}/references/sync`,
  {
    method: 'POST',
    body: JSON.stringify({
      vaultRootPath: vaultRoot,
      references: [
        {
          childFilePath: join(vaultRoot, 'parts', 'referenced-part.sldprt'),
          quantity: 2,
          configuration: 'Default',
          referenceType: 'component',
        },
      ],
    }),
  },
  token,
)
assert.equal(referenceSync.inserted, 1)
const whereUsed = await request(`/files/${referencedPart.id}/references/where-used`, {}, token)
assert.equal(whereUsed.references.length, 1)
assert.equal(whereUsed.references[0].parent_file_id, assembly.id)
assert.equal(whereUsed.references[0].parent.file_name, 'where-used-test.sldasm')
const contains = await request(`/files/${assembly.id}/references/contains`, {}, token)
assert.equal(contains.references.length, 1)
assert.equal(contains.references[0].child_file_id, referencedPart.id)
const checkout = await request(
  `/files/${imported.id}/checkout`,
  { method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }) },
  token,
)
await writeFile(join(vaultRoot, 'drawing.txt'), 'revision 2\n', 'utf8')
const checkin = await request(
  `/files/${imported.id}/checkin`,
  {
    method: 'POST',
    body: JSON.stringify({
      checkoutToken: checkout.checkoutToken,
      storageRelativePath: 'drawing.txt',
      comment: 'Second revision',
      partNumber: 'PN-00043',
    }),
  },
  token,
)
assert.equal(checkin.revision, 2)
assert.equal(
  (await request('/organizations/current/serialization/exists?serial=PN-00042', {}, token)).exists,
  false,
)
assert.equal(
  (await request('/organizations/current/serialization/exists?serial=PN-00043', {}, token)).exists,
  true,
)

console.log('PHP API, installer lifecycle, MariaDB, and network vault integration passed.')
