import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

function optionsFromArgs(argv) {
  const options = {}
  for (let index = 0; index < argv.length; index++) {
    const key = argv[index]
    if (key === '--config-only') {
      options.configOnly = true
      continue
    }
    if (!['--project', '--port', '--subnet', '--override', '--docker', '--evidence'].includes(key)) {
      throw new Error(`Unsupported option: ${key}`)
    }
    const value = argv[++index]
    if (!value || value.startsWith('--')) throw new Error(`Missing value for ${key}`)
    options[key.slice(2)] = value
  }
  assert.match(options.project ?? '', /^[a-z0-9][a-z0-9_-]{2,62}$/u)
  assert.match(options.port ?? '', /^\d{2,5}$/u)
  assert.ok(Number(options.port) >= 1024 && Number(options.port) <= 65535)
  assert.match(options.subnet ?? '', /^(?:\d{1,3}\.){3}\d{1,3}\/28$/u)
  assert.ok(options.subnet.split('/')[0].split('.').every(octet => Number(octet) >= 0 && Number(octet) <= 255))
  assert.ok(path.isAbsolute(options.override ?? ''), '--override must be an absolute external path')
  assert.ok(path.isAbsolute(options.docker ?? ''), '--docker must be an absolute executable path')
  assert.ok(path.isAbsolute(options.evidence ?? ''), '--evidence must be an absolute external path')
  return options
}

const options = optionsFromArgs(process.argv.slice(2))
const repoRoot = execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: process.cwd(), encoding: 'utf8' }).trim()
assert.equal(path.resolve(repoRoot), path.resolve(process.cwd()), 'Run from the PHP Git owner root before discovery.')
const scriptOwner = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
assert.equal(path.resolve(repoRoot), scriptOwner, 'The runner file must belong to the same PHP Git owner as the process CWD.')
const evidencePath = path.resolve(options.evidence)
function isExternalEvidencePath(ownerPath, candidatePath) {
  const relativePath = path.relative(path.resolve(ownerPath), path.resolve(candidatePath))
  const sameVolumeParent = relativePath === '..' || relativePath.startsWith(`..${path.sep}`)
  const otherLocalVolume = path.isAbsolute(relativePath) && !relativePath.startsWith('\\\\')
  return sameVolumeParent || otherLocalVolume
}
const evidenceOutsideOwner = path.resolve(repoRoot, '..', 'profile-summary-evidence-neighbor')
assert.equal(isExternalEvidencePath(repoRoot, repoRoot), false, 'The owner itself is not external evidence storage.')
assert.equal(isExternalEvidencePath(repoRoot, path.join(repoRoot, 'ordinary-child')), false, 'A normal child directory stays inside the source checkout.')
assert.equal(isExternalEvidencePath(repoRoot, path.join(repoRoot, '..evil')), false, 'A child name beginning with two dots is not a parent directory.')
assert.equal(isExternalEvidencePath(repoRoot, evidenceOutsideOwner), true, 'A same-volume sibling is valid external evidence storage.')
if (process.platform === 'win32') {
  assert.equal(isExternalEvidencePath(repoRoot, 'C:\\profile-summary-external-evidence'), true, 'An absolute path on another local drive is valid external evidence storage.')
  assert.equal(isExternalEvidencePath(repoRoot, '\\\\server\\share\\profile-summary-evidence'), false, 'UNC evidence storage is not enabled by this local test harness.')
}
assert.equal(isExternalEvidencePath(repoRoot, evidencePath), true, 'Evidence must stay outside the source checkout.')

const compose = ['compose', '-p', options.project, '-f', 'docker-compose.test.yml', '-f', options.override]
const runDocker = args => execFileSync(options.docker, args, {
  cwd: process.cwd(),
  stdio: 'pipe',
  encoding: 'utf8',
  env: {
    ...process.env,
    BLUEPLM_PROFILE_TEST_PORT: options.port,
    BLUEPLM_PROFILE_TEST_PROJECT: options.project,
    BLUEPLM_PROFILE_TEST_SUBNET: options.subnet,
  },
})
const server = `http://127.0.0.1:${options.port}`
const installationToken = 'integration-installation-token-must-be-at-least-32-chars'
const ownerPassword = 'Profile owner fixture password 123!'
const memberPassword = 'Profile member fixture password 456!'
const fixture = (...args) => JSON.parse(runDocker([...compose, 'exec', '-T', 'api', 'php', '/app/test/profile-summary-fixture.php', ...args]))

async function request(pathname, init = {}, token) {
  const response = await fetch(`${server}${pathname}`, {
    ...init,
    redirect: 'error',
    signal: AbortSignal.timeout(5000),
    headers: {
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })
  let body = null
  if (response.status !== 204) {
    const responseText = await response.text()
    try {
      body = JSON.parse(responseText)
    } catch (error) {
      const trimmed = responseText.trimStart()
      const first = trimmed[0] ?? ''
      const safeResponse = {
        status: response.status,
        contentType: response.headers.get('content-type'),
        bodyBytes: Buffer.byteLength(responseText),
        firstByteClass: first === '{' ? 'object-json' : first === '[' ? 'array-json' : first === '<' ? 'html' : first ? 'other-text' : 'empty',
        hasBom: responseText.charCodeAt(0) === 0xfeff,
        endsWithJsonDelimiter: /[}\]]\s*$/u.test(responseText),
      }
      const safeError = new Error('HTTP response JSON parse failed')
      safeError.name = error instanceof Error ? error.name : 'Error'
      safeError.safeResponse = {
        ...safeResponse,
        parseErrorClass: error instanceof Error ? error.name : 'unknown',
      }
      throw safeError
    }
  }
  return { status: response.status, body, contentType: response.headers.get('content-type') }
}

function contributionsPath(userId, values = {}) {
  const query = new URLSearchParams({ view: 'contributions', ...values })
  return `/users/${userId}/profile/summary?${query.toString()}`
}

let lastHealthObservation = null
async function waitForHealth() {
  const deadline = Date.now() + 30000
  while (Date.now() < deadline) {
    try {
      const result = await request('/health')
      lastHealthObservation = {
        status: result.status,
        okType: typeof result.body?.ok,
        ok: result.body?.ok === true,
        contentType: result.contentType,
      }
      if (result.status === 200 && result.body?.ok === true) return
    } catch (error) {
      lastHealthObservation = {
        errorClass: error instanceof Error ? error.name : 'unknown',
        response: error && typeof error === 'object' ? error.safeResponse ?? null : null,
      }
    }
    await new Promise(resolve => setTimeout(resolve, 250))
  }
  throw new Error(`The isolated API did not become healthy within 30 seconds; last observation: ${JSON.stringify(lastHealthObservation)}.`)
}

let projectStarted = false
let integrationResult = 'failed'
let failureClass = null
const redact = value => String(value)
  .replaceAll(installationToken, '[fixture-token-redacted]')
  .replaceAll(ownerPassword, '[fixture-password-redacted]')
  .replaceAll(memberPassword, '[fixture-password-redacted]')
  .replace(/Bearer\s+[A-Za-z0-9._-]+/giu, 'Bearer [redacted]')
  .replace(/(MARIADB_PASSWORD|BLUEPLM_[A-Z_]*TOKEN|BLUEPLM_SESSION_SECRET)=\S+/giu, '$1=[redacted]')
function saveFailureEvidence(error) {
  let composeLogs = ''
  let composeState = ''
  try { composeState = runDocker([...compose, 'ps', '--all', '--format', 'json']) } catch (captureError) { composeState = `Compose state capture failed: ${captureError instanceof Error ? captureError.name : 'unknown'}` }
  try { composeLogs = runDocker([...compose, 'logs', '--no-color', '--timestamps']) } catch (captureError) { composeLogs = `Compose log capture failed: ${captureError instanceof Error ? captureError.name : 'unknown'}` }
  let stderr = ''
  if (error && typeof error === 'object' && 'stderr' in error) stderr = String(error.stderr ?? '')
  mkdirSync(evidencePath, { recursive: true })
  writeFileSync(path.join(evidencePath, 'failure-diagnostics.txt'), redact([
    `project=${options.project}`,
    `port=${options.port}`,
    `failure=${error instanceof Error ? error.name : 'unknown'}`,
    `failure-detail=${error instanceof Error ? error.message : 'unknown error'}`,
    `stderr:\n${stderr}`,
    `compose-state:\n${composeState}`,
    `compose-logs:\n${composeLogs}`,
  ].join('\n\n')), 'utf8')
}
function assertProjectUnownedBeforeStart() {
  const containers = runDocker(['ps', '-a', '--filter', `label=com.docker.compose.project=${options.project}`, '--format', '{{.ID}}']).trim()
  const networks = runDocker(['network', 'ls', '--format', '{{.Name}}']).trim().split(/\r?\n/u).filter(Boolean)
  const exactNetwork = `${options.project}_net`
  const volumes = runDocker(['volume', 'ls', '--filter', `label=com.docker.compose.project=${options.project}`, '--format', '{{.Name}}']).trim()
  assert.equal(containers, '', 'Refusing to use a project name that already owns containers.')
  assert.equal(networks.includes(exactNetwork), false, 'Refusing to use a project network that already exists.')
  assert.equal(volumes, '', 'Refusing to use a project name that already owns volumes.')
}
try {
  const rendered = JSON.parse(runDocker([...compose, 'config', '--format', 'json']))
  const apiPorts = rendered.services?.api?.ports ?? []
  assert.equal(apiPorts.length, 1, 'Only one host port may be published by the isolated API service.')
  assert.equal(apiPorts[0].host_ip, '127.0.0.1')
  assert.equal(String(apiPorts[0].published), options.port)
  assert.equal(String(apiPorts[0].target), '80')
  assert.equal(rendered.services?.api?.build?.network, 'none')
  assert.equal(rendered.services?.api?.pull_policy, 'never')
  assert.equal(rendered.services?.mariadb?.pull_policy, 'never')
  assert.deepEqual(rendered.services?.mariadb?.command, ['--default-time-zone=+02:00'], 'The MariaDB fixture must run with a non-UTC database-local time basis.')
  assert.deepEqual(rendered.services?.api?.volumes ?? [], [])
  assert.deepEqual(rendered.services?.mariadb?.volumes ?? [], [])
  assert.equal(rendered.services?.mariadb?.tmpfs?.[0], '/var/lib/mysql:size=1g')
  assert.equal(rendered.services?.mariadb?.image, 'mariadb:11.4')
  assert.equal(rendered.networks?.default?.driver, 'bridge')
  assert.equal(rendered.networks?.default?.name, `${options.project}_net`)
  assert.equal(rendered.networks?.default?.internal ?? false, false)
  assert.deepEqual(rendered.networks?.default?.ipam?.config, [{ subnet: options.subnet }])
  console.log('isolated Compose config verified: loopback API, offline build/pull, tmpfs DB, owned bridge')
  if (options.configOnly) process.exit(0)
  assertProjectUnownedBeforeStart()
  projectStarted = true
  runDocker([...compose, 'up', '--build', '--pull', 'never', '-d', '--wait'])
  await waitForHealth()

  const installed = await request('/installer/commit', {
    method: 'POST',
    body: JSON.stringify({
      installationToken,
      action: 'install',
      bootstrap: {
        organizationName: 'Profile Summary Fixture',
        organizationSlug: `profile-summary-${randomUUID().slice(0, 8)}`,
        email: 'owner@profile-summary.test',
        displayName: 'Profile Owner',
        password: ownerPassword,
      },
    }),
  })
  assert.equal(installed.status, 200, 'The isolated installer bootstrap must succeed.')
  assert.equal(typeof installed.body?.token, 'string')
  const ownerToken = installed.body.token
  const me = await request('/auth/me', {}, ownerToken)
  assert.equal(me.status, 200)
  const userId = me.body.user.userId
  const organizationId = me.body.user.organizationId

  const memberEmail = `member-${randomUUID()}@profile-summary.test`
  const createdMember = await request('/users', {
    method: 'POST',
    body: JSON.stringify({ email: memberEmail, displayName: 'Profile Fixture Member', password: memberPassword, role: 'member' }),
  }, ownerToken)
  assert.equal(createdMember.status, 201, 'The owner must be able to create the isolated member fixture through the normal API.')
  const memberId = createdMember.body.id
  const memberLogin = await request('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email: memberEmail, password: memberPassword }),
  })
  assert.equal(memberLogin.status, 200)
  assert.equal(typeof memberLogin.body?.token, 'string')
  const memberToken = memberLogin.body.token

const guestEmail = `guest-${randomUUID()}@profile-summary.test`
const guestPassword = 'Profile guest fixture password 789!'
const createdGuest = await request('/users', {
  method: 'POST',
  body: JSON.stringify({ email: guestEmail, displayName: 'Profile Fixture Guest', password: guestPassword, role: 'guest' }),
}, ownerToken)
assert.equal(createdGuest.status, 201)
const guestId = createdGuest.body.id
const guestLogin = await request('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email: guestEmail, password: guestPassword }),
})
assert.equal(guestLogin.status, 200)
assert.equal(typeof guestLogin.body?.token, 'string')
const guestToken = guestLogin.body.token

const expected = fixture('seed', organizationId, userId, memberId, guestId)
  assert.equal(expected.ownerId, userId)
  assert.equal(expected.memberId, memberId)
assert.equal(expected.guestId, guestId)
  assert.equal(expected.databaseSessionTimeZone, '+02:00', 'The isolated MariaDB sessions must prove the non-UTC test basis.')
  assert.equal(expected.leapDayPriorYear, '2023-02-28 12:00:00.000000', 'The fixture must use MariaDB DATE_SUB month-end semantics for leap-day boundaries.')

  const summary = await request(`/users/${userId}/profile/summary`, {}, ownerToken)
const profileTeam = await request('/teams', {
  method: 'POST',
  body: JSON.stringify({ name: 'Profile Summary Team', color: '#2563eb', icon: 'Users' }),
}, ownerToken)
assert.equal(profileTeam.status, 201)
for (const teamUserId of [memberId, guestId]) {
  const added = await request(`/teams/${profileTeam.body.id}/members`, {
    method: 'POST',
    body: JSON.stringify({ userId: teamUserId }),
  }, ownerToken)
  assert.ok(added.status === 200 || added.status === 201 || added.status === 204)
}
const teamVaultAccess = await request(`/teams/${profileTeam.body.id}/vault-access`, {
  method: 'PUT',
  body: JSON.stringify({ vaultIds: [expected.visibleVaultId] }),
}, ownerToken)
assert.equal(teamVaultAccess.status, 200)
assert.equal((await request('/vaults', {}, guestToken)).body.vaults.some(vault => vault.id === expected.visibleVaultId), false, 'A guest must not inherit the team vault grant listed for ordinary members.')

  assert.equal(summary.status, 200, `Expected authenticated profile summary HTTP 200, got HTTP ${summary.status}${summary.body?.error ? ` (${summary.body.error})` : ''}.`)
  assert.deepEqual(Object.keys(summary.body).sort(), ['activity', 'deviceLastSeen', 'ownEcos', 'timeBasis', 'window'])
  assert.equal(summary.body.timeBasis, 'database-local-unverified')
  assert.deepEqual(Object.keys(summary.body.window).sort(), ['fromDatabaseTime', 'untilDatabaseTime'])
  const databaseDateTime = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/u
  assert.match(summary.body.window.fromDatabaseTime, databaseDateTime)
  assert.match(summary.body.window.untilDatabaseTime, databaseDateTime)
  assert.equal(summary.body.window.fromDatabaseTime.endsWith('Z'), false)
  assert.equal(summary.body.window.untilDatabaseTime.endsWith('Z'), false)
  assert.deepEqual(Object.keys(summary.body.activity).sort(), ['dailyCounts', 'recent', 'totalCount'])
  assert.equal(summary.body.activity.totalCount, expected.ownerExpectedCount, 'The full activity count must exceed the recent-feed limit and exclude out-of-window/unallowlisted/wrong-actor events.')
  assert.deepEqual(summary.body.activity.dailyCounts, expected.ownerDailyCounts, 'Database-local day buckets must account for all matching events without using the truncated recent list.')
  assert.equal(summary.body.activity.recent.length, 15, 'Recent activity is capped independently at fifteen entries.')
  for (const item of summary.body.activity.recent) {
    assert.deepEqual(Object.keys(item).sort(), ['createdAt', 'sequenceId', 'targetKind', 'targetLabel', 'type'])
    assert.match(item.sequenceId, /^(0|[1-9][0-9]*)$/u)
    assert.match(item.createdAt, databaseDateTime)
    assert.equal(/[zZ]|[+-]\d{2}:?\d{2}$/u.test(item.createdAt), false)
  }
  assert.equal(summary.body.activity.recent.some(item => item.type === 'review.rejected' && item.targetLabel === expected.visibleFileName), true, 'The rejected-review event resolves its label from payload.fileId for its actor.')
  assert.equal(summary.body.ownEcos.length, 10)
  assert.deepEqual(summary.body.ownEcos.map(eco => eco.ecoNumber), expected.ecoNumbers, 'The profile list is the ten most recent unique ECOs created by this user or linked through an accessible file.')
  assert.equal(new Set(summary.body.ownEcos.map(eco => eco.id)).size, 10, 'An ECO both created and linked by the user must appear only once.')
  assert.deepEqual(Object.keys(summary.body.ownEcos[0]).sort(), ['createdAt', 'ecoNumber', 'id', 'status', 'title'])
  for (const eco of summary.body.ownEcos) assert.match(eco.createdAt, databaseDateTime)
  assert.deepEqual(summary.body.deviceLastSeen, { status: 'recorded', at: expected.deviceLastSeen })
  assert.match(summary.body.deviceLastSeen.at, databaseDateTime)
  assert.equal(JSON.stringify(summary.body).includes('private-marker'), false, 'Storage-relative markers must not cross the profile DTO boundary.')

  const memberSummary = await request(`/users/${memberId}/profile/summary`, {}, memberToken)
  assert.equal(memberSummary.status, 200)
  assert.equal(memberSummary.body.activity.totalCount, expected.memberExpectedCount, 'A member sees only their own events on accessible files plus their own non-file activity.')
  assert.deepEqual(memberSummary.body.deviceLastSeen, { status: 'not-recorded' })
  assert.deepEqual(memberSummary.body.ownEcos.map(eco => eco.ecoNumber), expected.memberExpectedEcoNumbers, 'A member profile includes creator ECOs and target-linked ECOs on files they can access, in fixture timestamp order.')
  assert.equal(memberSummary.body.ownEcos.some(eco => eco.ecoNumber === expected.memberHiddenEcoNumber), false, 'A member must not learn ECO metadata through an inaccessible vault file.')
  assert.equal(memberSummary.body.ownEcos.some(eco => eco.ecoNumber === 'PROFILE-MEMBER-FOREIGN-LINKED'), false, 'A cross-organization file/ECO association must not cross the profile boundary.')
  assert.equal(memberSummary.body.activity.recent.some(item => item.targetLabel === expected.visibleFileName), true)
  assert.equal(memberSummary.body.activity.recent.some(item => item.targetLabel === expected.hiddenFileName), false, 'An inaccessible-vault filename must not be disclosed.')
  const memberRejected = memberSummary.body.activity.recent.filter(item => item.type === 'review.rejected')
  assert.deepEqual(memberRejected.map(item => item.targetLabel), [expected.visibleFileName], 'Members see rejected-review events only for accessible vaults.')
  assert.equal(JSON.stringify(memberSummary.body).includes('private-marker'), false)

const guestSummary = await request(`/users/${guestId}/profile/summary`, {}, guestToken)
assert.equal(guestSummary.status, 200)
assert.equal(guestSummary.body.activity.totalCount, '0', 'A guest cannot use team-only file access to reveal their own activity metadata.')
assert.equal(guestSummary.body.activity.recent.some(item => item.targetLabel === expected.visibleFileName), false)
assert.equal(guestSummary.body.ownEcos.some(eco => eco.ecoNumber === expected.guestLinkedEcoNumber), false, 'A guest cannot reveal linked ECO metadata through a team-only vault grant.')
const guestContributions = await request(contributionsPath(guestId, { day: expected.contributionDay, limit: '100' }), {}, guestToken)
assert.equal(guestContributions.status, 200)
assert.equal(guestContributions.body.counts.all, '0', 'Team-only file events and review requests are excluded from guest contribution counts.')
assert.equal(guestContributions.body.selectedDay.rows.some(row => row.fileName === expected.visibleFileName), false)

const guestDirectGrant = await request(`/users/${guestId}/vault-access`, {
  method: 'PUT',
  body: JSON.stringify({ vaultIds: [expected.visibleVaultId] }),
}, ownerToken)
assert.equal(guestDirectGrant.status, 200)
const guestDirectSummary = await request(`/users/${guestId}/profile/summary`, {}, guestToken)
assert.equal(guestDirectSummary.status, 200)
assert.equal(guestDirectSummary.body.activity.recent.some(item => item.targetLabel === expected.visibleFileName), true, 'An explicit guest vault grant preserves legitimate profile visibility.')
assert.equal(guestDirectSummary.body.ownEcos.some(eco => eco.ecoNumber === expected.guestLinkedEcoNumber), true)
const guestDirectContributions = await request(contributionsPath(guestId, { day: expected.contributionDay, limit: '100' }), {}, guestToken)
assert.equal(guestDirectContributions.status, 200)
assert.equal(guestDirectContributions.body.counts.all, '2', 'A direct guest grant allows the guest file event and review request rows.')
assert.deepEqual(guestDirectContributions.body.selectedDay.rows.map(row => row.fileName).sort(), [expected.visibleFileName, expected.visibleFileName])

  const ownerMemberSummary = await request(`/users/${memberId}/profile/summary`, {}, ownerToken)
  assert.equal(ownerMemberSummary.status, 200)
  assert.equal(ownerMemberSummary.body.activity.totalCount, expected.ownerViewMemberExpectedCount, 'An owner may inspect same-organization file and rejected-review events.')
  assert.deepEqual(ownerMemberSummary.body.ownEcos.map(eco => eco.ecoNumber), expected.ownerViewMemberExpectedEcoNumbers, 'An owner can see the target member’s creator ECOs and linked ECO metadata in both same-organization vaults.')
  assert.equal(ownerMemberSummary.body.ownEcos.some(eco => eco.ecoNumber === 'PROFILE-MEMBER-FOREIGN-LINKED'), false, 'Even an owner must not receive a linked ECO from a different organization.')
  assert.equal(ownerMemberSummary.body.activity.recent.some(item => item.targetLabel === expected.visibleFileName), true)
  assert.equal(ownerMemberSummary.body.activity.recent.some(item => item.targetLabel === expected.hiddenFileName), true, 'An owner may inspect both same-organization vaults.')
  const ownerViewRejected = ownerMemberSummary.body.activity.recent.filter(item => item.type === 'review.rejected').map(item => item.targetLabel).sort()
  assert.deepEqual(ownerViewRejected, [expected.hiddenFileName, expected.visibleFileName].sort(), 'An owner resolves both same-organization rejected-review file labels.')

  const ownerContributionsResponse = await request(contributionsPath(userId, { day: expected.contributionDay, limit: '10' }), {}, ownerToken)
  assert.equal(ownerContributionsResponse.status, 200)
  const ownerContributions = ownerContributionsResponse.body
  assert.deepEqual(Object.keys(ownerContributions).sort(), ['counts', 'dailyCounts', 'filter', 'recent', 'selectedDay', 'timeBasis', 'window'])
  assert.equal(ownerContributions.timeBasis, 'database-local-unverified')
  assert.equal(ownerContributions.filter, 'all')
  assert.deepEqual(ownerContributions.counts, { all: '27', checkins: '9', checkouts: '7', creates: '7', reviews: '3', approvals: '1' })
  assert.deepEqual(ownerContributions.dailyCounts, [{ day: expected.contributionDay, count: '27' }])
  assert.equal(ownerContributions.recent.length, 20, 'The exact contribution counts must not be inferred from a separately capped recent list.')
  assert.equal(ownerContributions.selectedDay.count, '27')
  assert.equal(ownerContributions.selectedDay.rows.length, 10)
  assert.equal(ownerContributions.selectedDay.hasMore, true)
  assert.equal(typeof ownerContributions.selectedDay.nextCursor, 'string')
  assert.equal(JSON.stringify(ownerContributions).includes('private-marker'), false)
  assert.equal(JSON.stringify(ownerContributions).includes('@profile-summary.test'), false)
  const cursorPayload = JSON.parse(Buffer.from(ownerContributions.selectedDay.nextCursor, 'base64url').toString('utf8'))
  const malformedDateCursor = Buffer.from(JSON.stringify({ ...cursorPayload, createdAt: '2026-02-30 12:00:00.000000' })).toString('base64url')
  const malformedCursorDate = await request(contributionsPath(userId, { day: expected.contributionDay, limit: '10', cursor: malformedDateCursor }), {}, ownerToken)
  assert.equal(malformedCursorDate.status, 400, 'Malformed calendar dates in a re-encoded cursor are invalid requests, not server errors.')

  const contributionIds = new Set(ownerContributions.selectedDay.rows.map(row => row.id))
  const contributionActions = new Set(ownerContributions.selectedDay.rows.map(row => row.action))
  let nextCursor = ownerContributions.selectedDay.nextCursor
  let cursorPageCount = 1
  while (nextCursor !== null) {
    const page = await request(contributionsPath(userId, { day: expected.contributionDay, limit: '10', cursor: nextCursor }), {}, ownerToken)
    assert.equal(page.status, 200)
    cursorPageCount++
    for (const row of page.body.selectedDay.rows) {
      assert.equal(contributionIds.has(row.id), false, 'Stable (database time, source kind, row id) cursors must not repeat equal-time rows.')
      contributionIds.add(row.id)
      contributionActions.add(row.action)
    }
    nextCursor = page.body.selectedDay.nextCursor
    assert.equal(page.body.selectedDay.hasMore, nextCursor !== null)
  }
  assert.equal(cursorPageCount, 3)
  assert.equal(contributionIds.size, 27)
  assert.equal(contributionActions.has('checkin'), true, 'The union retains file-event rows alongside stored review rows.')
  assert.equal(contributionActions.has('review_requested'), true)
  assert.equal(contributionActions.has('review_approved'), true)

  const expectedFilterCounts = {
    all: '27', checkins: '9', checkouts: '7', creates: '7', reviews: '3', approvals: '1',
  }
  for (const [filter, expectedCount] of Object.entries(expectedFilterCounts)) {
    const filtered = await request(contributionsPath(userId, { filter, day: expected.contributionDay, limit: '100' }), {}, ownerToken)
    assert.equal(filtered.status, 200)
    assert.equal(filtered.body.counts[filter], expectedCount)
    assert.equal(filtered.body.selectedDay.count, expectedCount)
    assert.equal(filtered.body.selectedDay.rows.length, Number(expectedCount))
    if (filter === 'approvals') assert.deepEqual(filtered.body.selectedDay.rows.map(row => row.action), ['review_approved'])
    if (filter === 'reviews') assert.deepEqual(filtered.body.selectedDay.rows.map(row => row.action).sort(), ['review_approved', 'review_rejected', 'review_requested'])
    if (filter === 'checkins') assert.ok(filtered.body.selectedDay.rows.every(row => row.action === 'checkin'))
    if (filter === 'checkouts') assert.ok(filtered.body.selectedDay.rows.every(row => row.action === 'checkout'))
    if (filter === 'creates') assert.ok(filtered.body.selectedDay.rows.every(row => row.action === 'create'))
  }

  const memberContributionsResponse = await request(contributionsPath(memberId, { day: expected.contributionDay, limit: '100' }), {}, memberToken)
  assert.equal(memberContributionsResponse.status, 200)
  assert.deepEqual(memberContributionsResponse.body.counts, { all: '7', checkins: '2', checkouts: '1', creates: '1', reviews: '3', approvals: '0' })
  assert.equal(memberContributionsResponse.body.selectedDay.count, '7')
  const memberReviewRows = memberContributionsResponse.body.selectedDay.rows.filter(row => row.action.startsWith('review_'))
  assert.deepEqual(memberReviewRows.map(row => row.action).sort(), ['review_kicked_back', 'review_requested', 'review_requested'], 'A reviewer kickback remains distinct and one request is shown for the round rather than each assignee.')
  assert.equal(memberContributionsResponse.body.selectedDay.rows.some(row => row.fileName === expected.hiddenFileName), false, 'An inaccessible vault must be excluded from both counts and selected-day rows.')
  const ownerViewingMemberContributions = await request(contributionsPath(memberId, { day: expected.contributionDay, limit: '100' }), {}, ownerToken)
  assert.equal(ownerViewingMemberContributions.status, 200)
  assert.deepEqual(ownerViewingMemberContributions.body.counts, { all: '11', checkins: '3', checkouts: '2', creates: '1', reviews: '5', approvals: '1' })
  assert.equal(ownerViewingMemberContributions.body.selectedDay.rows.some(row => row.fileName === expected.hiddenFileName), true, 'An owner may see matching contribution rows from both same-organization vaults.')
  assert.equal(ownerViewingMemberContributions.body.selectedDay.rows.filter(row => row.action === 'review_requested').length, 3, 'One request is counted per review round rather than once per assigned reviewer.')

  const noDay = await request(contributionsPath(userId), {}, ownerToken)
  assert.equal(noDay.status, 200)
  assert.equal(noDay.body.selectedDay, null)
  for (const invalidPath of [
    contributionsPath(userId, { filter: 'everything' }),
    contributionsPath(userId, { day: '2026-02-30' }),
    contributionsPath(userId, { limit: '101' }),
    contributionsPath(userId, { limit: '10' }),
    contributionsPath(userId, { day: expected.contributionDay, cursor: 'not-a-cursor' }),
    contributionsPath(userId, { cursor: 'not-a-cursor' }),
    `/users/${userId}/profile/summary?view=other`,
  ]) {
    const invalid = await request(invalidPath, {}, ownerToken)
    assert.equal(invalid.status, 400)
  }
  const forbiddenTarget = await request(`/users/${userId}/profile/summary`, {}, memberToken)
  assert.equal(forbiddenTarget.status, 403, 'A member cannot inspect another user profile.')
  const forbiddenContributionTarget = await request(contributionsPath(userId), {}, memberToken)
  assert.equal(forbiddenContributionTarget.status, 403, 'A member cannot inspect another user contribution view.')
  const foreignTarget = await request(`/users/${expected.foreignUserId}/profile/summary`, {}, ownerToken)
  assert.equal(foreignTarget.status, 404, 'A different-organization user is not distinguishable from a missing profile.')
  const foreignContributionTarget = await request(contributionsPath(expected.foreignUserId), {}, ownerToken)
  assert.equal(foreignContributionTarget.status, 404)
  const unauthenticated = await request(`/users/${userId}/profile/summary`)
  assert.equal(unauthenticated.status, 401)

  integrationResult = 'passed'
  console.log('profile summary integration tracer: authorized endpoint contract available')
} catch (error) {
  failureClass = error instanceof Error ? error.name : 'unknown'
  if (projectStarted) saveFailureEvidence(error)
  console.error(`profile summary integration tracer failed (${failureClass}); redacted diagnostics saved externally.`)
  process.exitCode = 1
} finally {
  mkdirSync(evidencePath, { recursive: true })
  writeFileSync(path.join(evidencePath, 'run-result.json'), JSON.stringify({
    project: options.project,
    port: options.port,
    result: integrationResult,
    failureClass,
    lastHealthObservation,
    isolatedComposeVerified: true,
  }, null, 2) + '\n', 'utf8')
  if (projectStarted) {
    try {
      runDocker([...compose, 'down'])
    } catch {
      console.error('Exact owned Compose project cleanup reported an error; inspect its saved run evidence.')
      process.exitCode = 1
    }
  }
}
