import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { resolve } from 'node:path'

const sessionSecret = 'test-session-secret-must-be-at-least-thirty-two-characters'
const sessionTokenPrefix = 'organization-logo-session-'
const maxBytes = 2 * 1024 * 1024

function assertTestOwner() {
  const expectedOwner = process.env.BLUEPLM_EXPECTED_ROOT
  assert.ok(expectedOwner, 'BLUEPLM_EXPECTED_ROOT must identify the approved PHP owner worktree')
  const actualOwner = execFileSync('git', ['rev-parse', '--show-toplevel'], {
    cwd: process.cwd(),
    encoding: 'utf8',
  }).trim()
  assert.equal(resolve(process.cwd()), resolve(expectedOwner), 'test process CWD must be the approved PHP owner')
  assert.equal(resolve(actualOwner), resolve(expectedOwner), 'Git top-level must be the approved PHP owner')
}

export async function runOrganizationLogoIntegration({ server, ownerToken, organizationId }) {
  assertTestOwner()
  const project = process.env.COMPOSE_PROJECT_NAME
  assert.ok(project, 'COMPOSE_PROJECT_NAME must identify the isolated integration stack')
  const docker = process.env.BLUEPLM_DOCKER_BIN || 'docker'
  const compose = ['compose', '-p', project, '-f', 'docker-compose.test.yml']
  const sql = statement => execFileSync(docker, [
    ...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement,
  ], { cwd: process.cwd(), stdio: 'pipe' })
  const rows = statement => execFileSync(docker, [
    ...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim().split(/\r?\n/u).filter(Boolean)

  const migration = rows("SELECT migration FROM schema_migrations WHERE migration='047_organization_logos.sql'")
  assert.deepEqual(migration, ['047_organization_logos.sql'], 'logo migration is applied and recorded once')

  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVs8AAAAASUVORK5CYII=', 'base64')
  const gif = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
  const invalidJpeg = Buffer.from([0xff, 0xd8])
  const upload = async (token, bytes, mime) => fetch(`${server}/organizations/current/logo`, {
    method: 'PUT',
    headers: { ...(token ? { Authorization: `Bearer ${token}` } : {}), 'Content-Type': mime },
    body: bytes,
  })
  const read = async token => fetch(`${server}/organizations/current/logo`, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
  const remove = async token => fetch(`${server}/organizations/current/logo`, {
    method: 'DELETE',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })

  const newOrgId = randomUUID()
  const suffix = randomUUID().replaceAll('-', '')
  sql(`INSERT INTO organizations(id,name,slug) VALUES('${newOrgId}','Logo Isolation Fixture','logo-${suffix}')`)

  function createMembership(tenantId, role, name, disabled = false) {
    const userId = randomUUID()
    const token = `${sessionTokenPrefix}${randomUUID()}`
    const tokenHash = createHash('sha256').update(`${sessionSecret}:${token}`).digest('hex')
    const disabledAt = disabled ? 'UTC_TIMESTAMP(3)' : 'NULL'
    sql(`INSERT INTO users(id,email,display_name,password_hash,disabled_at) VALUES('${userId}','${name}-${userId}@logo.test','${name}','fixture',${disabledAt}); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${tenantId}','${userId}','${role}'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${tokenHash}','${userId}','${tenantId}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)
    return { userId, token }
  }

  const member = createMembership(organizationId, 'member', 'LogoMember')
  const guest = createMembership(organizationId, 'guest', 'LogoGuest')
  const admin = createMembership(organizationId, 'admin', 'LogoAdmin')
  const inactive = createMembership(organizationId, 'member', 'LogoInactive', true)
  const foreignMember = createMembership(newOrgId, 'owner', 'LogoForeignOwner')

  const unauthenticatedRead = await read(null)
  assert.equal(unauthenticatedRead.status, 401, 'logo reads require an authenticated active organization membership')
  const inactiveRead = await read(inactive.token)
  assert.equal(inactiveRead.status, 401, 'disabled users cannot read organization logos')

  let response = await read(ownerToken)
  assert.equal(response.status, 404, 'an organization without either a current logo or legacy reference has no logo')
  const emptyProfile = await fetch(`${server}/organizations/current/profile`, { headers: { Authorization: `Bearer ${ownerToken}` } })
  const emptyProfileJson = await emptyProfile.json()
  assert.equal(emptyProfile.status, 200)
  assert.equal(emptyProfileJson.profile.logo_configured, false)
  assert.equal(emptyProfileJson.profile.logo_asset_ref, null)
  response = await read(foreignMember.token)
  assert.equal(response.status, 404, 'another organization cannot read the current organization logo')

  sql(`UPDATE organizations SET logo_storage_path='../../legacy/logo.png' WHERE id='${organizationId}'`)
  const legacyProfile = await fetch(`${server}/organizations/current/profile`, { headers: { Authorization: `Bearer ${ownerToken}` } })
  assert.equal(legacyProfile.status, 200)
  assert.equal((await legacyProfile.json()).profile.logo_configured, true, 'profile metadata preserves the configured legacy state without resolving it')
  response = await read(ownerToken)
  assert.equal(response.status, 409, 'unresolved legacy logo references must fail explicitly')
  assert.equal((await response.json()).error, 'LEGACY_LOGO_UNAVAILABLE')

  assert.equal((await upload(null, png, 'image/png')).status, 401, 'logo writes require a current session')
  assert.equal((await upload(inactive.token, png, 'image/png')).status, 401, 'disabled users cannot write organization logos')
  assert.equal((await upload(member.token, png, 'image/png')).status, 403, 'ordinary members cannot manage the organization logo')
  assert.equal((await upload(guest.token, png, 'image/png')).status, 403, 'guest members cannot manage the organization logo')
  assert.equal((await remove(guest.token)).status, 403, 'guest members cannot delete the organization logo')
  assert.equal((await upload(admin.token, png, 'image/png')).status, 200, 'an active organization admin can manage the logo')

  const firstWrite = await upload(ownerToken, png, 'image/png')
  assert.equal(firstWrite.status, 200, `owner logo upload failed: ${(await firstWrite.clone().text()).slice(0, 200)}`)
  const first = await firstWrite.json()
  assert.deepEqual(Object.keys(first), ['logo'])
  assert.deepEqual(Object.keys(first.logo).sort(), ['assetRef', 'contentType', 'updatedAt'].sort())
  assert.match(first.logo.assetRef, /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu)
  assert.equal(first.logo.contentType, 'image/png')
  assert.match(first.logo.updatedAt, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/u)
  assert.equal(JSON.stringify(first).includes('storage'), false, 'write response exposes no storage key/path')
  const configuredProfile = await fetch(`${server}/organizations/current/profile`, { headers: { Authorization: `Bearer ${ownerToken}` } })
  const configuredProfileJson = await configuredProfile.json()
  assert.equal(configuredProfileJson.profile.logo_configured, true)
  assert.equal(configuredProfileJson.profile.logo_asset_ref, first.logo.assetRef, 'profile exposes only the opaque asset reference')

  response = await read(ownerToken)
  assert.equal(response.status, 200)
  assert.equal(response.headers.get('content-type'), 'image/png')
  assert.equal(response.headers.get('content-disposition'), 'inline')
  assert.equal(response.headers.get('x-content-type-options'), 'nosniff')
  assert.equal(response.headers.get('cache-control'), 'private, no-store')
  assert.equal(response.headers.get('x-blueplm-asset-ref'), first.logo.assetRef)
  assert.equal(response.headers.get('etag'), `"${createHash('sha256').update(png).digest('hex')}"`)
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), png)

  const guestRead = await read(guest.token)
  assert.equal(guestRead.status, 200, 'an active guest membership can read the organization profile asset')
  assert.deepEqual(Buffer.from(await guestRead.arrayBuffer()), png)
  const guestProfile = await fetch(`${server}/organizations/current/profile`, { headers: { Authorization: `Bearer ${guest.token}` } })
  assert.equal((await guestProfile.json()).profile.logo_asset_ref, first.logo.assetRef, 'active guests receive only the opaque logo reference in the profile contract')
  assert.equal((await read(foreignMember.token)).status, 404, 'foreign-organization isolation remains after upload')
  assert.equal((await upload(foreignMember.token, gif, 'image/gif')).status, 200, 'an owner of another tenant may write only that tenant logo')
  const foreignRead = await read(foreignMember.token)
  assert.equal(foreignRead.status, 200)
  assert.deepEqual(Buffer.from(await foreignRead.arrayBuffer()), gif)
  const ownerReadAfterForeignWrite = await read(ownerToken)
  assert.deepEqual(Buffer.from(await ownerReadAfterForeignWrite.arrayBuffer()), png, 'foreign writes do not replace this tenant logo')

  const invalids = [
    { name: 'SVG is not a supported raster image', bytes: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), mime: 'image/svg+xml', status: 400 },
    { name: 'malformed JPEG container', bytes: invalidJpeg, mime: 'image/jpeg', status: 400 },
    { name: 'truncated PNG container', bytes: png.subarray(0, png.length - 12), mime: 'image/png', status: 400 },
    { name: 'declared MIME mismatch', bytes: png, mime: 'image/jpeg', status: 400 },
    { name: '2 MiB byte limit', bytes: Buffer.alloc(maxBytes + 1), mime: 'image/png', status: 413 },
  ]
  const oversizedDimensions = Buffer.from(png)
  oversizedDimensions.writeUInt32BE(5000, 16)
  invalids.push({ name: 'dimension limit', bytes: oversizedDimensions, mime: 'image/png', status: 400 })
  for (const invalid of invalids) {
    const invalidResponse = await upload(ownerToken, invalid.bytes, invalid.mime)
    assert.equal(invalidResponse.status, invalid.status, `${invalid.name} rejected with ${invalid.status}`)
  }
  response = await read(ownerToken)
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), png, 'invalid replacements leave the prior logo intact')

  const trigger = `logo_reject_${suffix}`
  sql(`CREATE TRIGGER ${trigger} BEFORE UPDATE ON organization_logos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic organization logo write failure'`)
  try {
    const failedWrite = await upload(ownerToken, gif, 'image/gif')
    assert.equal(failedWrite.status, 500, 'database errors fail the update without exposing database details')
  } finally {
    sql(`DROP TRIGGER ${trigger}`)
  }
  response = await read(ownerToken)
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), png, 'failed transaction preserves prior image bytes')

  const replacement = await upload(ownerToken, gif, 'image/gif')
  assert.equal(replacement.status, 200)
  const updated = await replacement.json()
  assert.notEqual(updated.logo.assetRef, first.logo.assetRef, 'replacement rotates the opaque organization asset reference')
  assert.equal(updated.logo.contentType, 'image/gif')
  response = await read(ownerToken)
  assert.equal(response.headers.get('content-type'), 'image/gif')
  assert.equal(response.headers.get('x-blueplm-asset-ref'), updated.logo.assetRef)
  assert.deepEqual(Buffer.from(await response.arrayBuffer()), gif)
  assert.equal(rows(`SELECT logo_storage_path FROM organizations WHERE id='${organizationId}'`)[0], '../../legacy/logo.png', 'legacy value is preserved, not interpreted or rewritten')

  assert.equal((await remove(member.token)).status, 403)
  assert.equal((await remove(ownerToken)).status, 204)
  assert.equal((await remove(ownerToken)).status, 204, 'logo deletion is idempotent')
  const deletedProfile = await fetch(`${server}/organizations/current/profile`, { headers: { Authorization: `Bearer ${ownerToken}` } })
  const deletedProfileJson = await deletedProfile.json()
  assert.equal(deletedProfileJson.profile.logo_configured, true, 'legacy metadata remains reported without implying that it was loaded')
  assert.equal(deletedProfileJson.profile.logo_asset_ref, null)
  response = await read(ownerToken)
  assert.equal(response.status, 409, 'legacy reference remains explicitly unresolved after deleting the new asset')
  assert.equal((await response.json()).error, 'LEGACY_LOGO_UNAVAILABLE')
  assert.deepEqual(rows(`SELECT organization_id FROM organization_logos WHERE organization_id='${organizationId}'`), [])

  console.log('MDB organization-logo integration contract passed.')
}
