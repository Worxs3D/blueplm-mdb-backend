// Focused workflow-review integration matrix. It owns its Compose project and
// verifies review decisions through both the HTTP contract and MariaDB state.
import assert from 'node:assert/strict'
import { execFileSync, spawn, spawnSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { mkdirSync, realpathSync, writeFileSync } from 'node:fs'
import { isAbsolute, join, relative, resolve, sep } from 'node:path'
import net from 'node:net'
import { fileURLToPath } from 'node:url'

async function requireHealthyApi(probe, pause, attempts = 30) {
  let lastStatus = 'no response'
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      const response = await probe()
      if (response?.ok === true) return
      lastStatus = `HTTP ${Number.isInteger(response?.status) ? response.status : 'invalid status'}`
    } catch (error) {
      lastStatus = error?.code || error?.name || 'probe failed'
    }
    if (attempt + 1 < attempts) await pause()
  }
  throw new Error(`API health check did not pass after ${attempts} attempts (${lastStatus}).`)
}

function validateRenderedConfig(config) {
  const services = config?.services || {}
  assert.deepEqual(Object.keys(services).sort(), ['api', 'mariadb'], 'rendered Compose must contain only api and mariadb')
  const api = services.api
  const database = services.mariadb
  assert.equal(api?.build?.network, 'none', 'API image build must not use the network')
  assert.equal(api?.pull_policy, 'never', 'API image must not be pulled')
  assert.equal(database?.pull_policy, 'never', 'MariaDB image must not be pulled')
  const apiPorts = Array.isArray(api?.ports) ? api.ports.map(({ target, published, host_ip, protocol }) => ({ target, published, host_ip, protocol })) : api?.ports
  assert.deepEqual(apiPorts, [{ target: 80, published: '18841', host_ip: '127.0.0.1', protocol: 'tcp' }], 'API must publish only the approved loopback port')
  assert.equal(database?.ports, undefined, 'MariaDB must not publish a host port')
  assert.deepEqual(database?.tmpfs, ['/var/lib/mysql:size=1073741824'], 'MariaDB data must use the approved disposable tmpfs')
  assert.equal(api?.volumes, undefined, 'API must not have host bind mounts')
  assert.equal(database?.volumes, undefined, 'MariaDB must not have host bind mounts')
  const networks = config?.networks || {}
  const defaultNetwork = networks.default
  assert.notEqual(defaultNetwork?.internal, true, 'the authorized retry must use a normal bridge network')
  assert.deepEqual(defaultNetwork?.ipam?.config, [{ subnet: '198.18.0.0/28' }], 'the test network must retain its approved isolated IPAM')
  return { services: ['api', 'mariadb'], apiLoopbackPort: '127.0.0.1:18841', mariadbHostPort: false, networkInternal: false }
}

function hasExpectedPortMapping(container, expectedPort) {
  const matches = values => Array.isArray(values) && values.some(value => value?.hostIp === '127.0.0.1' && String(value?.hostPort) === String(expectedPort))
  return matches(container?.hostPortBindings) && matches(container?.networkPorts)
}

function normalizeGroupedStatusCounts(rows) {
  return rows.map(row => String(row[0]).split(',').sort().join(',')).sort()
}

const ownerRoot = realpathSync(fileURLToPath(new URL('..', import.meta.url)))
const processRoot = realpathSync(process.cwd())
const gitRoot = realpathSync(execFileSync('git', ['rev-parse', '--show-toplevel'], { cwd: process.cwd(), encoding: 'utf8' }).trim())
if (processRoot !== ownerRoot || gitRoot !== ownerRoot) throw new Error('Workflow integration runner CWD must equal its Git owner root before Docker setup.')
const project = 'blueplm-review-rounds-20261017-r1'
const port = 18841
function resolveExternalPath(value, name) {
  if (typeof value !== 'string' || !isAbsolute(value)) throw new Error(`${name} must be an absolute path outside the repository.`)
  const resolved = resolve(value)
  const relativePath = relative(ownerRoot, resolved)
  if (relativePath === '' || (relativePath !== '..' && !relativePath.startsWith(`..${sep}`) && !isAbsolute(relativePath))) {
    throw new Error(`${name} must resolve outside the repository.`)
  }
  return resolved
}
if (process.argv[2] === '--harness-self-test') {
  let installerPosts = 0
  await assert.rejects(async () => {
    await requireHealthyApi(async () => ({ ok: false, status: 503 }), async () => {}, 3)
    installerPosts++
  }, /API health check did not pass after 3 attempts \(HTTP 503\)/)
  assert.equal(installerPosts, 0, 'installer POST must not run unless health check succeeds')
  let probes = 0
  await requireHealthyApi(async () => ({ ok: ++probes === 2, status: probes === 1 ? 503 : 200 }), async () => {}, 3)
  assert.equal(probes, 2)
  const redacted = redactDiagnostic('{"installationToken":"token-canary","accessToken":"access-canary","apiKey":"key-canary","password":"password-canary"}')
  for (const canary of ['token-canary', 'access-canary', 'key-canary', 'password-canary']) assert.equal(redacted.includes(canary), false)
  assert.match(redacted.join('\n'), /\[redacted\]/)
  assert.throws(() => resolveExternalPath(ownerRoot, 'BLUEPLM_TEST_EVIDENCE_ROOT'), /outside the repository/)
  assert.equal(resolveExternalPath(resolve(ownerRoot, '..', 'workflow-test-evidence'), 'BLUEPLM_TEST_EVIDENCE_ROOT'), resolve(ownerRoot, '..', 'workflow-test-evidence'))
  const inspection = summarizeOwnContainer({
    Id: '1234567890abcdef',
    Config: { Labels: { 'com.docker.compose.project': project, 'com.docker.compose.service': 'api' }, Env: ['TOKEN=secret-canary'] },
    State: { Status: 'running', Running: true, ExitCode: 0, Health: { Status: 'healthy' } },
    HostConfig: { PortBindings: { '80/tcp': [{ HostIp: '127.0.0.1', HostPort: String(port) }] } },
    NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '127.0.0.1', HostPort: String(port) }] } },
  }, 'api')
  assert.deepEqual(inspection.state, { status: 'running', running: true, exitCode: 0, health: 'healthy' })
  assert.deepEqual(inspection.hostPortBindings, [{ hostIp: '127.0.0.1', hostPort: String(port) }])
  assert.equal(JSON.stringify(inspection).includes('secret-canary'), false)
  assert.equal(summarizeOwnContainer({ Config: { Labels: { 'com.docker.compose.project': 'foreign' } } }, 'api'), null)
  assert.equal(hasExpectedPortMapping({ hostPortBindings: inspection.hostPortBindings, networkPorts: inspection.networkPorts }, port), true)
  assert.equal(hasExpectedPortMapping({ hostPortBindings: inspection.hostPortBindings, networkPorts: [] }, port), false)
  const safeComposeConfig = { services: {
    api: { build: { network: 'none' }, pull_policy: 'never', ports: [{ target: 80, published: '18841', host_ip: '127.0.0.1', protocol: 'tcp' }] },
    mariadb: { pull_policy: 'never', tmpfs: ['/var/lib/mysql:size=1073741824'] },
  }, networks: { default: { internal: false, ipam: { config: [{ subnet: '198.18.0.0/28' }] } } } }
  assert.deepEqual(validateRenderedConfig(safeComposeConfig), { services: ['api', 'mariadb'], apiLoopbackPort: '127.0.0.1:18841', mariadbHostPort: false, networkInternal: false })
  assert.throws(() => validateRenderedConfig({ ...safeComposeConfig, networks: { default: { ...safeComposeConfig.networks.default, internal: true } } }), /normal bridge network/)
  assert.throws(() => validateRenderedConfig({ ...safeComposeConfig, services: { ...safeComposeConfig.services, mariadb: { ...safeComposeConfig.services.mariadb, ports: ['3306:3306'] } } }), /must not publish a host port/)
  assert.deepEqual(normalizeGroupedStatusCounts([['rejected,cancelled'], ['approved,approved']]), ['approved,approved', 'cancelled,rejected'])
  assert.deepEqual(normalizeGroupedStatusCounts([['cancelled,rejected'], ['approved,approved']]), ['approved,approved', 'cancelled,rejected'])
  console.log('workflow harness readiness/redaction/path/inspect self-test: PASS')
  process.exit(0)
}
const docker = process.env.BLUEPLM_DOCKER_BIN || 'docker'
const override = resolveExternalPath(process.env.BLUEPLM_TEST_COMPOSE_OVERRIDE, 'BLUEPLM_TEST_COMPOSE_OVERRIDE')
const compose = ['compose', '-p', project, '-f', 'docker-compose.test.yml', '-f', override]
const evidenceRoot = resolveExternalPath(process.env.BLUEPLM_TEST_EVIDENCE_ROOT, 'BLUEPLM_TEST_EVIDENCE_ROOT')
let renderedComposeSummary = null
let prePostPortDiagnostics = null
const portProbe = await new Promise((resolve, reject) => {
  const probe = net.createServer()
  probe.once('error', reject)
  probe.listen(port, '127.0.0.1', () => {
    probe.close(error => error ? reject(error) : resolve(true))
  })
})
if (!portProbe) throw new Error('Approved workflow test port could not be reserved.')
const server = `http://127.0.0.1:${port}`
const installToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Workflow owner password 123!'
const composeRun = args => execFileSync(docker, [...compose, ...args], {
  cwd: process.cwd(),
  encoding: 'utf8',
  stdio: 'pipe',
  env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
})
function redactDiagnostic(text) {
  return String(text || '').split(/\r?\n/).filter(Boolean).map(line => line
    .replace(/((?:["']?[\w-]*(?:token|password|secret|credential|authorization|api[_-]?key)[\w-]*["']?)\s*[:=]\s*)(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\s,;}\]]+)/gi, '$1[redacted]')
    .replace(/\b(Bearer\s+)\S+/gi, '$1[redacted]')
    .replace(/\b(token|password|secret|credential|authorization|api[_-]?key)\s*[:=]\s*[^\s,;]+/gi, '$1=[redacted]')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[email-redacted]')
    .replace(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g, '[ip-redacted]')
    .slice(0, 500)).slice(-100)
}
function summarizeOwnContainer(container, expectedService) {
  const labels = container?.Config?.Labels || {}
  if (labels['com.docker.compose.project'] !== project || labels['com.docker.compose.service'] !== expectedService) return null
  const safeBindings = bindings => {
    const values = bindings?.['80/tcp']
    return Array.isArray(values) ? values.map(value => ({ hostIp: value.HostIp || null, hostPort: value.HostPort || null })) : null
  }
  return {
    id: typeof container.Id === 'string' ? container.Id.slice(0, 12) : null,
    service: expectedService,
    state: {
      status: container.State?.Status || null,
      running: container.State?.Running === true,
      exitCode: Number.isInteger(container.State?.ExitCode) ? container.State.ExitCode : null,
      health: container.State?.Health?.Status || null,
    },
    hostPortBindings: safeBindings(container.HostConfig?.PortBindings),
    networkPorts: safeBindings(container.NetworkSettings?.Ports),
  }
}
async function collectStartupDiagnostics() {
  const list = spawnSync(docker, [...compose, 'ps', '--all', '--quiet', 'api', 'mariadb'], {
    cwd: process.cwd(), encoding: 'utf8', stdio: 'pipe', maxBuffer: 256 * 1024,
    env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
  })
  const containers = []
  if (list.status === 0) {
    for (const id of String(list.stdout || '').trim().split(/\r?\n/).filter(Boolean)) {
      const inspected = spawnSync(docker, ['inspect', id], { cwd: process.cwd(), encoding: 'utf8', stdio: 'pipe', maxBuffer: 256 * 1024 })
      if (inspected.status !== 0) {
        containers.push({ inspectExitCode: inspected.status, errorCode: inspected.error?.code || null })
        continue
      }
      try {
        const item = JSON.parse(inspected.stdout)[0]
        const service = item?.Config?.Labels?.['com.docker.compose.service']
        const summary = summarizeOwnContainer(item, service)
        if (summary) containers.push(summary)
      } catch {
        containers.push({ inspectExitCode: inspected.status, parseError: true })
      }
    }
  }
  const apiContainer = containers.some(item => item.service === 'api' && item.state?.running)
  let internalHealth = { attempted: false }
  if (apiContainer) {
    const phpProbe = '$context=stream_context_create(["http"=>["ignore_errors"=>true,"timeout"=>2]]); $body=@file_get_contents("http://127.0.0.1/health",false,$context); $status=0; foreach(($http_response_header??[]) as $line) { if(preg_match("/^HTTP\\/\\S+\\s+(\\d+)/",$line,$m)) $status=(int)$m[1]; } $data=is_string($body)?json_decode($body,true):null; echo json_encode(["status"=>$status,"jsonValid"=>is_array($data),"ok"=>is_array($data)&&(($data["ok"]??null)===true)]),PHP_EOL;'
    const probe = spawnSync(docker, [...compose, 'exec', '-T', 'api', 'php', '-r', phpProbe], {
      cwd: process.cwd(), encoding: 'utf8', stdio: 'pipe', maxBuffer: 256 * 1024,
      env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
    })
    try {
      internalHealth = { attempted: true, exitCode: probe.status, errorCode: probe.error?.code || null, result: JSON.parse(probe.stdout.trim()) }
    } catch {
      internalHealth = { attempted: true, exitCode: probe.status, errorCode: probe.error?.code || null, invalidDiagnostic: true }
    }
  }
  let hostHealth
  try { hostHealth = await probeHealth() } catch (error) { hostHealth = { ok: false, errorCode: error?.code || error?.name || 'probe failed' } }
  return {
    projectContainerList: { exitCode: list.status, errorCode: list.error?.code || null },
    containers,
    internalHealth,
    hostHealth,
  }
}
async function saveStartupFailureEvidence(start, healthError) {
  const logs = spawnSync(docker, [...compose, 'logs', '--no-color', 'api', 'mariadb'], {
    cwd: process.cwd(), encoding: 'utf8', stdio: 'pipe',
    maxBuffer: 1024 * 1024,
    env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
  })
  const runtimeDiagnostics = await collectStartupDiagnostics()
  const evidence = {
    capturedAt: new Date().toISOString(),
    composeStart: {
      exitCode: start.status,
      signal: start.signal,
      errorCode: start.error?.code || null,
      stdout: redactDiagnostic(start.stdout),
      stderr: redactDiagnostic(start.stderr),
    },
    healthFailure: redactDiagnostic(healthError?.message),
    renderedCompose: renderedComposeSummary,
    prePostPortGate: prePostPortDiagnostics,
    containerLogs: {
      exitCode: logs.status,
      errorCode: logs.error?.code || null,
      stdout: redactDiagnostic(logs.stdout),
      stderr: redactDiagnostic(logs.stderr),
    },
    runtimeDiagnostics,
  }
  mkdirSync(evidenceRoot, { recursive: true })
  const evidencePath = join(evidenceRoot, `startup-failure-${Date.now()}-${randomUUID()}.json`)
  writeFileSync(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`, { flag: 'wx' })
  return evidencePath
}
const run = (args, options = {}) => execFileSync(docker, args, {
  cwd: process.cwd(),
  stdio: 'pipe',
  env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
  ...options,
})
const projectContainers = run(['ps', '-a', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.ID}}'], { encoding: 'utf8' }).trim()
const projectVolumes = run(['volume', 'ls', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.Name}}'], { encoding: 'utf8' }).trim()
const projectNetworks = run(['network', 'ls', '--filter', `label=com.docker.compose.project=${project}`, '--format', '{{.Name}}'], { encoding: 'utf8' }).trim()
if (projectContainers || projectVolumes || projectNetworks) throw new Error('Approved workflow test project already has Docker resources; refusing to reuse them.')
composeRun(['config', '--quiet'])
const services = composeRun(['config', '--services']).trim().split(/\r?\n/).sort()
if (services.join(',') !== 'api,mariadb') throw new Error('Workflow test Compose project must contain only api and mariadb services.')
try {
  renderedComposeSummary = validateRenderedConfig(JSON.parse(composeRun(['config', '--format', 'json'])))
} catch {
  throw new Error('Rendered workflow test Compose configuration did not satisfy the approved isolated runtime policy.')
}
const sql = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
const rows = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement]).toString().trim().split(/\r?\n/).filter(Boolean).map(line => line.split('\t'))
async function call(path, init = {}, token, expected = 200) {
  const response = await fetch(`${server}${path}`, { ...init, headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) } })
  const body = await response.json()
  assert.equal(response.status, expected, `${path}: ${typeof body?.error === 'string' ? body.error : 'unexpected response'}`)
  return body
}
async function probeHealth() {
  const response = await fetch(`${server}/health`, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(1500) })
  let body
  try { body = await response.json() } catch { return { ok: false, status: response.status, category: 'invalid-json' } }
  return { ok: response.ok && body?.ok === true, status: response.status }
}
const decision = (id, token, value = 'approved', expected = 200) => call(`/workflow-reviews/${id}/decision`, { method: 'POST', body: JSON.stringify({ decision: value, comment: `${value} matrix` }) }, token, expected)
const mineFor = async (token, file) => (await call('/workflow-reviews/mine', {}, token)).reviews.filter(review => review.file_id === file)
async function holdAssignmentLock(item) {
  const child = spawn(docker, [...compose, 'exec', '-T', 'mariadb', 'mariadb', '--batch', '--skip-column-names', '--unbuffered', '-ublueplm', '-pblueplm-test-password', 'blueplm'], {
    cwd: process.cwd(),
    env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
    stdio: ['pipe', 'pipe', 'pipe'],
  })
  let stdout = ''
  let stderr = ''
  child.stdout.setEncoding('utf8').on('data', chunk => { stdout += chunk })
  child.stderr.setEncoding('utf8').on('data', chunk => { stderr += chunk })
  const marker = new Promise((resolve, reject) => {
    const deadline = setTimeout(() => {
      child.stdin.end('ROLLBACK;\n')
      const killTimer = setTimeout(() => child.kill(), 1000)
      child.once('close', () => clearTimeout(killTimer))
      reject(new Error('Timed out acquiring the test assignment lock.'))
    }, 10000)
    child.on('error', error => { clearTimeout(deadline); reject(new Error(`Lock holder process error (${error?.code || error?.name || 'unknown'}).`)) })
    child.on('close', code => {
      if (!stdout.includes('ASSIGNMENT_LOCK_HELD')) {
        clearTimeout(deadline)
        reject(new Error(`Lock holder exited before acquiring lock (${code ?? 'unknown'}; ${stderr.includes('ERROR') ? 'client error' : 'no marker'}).`))
      }
    })
    child.stdout.on('data', () => {
      if (stdout.includes('ASSIGNMENT_LOCK_HELD')) { clearTimeout(deadline); resolve() }
    })
  })
  child.stdin.write(`START TRANSACTION;\nSELECT id FROM file_workflow_assignments WHERE file_id='${item.file}' FOR UPDATE;\nSELECT 'ASSIGNMENT_LOCK_HELD';\n`)
  await marker
  return async () => {
    child.stdin.end('COMMIT;\n')
    await new Promise((resolve, reject) => {
      const deadline = setTimeout(() => {
        child.kill()
        reject(new Error('Timed out releasing the test assignment lock.'))
      }, 5000)
      child.once('error', reject)
      child.once('close', code => {
        clearTimeout(deadline)
        code === 0 ? resolve() : reject(new Error(`Lock holder exited with ${code ?? 'unknown status'}.`))
      })
    })
  }
}
async function waitForMariaDbLockWait() {
  for (let attempt = 0; attempt < 100; attempt++) {
    const waitCount = run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-uroot', '-proot-test-password', 'blueplm', '-e', 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS']).toString().trim()
    if (Number(waitCount) > 0) return
    await new Promise(resolve => setTimeout(resolve, 50))
  }
  assert.fail('The decision request never entered the expected MariaDB row-lock wait.')
}

try {
  // Explicit project/port/image context makes this safe alongside other suites.
  const startResult = spawnSync(docker, [...compose, 'up', '--build', '--pull', 'never', '-d', '--wait'], {
    cwd: process.cwd(), encoding: 'utf8', stdio: 'pipe',
    env: { ...process.env, BLUEPLM_PHP_TEST_PORT: `127.0.0.1:${port}` },
  })
  try {
    if (startResult.error || startResult.status !== 0) throw new Error(`Compose startup failed (${startResult.error?.code || startResult.status}).`)
    await requireHealthyApi(probeHealth, () => new Promise(resolve => setTimeout(resolve, 250)))
  } catch (startupError) {
    const evidencePath = await saveStartupFailureEvidence(startResult, startupError)
    throw new Error(`${startupError.message} Redacted startup diagnostics: ${evidencePath}`)
  }
  const installed = await call('/installer/commit', { method: 'POST', body: JSON.stringify({ installationToken: installToken, action: 'install', bootstrap: { organizationName: 'Workflow Org', organizationSlug: 'workflow-org', email: 'owner@workflow.test', displayName: 'Owner', password } }) })
  const owner = installed.token
  const ownerMe = await call('/auth/me', {}, owner)
  const org = ownerMe.user.organizationId
  const user = ownerMe.user.userId
  const vault = randomUUID()
  sql(`INSERT INTO vaults(id,organization_id,name,network_root) VALUES('${vault}','${org}','Workflow Vault','/tmp')`)

  const users = {}
  function createUser(name, role = 'admin') {
    const id = randomUUID(), token = `matrix-${randomUUID()}`
    const hash = createHash('sha256').update(`test-session-secret-must-be-at-least-thirty-two-characters:${token}`).digest('hex')
    sql(`INSERT INTO users(id,email,display_name,password_hash) VALUES('${id}','${name}@workflow.test','${name}','fixture'); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${org}','${id}','${role}'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${hash}','${id}','${org}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)
    users[name] = { id, token }
    return users[name]
  }
  function reviewerValue(rule) {
    if (rule.type === 'user') return `'${randomUUID()}','${rule.gate}','user','${rule.user.id}',NULL,NULL,NULL`
    if (rule.type === 'role') return `'${randomUUID()}','${rule.gate}','role',NULL,'${rule.role}',NULL,NULL`
    if (rule.type === 'team') return `'${randomUUID()}','${rule.gate}','group',NULL,NULL,'${rule.team}',NULL`
    return `'${randomUUID()}','${rule.gate}','workflow_role',NULL,NULL,NULL,'${rule.workflowRole}'`
  }
  function flow({ rules = [], mode = 'any', required = 1, gate = true, vaultId = vault, allowedRoles = null, revision = 7 }) {
    const [file, workflow, from, to, transition, gateId] = Array.from({ length: 6 }, randomUUID)
    const allowed = allowedRoles ? `'${JSON.stringify(allowedRoles)}'` : 'NULL'
    sql(`INSERT INTO files(id,organization_id,vault_id,canonical_path,file_name,storage_relative_path,current_revision) VALUES('${file}','${org}','${vaultId}','${file}.txt','${file}.txt','${file}.txt',${revision}); INSERT INTO workflow_templates(id,organization_id,name,created_by) VALUES('${workflow}','${org}','${workflow}','${user}'); INSERT INTO workflow_states(id,workflow_id,name,label) VALUES('${from}','${workflow}','Draft','Draft'),('${to}','${workflow}','Review','Review'); UPDATE workflow_states SET auto_increment_revision=TRUE WHERE id='${to}'; INSERT INTO workflow_transitions(id,workflow_id,from_state_id,to_state_id,name,waypoints,allowed_workflow_roles) VALUES('${transition}','${workflow}','${from}','${to}','Review','[]',${allowed}); INSERT INTO file_workflow_assignments(id,file_id,workflow_id,current_state_id,assigned_by) VALUES('${randomUUID()}','${file}','${workflow}','${from}','${user}')${gate ? `; INSERT INTO workflow_gates(id,transition_id,name,approval_mode,required_approvals,is_blocking) VALUES('${gateId}','${transition}','Gate','${mode}',${required},TRUE)` : ''}`)
    if (gate) for (const rule of rules) { rule.gate = gateId; sql(`INSERT INTO workflow_gate_reviewers(id,gate_id,reviewer_type,user_id,role,group_name,workflow_role_id) VALUES(${reviewerValue(rule)})`) }
    return { file, workflow, from, to, transition, gateId, revision }
  }
  const start = item => call(`/files/${item.file}/workflow-transitions/${item.transition}/execute`, { method: 'POST', body: '{}' }, owner)
  const state = item => rows(`SELECT current_state_id,current_revision FROM file_workflow_assignments a JOIN files f ON f.id=a.file_id WHERE a.file_id='${item.file}'`)[0]
  const statuses = item => rows(`SELECT status,COUNT(*) FROM pending_reviews WHERE file_id='${item.file}' GROUP BY status ORDER BY status`).map(row => row.join(':')).join(',')

  // Direct review remains immediately solvable when it is raised before other admins exist.
  const direct = flow({ gate: false })
  sql(`UPDATE workflow_states SET triggers_review=TRUE WHERE id='${direct.to}'`)
  assert.equal((await start(direct)).result.requires_review, true)
  const directReview = (await mineFor(owner, direct.file))[0]
  assert.equal(directReview.gate_id, null)
  for (const internalColumn of ['review_round_id', 'is_legacy_round', 'legacy_round_ambiguous']) {
    assert.equal(Object.hasOwn(directReview, internalColumn), false, `review DTO must not expose ${internalColumn}`)
  }
  assert.equal((await decision(directReview.review_id, owner)).result.new_state_id, direct.to)
  assert.deepEqual(state(direct), [direct.to, '8'])

  const explicit = createUser('explicit')
  const roleReviewer = createUser('role-reviewer')
  const teamReviewer = createUser('team-reviewer')
  const workflowReviewer = createUser('workflow-reviewer')
  // Reuse selector fixtures for quorum decisions: this keeps the isolated
  // integration process short while exercising three independent assignees.
  const quorumOne = explicit
  const quorumTwo = roleReviewer
  const quorumThree = teamReviewer
  const member = createUser('normal-member', 'member')
  sql(`INSERT INTO vault_access(vault_id,user_id,granted_by) VALUES('${vault}','${member.id}','${user}')`)

  // A: each persisted reviewer selector resolves to exactly its intended user.
  const team = await call('/teams', { method: 'POST', body: JSON.stringify({ name: 'Review Team', color: '#123456', icon: 'users' }) }, owner, 201)
  await call(`/teams/${team.id}/members`, { method: 'POST', body: JSON.stringify({ userId: teamReviewer.id }) }, owner, 201)
  const workflowRole = (await call('/workflow-roles', { method: 'POST', body: JSON.stringify({ name: 'Matrix Reviewer', color: '#123456', icon: 'badge-check' }) }, owner, 201)).role
  await call(`/users/${workflowReviewer.id}/workflow-roles`, { method: 'PUT', body: JSON.stringify({ roleIds: [workflowRole.id] }) }, owner)
  const resolutionCases = [
    [{ type: 'user', user: explicit }, explicit],
    [{ type: 'role', role: 'admin' }, roleReviewer],
    [{ type: 'team', team: team.id }, teamReviewer],
    [{ type: 'workflow_role', workflowRole: workflowRole.id }, workflowReviewer],
  ]
  for (const [rule, target] of resolutionCases) {
    const item = flow({ rules: [rule] })
    assert.equal((await start(item)).result.requires_review, true)
    const targetReviews = await mineFor(target.token, item.file)
    assert.equal(targetReviews.length, 1)
    assert.equal(targetReviews[0].gate_id, item.gateId)
  }

  // B + G approve path: every quorum stays pending until its threshold, then commits state/revision/history/event together.
  async function quorum(mode, required, approvals) {
    const item = flow({ mode, required, rules: [quorumOne, quorumTwo, quorumThree].map(actor => ({ type: 'user', user: actor })) })
    assert.equal((await start(item)).result.requires_review, true)
    const reviews = []
    for (const actor of [quorumOne, quorumTwo, quorumThree]) reviews.push((await mineFor(actor.token, item.file))[0])
    for (let index = 0; index < approvals; index++) {
      const response = await decision(reviews[index].review_id, [quorumOne, quorumTwo, quorumThree][index].token)
      if (index + 1 < approvals) {
        assert.equal(response.result.requires_review, true)
        assert.deepEqual(state(item), [item.from, String(item.revision)])
      } else assert.equal(response.result.new_state_id, item.to)
    }
    assert.deepEqual(state(item), [item.to, String(item.revision + 1)])
    assert.equal(rows(`SELECT COUNT(*) FROM workflow_history WHERE file_id='${item.file}'`)[0][0], '1')
    assert.equal(rows(`SELECT COUNT(*) FROM events WHERE aggregate_id='${item.file}' AND type='file.workflow_transition_executed'`)[0][0], '1')
    assert.equal(statuses(item), approvals === 3 ? 'approved:3' : `approved:${approvals},cancelled:${3 - approvals}`)
    await decision(reviews[0].review_id, quorumOne.token, 'approved', 409)
    return item
  }
  await quorum('any', 1, 1)
  await quorum('all', 1, 3)
  await quorum('majority', 1, 2)
  await quorum('any', 2, 2)

  // C + G reject path: rejection/kickback abort siblings, preserve state, and emit an audit event without transition history.
  for (const rejected of ['rejected', 'kicked_back']) {
    const item = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
    await start(item)
    const review = (await mineFor(quorumOne.token, item.file))[0]
    assert.equal((await decision(review.review_id, quorumOne.token, rejected)).result.requires_review, false)
    assert.deepEqual(state(item), [item.from, String(item.revision)])
    assert.deepEqual(statuses(item).split(',').sort(), ['cancelled:1', `${rejected}:1`].sort())
    assert.deepEqual(rows(`SELECT COUNT(*) FROM workflow_history WHERE file_id='${item.file}'; SELECT COUNT(*) FROM events WHERE aggregate_id='${review.review_id}' AND type='review.rejected'`).flat(), ['0', '1'])
  }

  // A new submission after kickback/rejection is a distinct review round.
  // Earlier decisions remain in history but cannot poison a later quorum.
  const repeated = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
  await start(repeated)
  const roundOne = rows(`SELECT DISTINCT review_round_id FROM pending_reviews WHERE file_id='${repeated.file}'`)[0][0]
  const firstReview = (await mineFor(quorumOne.token, repeated.file))[0]
  assert.equal((await decision(firstReview.review_id, quorumOne.token, 'kicked_back')).result.requires_review, false)

  await start(repeated)
  const roundTwo = rows(`SELECT DISTINCT review_round_id FROM pending_reviews WHERE file_id='${repeated.file}' AND status='pending'`)[0][0]
  assert.notEqual(roundTwo, roundOne)
  const secondReview = (await mineFor(quorumOne.token, repeated.file))[0]
  assert.equal((await decision(secondReview.review_id, quorumOne.token, 'rejected')).result.requires_review, false)

  await start(repeated)
  const roundThree = rows(`SELECT DISTINCT review_round_id FROM pending_reviews WHERE file_id='${repeated.file}' AND status='pending'`)[0][0]
  assert.notEqual(roundThree, roundTwo)
  assert.notEqual(roundThree, roundOne)
  const finalReviewOne = (await mineFor(quorumOne.token, repeated.file))[0]
  const finalReviewTwo = (await mineFor(quorumTwo.token, repeated.file))[0]
  assert.equal((await decision(finalReviewOne.review_id, quorumOne.token, 'approved')).result.requires_review, true)
  assert.equal((await decision(finalReviewTwo.review_id, quorumTwo.token, 'approved')).result.new_state_id, repeated.to)
  assert.deepEqual(normalizeGroupedStatusCounts(rows(`SELECT GROUP_CONCAT(status ORDER BY status) AS round_status FROM pending_reviews WHERE file_id='${repeated.file}' GROUP BY review_round_id`)), [
    'cancelled,kicked_back',
    'cancelled,rejected',
    'approved,approved',
  ].sort())

  // Ambiguous legacy partial quorums cannot approve from uncertain history,
  // but assigned reviewers can close the remaining pending task using the
  // existing reject/return actions. Terminal decisions and file history stay
  // intact; the next submission gets a clean server-owned round.
  for (const recoveryDecision of ['rejected', 'kicked_back']) {
    const ambiguousLegacy = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
    await start(ambiguousLegacy)
    const ambiguousPending = (await mineFor(quorumOne.token, ambiguousLegacy.file))[0]
    const ambiguousTerminal = (await mineFor(quorumTwo.token, ambiguousLegacy.file))[0]
    const ambiguousRound = rows(`SELECT review_round_id FROM pending_reviews WHERE id='${ambiguousPending.review_id}'`)[0][0]
    sql(`UPDATE pending_reviews SET status='approved', reviewed_by='${quorumTwo.id}', reviewed_at=UTC_TIMESTAMP(3), is_legacy_round=1, legacy_round_ambiguous=1 WHERE id='${ambiguousTerminal.review_id}';
         UPDATE pending_reviews SET is_legacy_round=1, legacy_round_ambiguous=1 WHERE id='${ambiguousPending.review_id}'`)
    const stateBeforeRecovery = state(ambiguousLegacy)
    const oldTerminalBeforeRecovery = rows(`SELECT status,reviewed_by,review_round_id FROM pending_reviews WHERE id='${ambiguousTerminal.review_id}'`)[0]

    assert.equal((await call(`/files/${ambiguousLegacy.file}/workflow-transitions/${ambiguousLegacy.transition}/execute`, { method: 'POST', body: '{}' }, owner, 409)).error, 'REVIEW_ROUND_STATE_AMBIGUOUS')
    await decision(ambiguousPending.review_id, quorumOne.token, 'approved', 409)
    await decision(ambiguousPending.review_id, quorumThree.token, recoveryDecision, 403)
    assert.deepEqual(state(ambiguousLegacy), stateBeforeRecovery)
    assert.equal(rows(`SELECT status FROM pending_reviews WHERE id='${ambiguousPending.review_id}'`)[0][0], 'pending')

    assert.equal((await decision(ambiguousPending.review_id, quorumOne.token, recoveryDecision)).result.requires_review, false)
    assert.deepEqual(rows(`SELECT status FROM pending_reviews WHERE file_id='${ambiguousLegacy.file}' AND review_round_id='${ambiguousRound}' ORDER BY status`).map(row => row[0]), ['approved', recoveryDecision].sort())
    assert.deepEqual(rows(`SELECT status,reviewed_by,review_round_id FROM pending_reviews WHERE id='${ambiguousTerminal.review_id}'`)[0], oldTerminalBeforeRecovery)
    assert.deepEqual(state(ambiguousLegacy), stateBeforeRecovery)
    assert.equal(rows(`SELECT COUNT(*) FROM workflow_history WHERE file_id='${ambiguousLegacy.file}'`)[0][0], '0')

    await start(ambiguousLegacy)
    const freshRound = rows(`SELECT DISTINCT review_round_id FROM pending_reviews WHERE file_id='${ambiguousLegacy.file}' AND status='pending'`)[0][0]
    assert.notEqual(freshRound, ambiguousRound)
    const freshOne = (await mineFor(quorumOne.token, ambiguousLegacy.file))[0]
    const freshTwo = (await mineFor(quorumTwo.token, ambiguousLegacy.file))[0]
    assert.equal((await decision(freshOne.review_id, quorumOne.token, 'approved')).result.requires_review, true)
    assert.equal((await decision(freshTwo.review_id, quorumTwo.token, 'approved')).result.new_state_id, ambiguousLegacy.to)
    assert.deepEqual(rows(`SELECT status,review_round_id FROM pending_reviews WHERE file_id='${ambiguousLegacy.file}' AND review_round_id='${ambiguousRound}' ORDER BY status`), [
      ['approved', ambiguousRound], [recoveryDecision, ambiguousRound],
    ].sort((left, right) => left[0].localeCompare(right[0])))
    assert.deepEqual(state(ambiguousLegacy), [ambiguousLegacy.to, String(ambiguousLegacy.revision + 1)])
  }

  // Assignment locking makes concurrent submissions reuse one current round.
  const concurrentStart = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
  await Promise.all([start(concurrentStart), start(concurrentStart)])
  assert.deepEqual(rows(`SELECT COUNT(*), COUNT(DISTINCT review_round_id), SUM(status='pending') FROM pending_reviews WHERE file_id='${concurrentStart.file}'`).flat(), ['2', '1', '2'])

  // Concurrent approvals serialize on the file assignment: one completes the
  // transition and the sibling becomes non-actionable, with one history row.
  const concurrentDecision = flow({ mode: 'any', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
  await start(concurrentDecision)
  const simultaneous = await Promise.all([quorumOne, quorumTwo].map(async reviewer => {
    const review = (await mineFor(reviewer.token, concurrentDecision.file))[0]
    const response = await fetch(`${server}/workflow-reviews/${review.review_id}/decision`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${reviewer.token}` },
      body: JSON.stringify({ decision: 'approved', comment: 'concurrent round decision' }),
    })
    return { status: response.status, body: await response.json() }
  }))
  assert.deepEqual(simultaneous.map(result => result.status).sort(), [200, 409])
  assert.equal(simultaneous.filter(result => result.status === 200 && result.body.result.new_state_id === concurrentDecision.to).length, 1)
  assert.deepEqual(rows(`SELECT COUNT(*) FROM workflow_history WHERE file_id='${concurrentDecision.file}'`).flat(), ['1'])

  // This fixture update simulates a sibling decision already committed while
  // the second request is blocked; it intentionally does not exercise that
  // sibling's HTTP decision endpoint.
  // Deterministically reproduce the REPEATABLE READ boundary: keep the file
  // assignment locked while one decision has reached its wait, commit its
  // sibling review, then release the lock. The final approval must see the
  // sibling's committed status rather than a snapshot created before waiting.
  const waitedDecision = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
  await start(waitedDecision)
  const waitedReviewOne = (await mineFor(quorumOne.token, waitedDecision.file))[0]
  const waitedReviewTwo = (await mineFor(quorumTwo.token, waitedDecision.file))[0]
  const releaseAssignment = await holdAssignmentLock(waitedDecision)
  const finalApproval = fetch(`${server}/workflow-reviews/${waitedReviewOne.review_id}/decision`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${quorumOne.token}` },
    body: JSON.stringify({ decision: 'approved', comment: 'snapshot boundary regression' }),
  }).then(async response => ({ status: response.status, body: await response.json() }))
  try {
    await waitForMariaDbLockWait()
    sql(`UPDATE pending_reviews SET status='approved', reviewed_by='${quorumTwo.id}', reviewed_at=UTC_TIMESTAMP(3) WHERE id='${waitedReviewTwo.review_id}' AND status='pending'`)
    assert.equal(rows(`SELECT status FROM pending_reviews WHERE id='${waitedReviewTwo.review_id}'`)[0][0], 'approved')
  } finally {
    await releaseAssignment()
  }
  const waitedResult = await finalApproval
  assert.equal(waitedResult.status, 200)
  assert.equal(waitedResult.body.result.new_state_id, waitedDecision.to)
  assert.deepEqual(state(waitedDecision), [waitedDecision.to, String(waitedDecision.revision + 1)])

  // D: expiration, stale state, duplicate execute, and replay are conflict-only, non-mutating paths.
  const expired = flow({ rules: [{ type: 'user', user: quorumOne }] }); await start(expired)
  const expiredReview = (await mineFor(quorumOne.token, expired.file))[0]
  sql(`UPDATE pending_reviews SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id='${expiredReview.review_id}'`)
  await decision(expiredReview.review_id, quorumOne.token, 'approved', 409)
  assert.deepEqual(state(expired), [expired.from, String(expired.revision)])
  const stale = flow({ rules: [{ type: 'user', user: quorumOne }] }); await start(stale)
  const staleReview = (await mineFor(quorumOne.token, stale.file))[0]
  sql(`UPDATE file_workflow_assignments SET current_state_id='${stale.to}' WHERE file_id='${stale.file}'`)
  await decision(staleReview.review_id, quorumOne.token, 'approved', 409)
  assert.deepEqual(state(stale), [stale.to, String(stale.revision)])
  assert.equal(statuses(stale), 'cancelled:1')
  const duplicate = flow({ rules: [{ type: 'user', user: quorumOne }] }); await start(duplicate); await start(duplicate)
  assert.equal(rows(`SELECT COUNT(*) FROM pending_reviews WHERE file_id='${duplicate.file}'`)[0][0], '1')

  // E: another organization and an inaccessible same-org vault can neither list nor decide/execute reviews.
  const foreignOrg = randomUUID(), foreignUser = randomUUID(), foreignToken = `foreign-${randomUUID()}`
  const foreignHash = createHash('sha256').update(`test-session-secret-must-be-at-least-thirty-two-characters:${foreignToken}`).digest('hex')
  sql(`INSERT INTO organizations(id,name,slug) VALUES('${foreignOrg}','Foreign Org','foreign-org'); INSERT INTO users(id,email,display_name,password_hash) VALUES('${foreignUser}','foreign@workflow.test','Foreign','unused'); INSERT INTO organization_memberships(organization_id,user_id,role) VALUES('${foreignOrg}','${foreignUser}','owner'); INSERT INTO sessions(token_hash,user_id,organization_id,expires_at) VALUES('${foreignHash}','${foreignUser}','${foreignOrg}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))`)
  const foreignTarget = flow({ rules: [{ type: 'user', user: quorumOne }] }); await start(foreignTarget)
  const foreignReview = (await mineFor(quorumOne.token, foreignTarget.file))[0]
  assert.equal((await call('/workflow-reviews/mine', {}, foreignToken)).reviews.length, 0)
  await decision(foreignReview.review_id, foreignToken, 'approved', 404)
  await call(`/files/${foreignTarget.file}/workflow-transitions/${foreignTarget.transition}/execute`, { method: 'POST', body: '{}' }, foreignToken, 404)
  const foreignAmbiguous = flow({ mode: 'all', rules: [{ type: 'user', user: quorumOne }, { type: 'user', user: quorumTwo }] })
  await start(foreignAmbiguous)
  const foreignAmbiguousPending = (await mineFor(quorumOne.token, foreignAmbiguous.file))[0]
  const foreignAmbiguousTerminal = (await mineFor(quorumTwo.token, foreignAmbiguous.file))[0]
  sql(`UPDATE pending_reviews SET status='approved', reviewed_by='${quorumTwo.id}', reviewed_at=UTC_TIMESTAMP(3), is_legacy_round=1, legacy_round_ambiguous=1 WHERE id='${foreignAmbiguousTerminal.review_id}'; UPDATE pending_reviews SET is_legacy_round=1, legacy_round_ambiguous=1 WHERE id='${foreignAmbiguousPending.review_id}'`)
  const foreignAmbiguousState = state(foreignAmbiguous)
  await decision(foreignAmbiguousPending.review_id, foreignToken, 'rejected', 404)
  assert.equal(rows(`SELECT status FROM pending_reviews WHERE id='${foreignAmbiguousPending.review_id}'`)[0][0], 'pending')
  assert.deepEqual(state(foreignAmbiguous), foreignAmbiguousState)
  const hiddenVault = randomUUID(); sql(`INSERT INTO vaults(id,organization_id,name,network_root) VALUES('${hiddenVault}','${org}','Hidden Vault','/tmp')`)
  const hidden = flow({ vaultId: hiddenVault, rules: [{ type: 'user', user: member }] }); await start(hidden)
  const hiddenReview = rows(`SELECT id FROM pending_reviews WHERE file_id='${hidden.file}'`)[0][0]
  await call('/workflow-reviews/mine', {}, member.token, 404)
  await decision(hiddenReview, member.token, 'approved', 404)
  await call(`/files/${hidden.file}/workflow-transitions/${hidden.transition}/execute`, { method: 'POST', body: '{}' }, member.token, 404)

  // F: owner/admin bypass transition-role constraints; a vault-authorized normal member does not.
  const roleGuard = flow({ gate: false, allowedRoles: [workflowRole.id] })
  assert.equal((await start(roleGuard)).result.new_state_id, roleGuard.to)
  const adminGuard = flow({ gate: false, allowedRoles: [workflowRole.id] })
  assert.equal((await call(`/files/${adminGuard.file}/workflow-transitions/${adminGuard.transition}/execute`, { method: 'POST', body: '{}' }, explicit.token)).result.new_state_id, adminGuard.to)
  const memberGuard = flow({ gate: false, allowedRoles: [workflowRole.id] })
  await call(`/files/${memberGuard.file}/workflow-transitions/${memberGuard.transition}/execute`, { method: 'POST', body: '{}' }, member.token, 403)
  assert.deepEqual(state(memberGuard), [memberGuard.from, String(memberGuard.revision)])

  // H: fresh install applied 034; two migrator passes are idempotent and keep direct gates nullable.
  assert.equal(rows("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='blueplm' AND TABLE_NAME='pending_reviews' AND COLUMN_NAME='gate_id'")[0][0], 'YES')
  assert.deepEqual(rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='blueplm' AND TABLE_NAME='pending_reviews' AND COLUMN_NAME IN ('review_round_id', 'is_legacy_round', 'legacy_round_ambiguous') ORDER BY COLUMN_NAME").map(row => row[0]), ['is_legacy_round', 'legacy_round_ambiguous', 'review_round_id'])
  assert.equal(run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']).toString().trim(), '')
  assert.equal(run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']).toString().trim(), '')

  // Simulate the pre-round target table in this disposable project and run the
  // real migration runner. Historical decisions must survive unchanged and be
  // isolated as a legacy bucket; this does not claim their original rounds can
  // be reconstructed from pre-migration data.
  const legacyGroups = [
    { file: randomUUID(), transition: randomUUID(), statuses: ['kicked_back', 'cancelled', 'approved'] },
    { file: randomUUID(), transition: randomUUID(), statuses: ['pending', 'pending'] },
    { file: randomUUID(), transition: randomUUID(), statuses: ['pending', 'approved'] },
  ]
  const legacyRows = legacyGroups.flatMap(group => group.statuses.map(status => {
    const id = randomUUID(), assignee = randomUUID()
    return { id, file: group.file, transition: group.transition, gate: randomUUID(), assignee, status,
      comment: status === 'pending' ? null : `legacy-${status}`,
      reviewedBy: status === 'pending' ? null : assignee,
      checklist: status === 'approved' ? '{"legacy":true}' : null }
  }))
  sql(`DROP TABLE pending_reviews;
    CREATE TABLE pending_reviews (
      id CHAR(36) NOT NULL PRIMARY KEY,
      file_id CHAR(36) NOT NULL,
      transition_id CHAR(36) NOT NULL,
      gate_id CHAR(36) NULL,
      assigned_to CHAR(36) NULL,
      status ENUM('pending','approved','rejected','cancelled','kicked_back') NOT NULL DEFAULT 'pending',
      review_comment TEXT NULL,
      checklist_responses JSON NULL,
      requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
      reviewed_at DATETIME(3) NULL,
      reviewed_by CHAR(36) NULL,
      expires_at DATETIME(3) NULL,
      UNIQUE KEY uq_pending_review_assignee (file_id, transition_id, gate_id, assigned_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    DELETE FROM schema_migrations WHERE migration='041_workflow_review_rounds.sql';
    INSERT INTO pending_reviews(id,file_id,transition_id,gate_id,assigned_to,status,review_comment,reviewed_by,checklist_responses,reviewed_at) VALUES
      ${legacyRows.map(row => `('${row.id}','${row.file}','${row.transition}','${row.gate}','${row.assignee}','${row.status}',${row.comment === null ? 'NULL' : `'${row.comment}'`},${row.reviewedBy === null ? 'NULL' : `'${row.reviewedBy}'`},${row.checklist === null ? 'NULL' : `'${row.checklist}'`},${row.status === 'pending' ? 'NULL' : 'UTC_TIMESTAMP(3)'})`).join(',')}`)
  const appliedRoundMigration = run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']).toString().trim()
  assert.match(appliedRoundMigration, /Applied 041_workflow_review_rounds\.sql/)
  for (const group of legacyGroups) {
    const expectedRows = legacyRows.filter(row => row.file === group.file)
    assert.deepEqual(rows(`SELECT id,status FROM pending_reviews WHERE file_id='${group.file}' ORDER BY id`), expectedRows.map(row => [row.id, row.status]).sort((left, right) => left[0].localeCompare(right[0])))
    for (const row of expectedRows) {
      const preserved = rows(`SELECT (review_comment <=> ${row.comment === null ? 'NULL' : `'${row.comment}'`}), (reviewed_by <=> ${row.reviewedBy === null ? 'NULL' : `'${row.reviewedBy}'`}), (checklist_responses <=> ${row.checklist === null ? 'NULL' : `'${row.checklist}'`}) FROM pending_reviews WHERE id='${row.id}'`).flat()
      assert.deepEqual(preserved, ['1', '1', '1'])
    }
    const markers = rows(`SELECT COUNT(DISTINCT review_round_id), MIN(is_legacy_round), MAX(is_legacy_round), MIN(legacy_round_ambiguous), MAX(legacy_round_ambiguous) FROM pending_reviews WHERE file_id='${group.file}'`).flat()
    assert.deepEqual(markers, ['1', '1', '1', group.statuses.includes('pending') && group.statuses.some(status => status !== 'pending') ? '1' : '0', group.statuses.includes('pending') && group.statuses.some(status => status !== 'pending') ? '1' : '0'])
  }
  console.log('workflow review integration matrix: A-H ok')
} finally { try { composeRun(['down', '--remove-orphans']) } catch {} }
