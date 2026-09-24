import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtemp, mkdir, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const server = process.env.BLUEPLM_PHP_TEST_SERVER ?? 'http://127.0.0.1:18080'
const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Integration password 123!'

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
