import assert from 'node:assert/strict'
import { mkdtemp, mkdir, writeFile, readFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawn } from 'node:child_process'

const server = process.env.BLUEPLM_PHP_TEST_SERVER ?? 'http://127.0.0.1:18080'
const bootstrapToken = 'integration-bootstrap-token-must-be-at-least-32-chars'
const maintenanceToken = 'integration-maintenance-token-must-be-at-least-32-chars'
const password = 'Integration password 123!'

async function request(path, init = {}, token) {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(`${server}${path}`, { ...init, headers })
  const body = response.status === 204 ? undefined : await response.json()
  assert.ok(response.ok, `${init.method ?? 'GET'} ${path}: ${response.status} ${JSON.stringify(body)}`)
  return body
}

async function waitForHealth() {
  let lastError
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const health = await request('/health')
      if (health.ok === true && health.supabase === false) {
        // The HTTP server can be ready a moment before MariaDB accepts its
        // first connection. An intentionally invalid bootstrap call is only
        // rejected with 403 after the database connection is usable.
        const databaseProbe = await fetch(`${server}/admin/migrate`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ bootstrapToken: 'invalid' }),
        })
        if (databaseProbe.status === 403) return
      }
    } catch (error) { lastError = error }
    await new Promise(resolve => setTimeout(resolve, 500))
  }
  throw new Error(`PHP API did not become healthy: ${String(lastError)}`)
}

function run(command, args, options) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { ...options, stdio: 'pipe' })
    let output = ''
    child.stdout.on('data', chunk => { output += chunk })
    child.stderr.on('data', chunk => { output += chunk })
    child.on('error', reject)
    child.on('exit', code => code === 0 ? resolve(output) : reject(new Error(`${command} failed (${code}): ${output}`)))
  })
}

await waitForHealth()
const corsResponse = await fetch(`${server}/health`, { headers: { Origin: 'null' } })
assert.equal(corsResponse.headers.get('access-control-allow-origin'), 'null')
const deniedMigration = await fetch(`${server}/admin/migrate`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ maintenanceToken: 'invalid' }) })
assert.equal(deniedMigration.status, 403)
const migration = await request('/admin/migrate', { method: 'POST', body: JSON.stringify({ maintenanceToken }) })
assert.ok(Array.isArray(migration.applied) && migration.applied.length >= 16)

const bootstrapped = await request('/auth/bootstrap', {
  method: 'POST',
  body: JSON.stringify({ bootstrapToken, organizationName: 'Integration Org', organizationSlug: 'integration-org', email: 'owner@example.test', displayName: 'Owner', password }),
})
assert.equal(typeof bootstrapped.token, 'string')
const login = await request('/auth/login', { method: 'POST', body: JSON.stringify({ email: 'owner@example.test', password }) })
const token = login.token

// Community user management must remain a first-class API: no Supabase auth
// endpoint is involved when an administrator creates or changes an account.
const createdUser = await request('/users', {
  method: 'POST',
  body: JSON.stringify({
    email: 'member@example.test',
    displayName: 'Initial Member',
    password: 'Initial member password 123!',
  }),
}, token)
assert.equal(createdUser.email, 'member@example.test')
const usersBeforeUpdate = await request('/users', {}, token)
assert.ok(usersBeforeUpdate.users.some(user => user.id === createdUser.id))
const memberLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'member@example.test', password: 'Initial member password 123!' }),
})
const updatedUser = await request(`/users/${createdUser.id}`, {
  method: 'PATCH',
  body: JSON.stringify({
    email: 'renamed.member@example.test',
    displayName: 'Renamed Member',
    password: 'Updated member password 123!',
  }),
}, token)
assert.equal(updatedUser.user.email, 'renamed.member@example.test')
assert.equal(updatedUser.user.displayName, 'Renamed Member')
const revokedSession = await fetch(`${server}/auth/me`, {
  headers: { Authorization: `Bearer ${memberLogin.token}` },
})
assert.equal(revokedSession.status, 401)
const updatedMemberLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: 'renamed.member@example.test', password: 'Updated member password 123!' }),
})
assert.equal(typeof updatedMemberLogin.token, 'string')

// Teams are used by the desktop Community backend. A missing GET /teams
// route makes the client surface "Failed to load teams" immediately after
// successful login, so retain this as an end-to-end compatibility contract.
const initialTeams = await request('/teams', {}, token)
assert.deepEqual(initialTeams.teams, [])
const createdTeam = await request('/teams', {
  method: 'POST',
  body: JSON.stringify({ name: 'Integration Team', color: '#2563eb', icon: 'Users' }),
}, token)
assert.equal(createdTeam.name, 'Integration Team')
const teams = await request('/teams', {}, token)
assert.ok(teams.teams.some(team => team.id === createdTeam.id && team.memberCount === 0))
const updatedTeam = await request(`/teams/${createdTeam.id}`, {
  method: 'PATCH',
  body: JSON.stringify({ name: 'Renamed Integration Team', color: '#16a34a', icon: 'UsersRound' }),
}, token)
assert.equal(updatedTeam.name, 'Renamed Integration Team')
await request(`/teams/${createdTeam.id}/members`, {
  method: 'POST', body: JSON.stringify({ userId: createdUser.id }),
}, token)
const userTeams = await request(`/users/${createdUser.id}/teams`, {}, token)
assert.ok(userTeams.teams.some(team => team.id === createdTeam.id))
const teamMembers = await request(`/teams/${createdTeam.id}/members`, {}, token)
assert.ok(teamMembers.members.some(member => member.userId === createdUser.id))

const root = await mkdtemp(join(tmpdir(), 'blueplm-php-vault-'))
const vaultRoot = join(root, 'vault')
const workspace = join(root, 'workspace')
await mkdir(vaultRoot)
await mkdir(workspace)
await writeFile(join(vaultRoot, 'drawing.txt'), 'revision 1\n', 'utf8')

const vault = await request('/vaults', { method: 'POST', body: JSON.stringify({ name: 'Integration Vault', networkRoot: vaultRoot }) }, token)
await request(`/teams/${createdTeam.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }),
}, token)
const teamVaultAccess = await request(`/teams/${createdTeam.id}/vault-access`, {}, token)
assert.deepEqual(teamVaultAccess.vaultIds, [vault.id])
const defaultTeam = await request('/organizations/current/settings', {
  method: 'PUT', body: JSON.stringify({ defaultNewUserTeamId: createdTeam.id }),
}, token)
assert.equal(defaultTeam.defaultNewUserTeamId, createdTeam.id)

// The PHP administration page deliberately has its own session and CSRF
// boundary; the bearer token used by the desktop client must never appear in
// HTML or be accepted as a portal login.
const portalLogin = await fetch(`${server}/admin/`, {
  method: 'POST',
  redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ action: 'login', email: 'owner@example.test', password }),
})
assert.equal(portalLogin.status, 303)
const portalCookie = portalLogin.headers.getSetCookie().at(-1)?.split(';')[0]
assert.ok(portalCookie)
const portalDashboard = await fetch(`${server}/admin/`, { headers: { Cookie: portalCookie } })
const portalHtml = await portalDashboard.text()
assert.equal(portalDashboard.status, 200)
assert.match(portalHtml, /Company configuration/)
const germanPortal = await fetch(`${server}/admin/?lang=de`, { headers: { Cookie: portalCookie } })
assert.equal(germanPortal.status, 200)
assert.match(await germanPortal.text(), /Firmenkonfiguration/)
assert.doesNotMatch(portalHtml, /integration-bootstrap-token/)
const csrf = portalHtml.match(/name="csrf" value="([a-f0-9]{64})"/)?.[1]
assert.ok(csrf)
const rejectedPortalMutation = await fetch(`${server}/admin/`, {
  method: 'POST',
  redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: portalCookie },
  body: new URLSearchParams({ action: 'save-company', name: 'Should not save' }),
})
assert.equal(rejectedPortalMutation.status, 403)
const savedCompany = await fetch(`${server}/admin/`, {
  method: 'POST',
  redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: portalCookie },
  body: new URLSearchParams({
    action: 'save-company', csrf, name: 'Integration Org GmbH', phone: '+49 555 123',
    website: 'https://example.test', contactEmail: 'office@example.test',
    logoStoragePath: 'brand/logo.png', defaultNewUserTeamId: createdTeam.id,
  }),
})
assert.equal(savedCompany.status, 303)
const organizationAfterPortalSave = await request('/organizations/current', {}, token)
assert.equal(organizationAfterPortalSave.organization.name, 'Integration Org GmbH')
const profileAfterPortalSave = await request('/organizations/current/profile', {}, token)
assert.equal(profileAfterPortalSave.profile.contact_email, 'office@example.test')
const createdPortalUser = await fetch(`${server}/admin/`, {
  method: 'POST',
  redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: portalCookie },
  body: new URLSearchParams({
    action: 'create-user', csrf, displayName: 'Portal member', email: 'portal.member@example.test',
    password: 'Portal member password 123!', role: 'member',
  }),
})
assert.equal(createdPortalUser.status, 303)
const usersAfterPortalCreate = await request('/users', {}, token)
assert.ok(usersAfterPortalCreate.users.some(user => user.email === 'portal.member@example.test'))
const imported = await request('/files/import', { method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'drawing.txt', fileName: 'drawing.txt', storageRelativePath: 'drawing.txt' }) }, token)
const referencedPart = await request('/files/import', { method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'parts/referenced-part.sldprt', fileName: 'referenced-part.sldprt', storageRelativePath: 'parts/referenced-part.sldprt' }) }, token)
const assembly = await request('/files/import', { method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'assemblies/where-used-test.sldasm', fileName: 'where-used-test.sldasm', storageRelativePath: 'assemblies/where-used-test.sldasm' }) }, token)

// The desktop client's Where Used panel reads this exact route. Keep both
// directions under test: the sync must persist the reference and the reader
// must return the parent/child shape expected by the panel.
const referenceSync = await request(`/files/${assembly.id}/references/sync`, {
  method: 'POST',
  body: JSON.stringify({
    vaultRootPath: vaultRoot,
    references: [{
      childFilePath: join(vaultRoot, 'parts', 'referenced-part.sldprt'),
      quantity: 2,
      configuration: 'Default',
      referenceType: 'component',
    }],
  }),
}, token)
assert.equal(referenceSync.inserted, 1)
const whereUsed = await request(`/files/${referencedPart.id}/references/where-used`, {}, token)
assert.equal(whereUsed.references.length, 1)
assert.equal(whereUsed.references[0].parent_file_id, assembly.id)
assert.equal(whereUsed.references[0].parent.file_name, 'where-used-test.sldasm')
const contains = await request(`/files/${assembly.id}/references/contains`, {}, token)
assert.equal(contains.references.length, 1)
assert.equal(contains.references[0].child_file_id, referencedPart.id)
const checkout = await request(`/files/${imported.id}/checkout`, { method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }) }, token)
await writeFile(join(vaultRoot, 'drawing.txt'), 'revision 2\n', 'utf8')
const checkin = await request(`/files/${imported.id}/checkin`, { method: 'POST', body: JSON.stringify({ checkoutToken: checkout.checkoutToken, storageRelativePath: 'drawing.txt', comment: 'Second revision' }) }, token)
assert.equal(checkin.revision, 2)

const clientRoot = join(process.cwd(), '..', 'blueplm-community-client')
await run('node', ['dist/cli.js', 'configure', '--server', server, '--workspace', workspace], { cwd: clientRoot, env: { ...process.env, APPDATA: join(root, 'appdata') } })
await run('node', ['dist/cli.js', 'login', '--email', 'owner@example.test'], { cwd: clientRoot, env: { ...process.env, APPDATA: join(root, 'appdata'), BLUEPLM_PASSWORD: password } })
const listed = await run('node', ['dist/cli.js', 'vault-list'], { cwd: clientRoot, env: { ...process.env, APPDATA: join(root, 'appdata') } })
assert.match(listed, /Integration Vault/)

const checkoutOutput = await run('node', ['dist/cli.js', 'checkout', vault.id, imported.id], { cwd: clientRoot, env: { ...process.env, APPDATA: join(root, 'appdata') } })
assert.match(checkoutOutput, /Checked out/)
assert.equal(await readFile(join(workspace, 'drawing.txt'), 'utf8'), 'revision 2\n')

console.log('PHP API, MariaDB, vault and Community CLI integration passed.')
