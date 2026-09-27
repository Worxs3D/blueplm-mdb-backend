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

async function waitForHealth() {
  let lastError
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const health = await request('/health')
      if (health.ok === true && health.supabase === false) {
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

// Community user management must remain a first-class API: no Supabase auth
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

// Teams are used by the desktop Community backend. A missing GET /teams
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
      storageRelativePath: 'drawing.txt',
    }),
  },
  token,
)
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
    }),
  },
  token,
)
assert.equal(checkin.revision, 2)

console.log('PHP API, installer lifecycle, MariaDB, and network vault integration passed.')
