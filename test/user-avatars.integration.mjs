import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { mkdtempSync, rmdirSync, unlinkSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { tmpdir } from 'node:os'

const expectedOwner = process.env.BLUEPLM_EXPECTED_PHP_OWNER
assert.ok(expectedOwner, 'BLUEPLM_EXPECTED_PHP_OWNER must identify the approved PHP owner worktree')
const actualOwner = execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: process.cwd(), encoding: 'utf8' }).trim()
assert.equal(resolve(process.cwd()), resolve(expectedOwner), 'test process CWD must be the approved PHP owner')
assert.equal(resolve(actualOwner), resolve(expectedOwner), 'Git top-level must be the approved PHP owner')

const project = `blueplm-avatar-${process.pid}-${Date.now()}`
const port = 18244
const server = `http://127.0.0.1:${port}`
const docker = process.env.BLUEPLM_DOCKER_BIN || 'docker'
const compose = ['compose', '-p', project, '-f', 'docker-compose.test.yml']
const testSubnet = process.env.BLUEPLM_AVATAR_TEST_SUBNET
const networkOverrideDirectory = mkdtempSync(join(tmpdir(), 'blueplm-avatar-compose-'))
const networkOverridePath = join(networkOverrideDirectory, 'compose-network.yml')
if (testSubnet) {
  assert.match(testSubnet, /^(?:\d{1,3}\.){3}\d{1,3}\/(?:24|28)$/u, 'avatar test subnet must be an explicit /24 or /28 IPv4 CIDR')
  const octets = testSubnet.split('/')[0].split('.').map(Number)
  assert.ok(octets.every(octet => octet >= 0 && octet <= 255), 'avatar test subnet octets must be valid IPv4 values')
}
writeFileSync(networkOverridePath, [
  'services:',
  '  api:',
  '    build:',
  '      network: none',
  '    ports: !override',
  `      - "127.0.0.1:${port}:80"`,
  '  mariadb:',
  '    tmpfs:',
  '      - /var/lib/mysql:rw,noexec,nosuid,size=1g',
  ...(testSubnet ? [
    'networks:',
    '  default:',
    '    ipam:',
    '      config:',
      `        - subnet: ${testSubnet}`,
  ] : []),
  '',
].join('\n'), { flag: 'wx' })
compose.push('-f', networkOverridePath)
const installToken = 'integration-installation-token-must-be-at-least-32-chars'
const maintenanceToken = 'integration-maintenance-token-must-be-at-least-32-chars'
const password = 'Avatar owner password 123!'
const run = (args, options = {}) => execFileSync(docker, args, {
  cwd: process.cwd(),
  stdio: 'pipe',
  env: { ...process.env, BLUEPLM_PHP_TEST_PORT: String(port) },
  ...options,
})
const sql = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
const rows = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement]).toString().trim().split(/\r?\n/).filter(Boolean).map(line => line.split('\t'))

async function call(path, token, { method = 'GET', body } = {}, expected = 200) {
  const response = await fetch(`${server}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })
  assert.equal(response.status, expected, `${method} ${path} returned ${response.status}`)
  return response.status === 204 ? null : response.json()
}

async function putAvatar(userToken, bytes, mime = 'image/png', expected = 204) {
  const response = await fetch(`${server}/users/current/avatar`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${userToken}`, 'Content-Type': mime },
    body: bytes,
  })
  assert.equal(response.status, expected, `PUT avatar returned ${response.status}: ${(await response.clone().text()).slice(0, 300)}`)
  return response
}

async function getAvatar(userToken, userId, expected = 200) {
  const response = await fetch(`${server}/users/${userId}/avatar`, { headers: userToken ? { Authorization: `Bearer ${userToken}` } : {} })
  assert.equal(response.status, expected, `GET avatar returned ${response.status}: ${(await response.clone().text()).slice(0, 300)}`)
  return response
}

function createMember(name, organizationId, role) {
  const id = randomUUID()
  const token = `avatar-session-${randomUUID()}`
  const hash = createHash('sha256').update(`test-session-secret-must-be-at-least-thirty-two-characters:${token}`).digest('hex')
  sql(`INSERT INTO users(id,email,display_name,password_hash) VALUES('${id}','${name}@avatar.test','${name}','fixture'); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${organizationId}','${id}','${role}'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${hash}','${id}','${organizationId}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)
  return { id, token }
}

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jVs8AAAAASUVORK5CYII=', 'base64')
const gif = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')
const jpeg = Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAgBAQABPyF//9k=', 'base64')
const webp = Buffer.from('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89', 'base64')
const validJpeg = Buffer.from('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDABQODxIPDRQSERIXFhQYHzMhHxwcHz8tLyUzSkFOTUlBSEZSXHZkUldvWEZIZoxob3p9hIWET2ORm4+AmnaBhH//2wBDARYXFx8bHzwhITx/VEhUf39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f39/f3//wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDs6KKKAP/Z', 'base64')

try {
  const renderedConfig = JSON.parse(run([...compose, 'config', '--format', 'json'], { encoding: 'utf8' }))
  assert.deepEqual(renderedConfig.services.api.ports, [{ mode: 'ingress', host_ip: '127.0.0.1', target: 80, published: String(port), protocol: 'tcp' }])
  assert.equal(renderedConfig.services.api.build.network, 'none', 'API image build must not use a network')
  assert.deepEqual(renderedConfig.services.mariadb.ports ?? [], [], 'database must not publish a host port')
  assert.ok(renderedConfig.services.mariadb.tmpfs.some(mount => mount.startsWith('/var/lib/mysql:')), 'database files must be stored in container tmpfs')
  assert.deepEqual(renderedConfig.services.api.volumes ?? [], [], 'API must not bind-mount host paths')
  assert.deepEqual(renderedConfig.services.mariadb.volumes ?? [], [], 'database must not bind-mount or attach named volumes')
  assert.deepEqual(Object.keys(renderedConfig.volumes ?? {}), [], 'Compose must not declare persistent volumes')
  if (testSubnet) assert.equal(renderedConfig.networks.default.ipam.config[0].subnet, testSubnet)
  console.log(JSON.stringify({
    apiPorts: renderedConfig.services.api.ports,
    apiBuildNetwork: renderedConfig.services.api.build.network,
    mariadbPorts: renderedConfig.services.mariadb.ports ?? [],
    mariadbTmpfs: renderedConfig.services.mariadb.tmpfs,
    bindMounts: 0,
    persistentVolumes: Object.keys(renderedConfig.volumes ?? {}).length,
    networkSubnet: testSubnet ?? null,
  }))

  run([...compose, 'up', '--build', '--pull', 'never', '-d', '--wait'])
  for (let attempt = 0; attempt < 40; attempt++) {
    try {
      const response = await fetch(`${server}/health`)
      if (response.ok) break
    } catch {}
    if (attempt === 39) throw new Error('Isolated avatar API did not become healthy.')
    await new Promise(resolve => setTimeout(resolve, 250))
  }

  const installed = await call('/installer/commit', null, {
    method: 'POST',
    body: {
      installationToken: installToken,
      action: 'install',
      bootstrap: { organizationName: 'Avatar Org', organizationSlug: `avatar-${process.pid}`, email: 'owner@avatar.test', displayName: 'Avatar Owner', password },
    },
  }, 200)
  const ownerToken = installed.token
  const owner = (await call('/auth/me', ownerToken)).user
  const org2 = randomUUID()
  sql(`INSERT INTO organizations(id,name,slug) VALUES('${org2}','Other Tenant','avatar-other-${process.pid}')`)
  const viewer = createMember('viewer', owner.organizationId, 'viewer')
  const outsider = createMember('outsider', org2, 'member')

  await getAvatar(viewer.token, owner.userId, 404)
  await getAvatar(null, owner.userId, 401)
  const profile = (await call(`/users/${viewer.id}/profile`, ownerToken)).user
  assert.equal(Object.keys(profile).some(key => key.toLowerCase().includes('avatar')), false, 'profile DTO stays free of image bytes and URLs')

  const migrations = await call('/admin/migrate', ownerToken, { method: 'POST', body: { maintenanceToken } }, 200)
  assert.equal(migrations.applied.includes('044_user_avatars.sql'), false, 'already applied migration is not applied twice')
  assert.equal(rows("SELECT migration FROM schema_migrations WHERE migration='044_user_avatars.sql'").length, 1)

  assert.equal((await putAvatar(ownerToken, png)).status, 204)
  let ownerImage = await getAvatar(viewer.token, owner.userId)
  assert.equal(ownerImage.headers.get('content-type'), 'image/png')
  assert.equal(ownerImage.headers.get('x-content-type-options'), 'nosniff')
  assert.equal(ownerImage.headers.get('cache-control'), 'private, no-store')
  assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), png)

  for (const [mime, bytes] of [['image/jpeg', validJpeg], ['image/webp', webp]]) {
    assert.equal((await putAvatar(ownerToken, bytes, mime)).status, 204, `${mime} fixture is accepted`)
    ownerImage = await getAvatar(viewer.token, owner.userId)
    assert.equal(ownerImage.headers.get('content-type'), mime)
    assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), bytes)
  }
  assert.equal((await putAvatar(ownerToken, png)).status, 204, 'restore PNG baseline before invalid replacement tests')
  ownerImage = await getAvatar(viewer.token, owner.userId)
  assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), png)

  assert.equal((await putAvatar(viewer.token, gif, 'image/gif')).status, 204, 'viewer can write only their own avatar')
  const viewerImage = await getAvatar(ownerToken, viewer.id)
  assert.equal(viewerImage.headers.get('content-type'), 'image/gif')
  assert.deepEqual(Buffer.from(await viewerImage.arrayBuffer()), gif)
  assert.equal((await fetch(`${server}/users/${viewer.id}/avatar`, { method: 'PUT', headers: { Authorization: `Bearer ${ownerToken}`, 'Content-Type': 'image/png' }, body: png })).status, 404, 'other-user write path does not exist')
  const crossTenant = await getAvatar(outsider.token, owner.userId, 404)
  assert.equal((await crossTenant.json()).user, undefined, 'cross-tenant miss does not disclose identity')

  const invalids = [
    { bytes: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"></svg>'), mime: 'image/svg+xml', status: 400 },
    { bytes: jpeg, mime: 'image/jpeg', status: 400 },
    { bytes: png.subarray(0, png.length - 12), mime: 'image/png', status: 400 },
    { bytes: png, mime: 'image/jpeg', status: 400 },
    { bytes: Buffer.alloc(2 * 1024 * 1024 + 1), mime: 'image/png', status: 413 },
  ]
  const oversizedDimensions = Buffer.from(png)
  oversizedDimensions.writeUInt32BE(5000, 16)
  invalids.push({ bytes: oversizedDimensions, mime: 'image/png', status: 400 })
  for (const invalid of invalids) await putAvatar(ownerToken, invalid.bytes, invalid.mime, invalid.status)
  ownerImage = await getAvatar(viewer.token, owner.userId)
  assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), png, 'invalid replacement leaves prior image intact')

  sql("CREATE TRIGGER avatar_test_replacement_failure BEFORE UPDATE ON user_avatars FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic avatar replacement failure'")
  await putAvatar(ownerToken, gif, 'image/gif', 500)
  sql('DROP TRIGGER avatar_test_replacement_failure')
  ownerImage = await getAvatar(viewer.token, owner.userId)
  assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), png, 'database replacement failure leaves prior image intact')

  assert.equal((await putAvatar(ownerToken, gif, 'image/gif')).status, 204)
  ownerImage = await getAvatar(viewer.token, owner.userId)
  assert.equal(ownerImage.headers.get('content-type'), 'image/gif')
  assert.deepEqual(Buffer.from(await ownerImage.arrayBuffer()), gif)

  assert.equal(await call('/users/current/avatar', ownerToken, { method: 'DELETE' }, 204), null)
  await getAvatar(viewer.token, owner.userId, 404)
  await call('/users/current/avatar', ownerToken, { method: 'DELETE' }, 204)

  await putAvatar(viewer.token, png)
  sql(`DELETE FROM organization_memberships WHERE organization_id='${owner.organizationId}' AND user_id='${viewer.id}'`)
  await getAvatar(ownerToken, viewer.id, 404)
  assert.deepEqual(rows(`SELECT user_id FROM user_avatars WHERE organization_id='${owner.organizationId}' AND user_id='${viewer.id}'`), [], 'membership removal cascades avatar bytes')

  console.log('MDB user-avatar integration contract passed.')
} finally {
  try { run([...compose, 'down']) } catch (error) {
    console.error(`Isolated avatar Compose cleanup failed for project ${project}: ${error.message}`)
    process.exitCode = 1
  } finally {
    unlinkSync(networkOverridePath)
    rmdirSync(networkOverrideDirectory)
  }
}
