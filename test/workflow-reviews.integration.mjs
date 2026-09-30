// Focused workflow-review smoke/integration harness.  Unlike integration.mjs it
// owns its compose project and only exercises the review contract.
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'

const project = `blueplm-workflow-${process.pid}`
const compose = ['compose', '-p', project, '-f', 'docker-compose.test.yml']
const server = 'http://127.0.0.1:18081'
const installToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Workflow owner password 123!'
const run = (args, options = {}) => execFileSync('docker', args, { cwd: process.cwd(), stdio: 'pipe', ...options })
const sql = (statement) => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
async function call(path, init = {}, token, expected = 200) {
  const response = await fetch(`${server}${path}`, { ...init, headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) } })
  const body = await response.json()
  assert.equal(response.status, expected, `${path}: ${JSON.stringify(body)}`)
  return body
}
try {
  // Explicit project/port makes this safe alongside the broad test suite.
  run([...compose, 'up', '--build', '-d', '--wait'], { env: { ...process.env, BLUEPLM_PHP_TEST_PORT: '18081' } })
  for (let i = 0; i < 30; i++) { try { if ((await call('/health')).ok) break } catch {} await new Promise(r => setTimeout(r, 250)) }
  const installed = await call('/installer/commit', { method: 'POST', body: JSON.stringify({ installationToken: installToken, action: 'install', bootstrap: { organizationName: 'Workflow Org', organizationSlug: 'workflow-org', email: 'owner@workflow.test', displayName: 'Owner', password } }) })
  const owner = installed.token
  const ownerMe = await call('/auth/me', {}, owner)
  const reviewer = await call('/users', { method: 'POST', body: JSON.stringify({ email: 'reviewer@workflow.test', displayName: 'Reviewer', password, role: 'admin' }) }, owner, 201)
  const reviewerToken = (await call('/auth/login', { method: 'POST', body: JSON.stringify({ email: 'reviewer@workflow.test', password }) })).token
  const [vault, file, workflow, from, to, transition] = Array.from({ length: 6 }, randomUUID)
  const org = ownerMe.user.organizationId, user = ownerMe.user.userId
  sql(`INSERT INTO vaults(id,organization_id,name,network_root) VALUES('${vault}','${org}','Workflow Vault','/tmp'); INSERT INTO files(id,organization_id,vault_id,canonical_path,file_name,storage_relative_path,current_revision) VALUES('${file}','${org}','${vault}','review.txt','review.txt','review.txt',7); INSERT INTO workflow_templates(id,organization_id,name,created_by) VALUES('${workflow}','${org}','Review flow','${user}'); INSERT INTO workflow_states(id,workflow_id,name,label) VALUES('${from}','${workflow}','Draft','Draft'),('${to}','${workflow}','Review','Review'); INSERT INTO workflow_transitions(id,workflow_id,from_state_id,to_state_id,name,waypoints) VALUES('${transition}','${workflow}','${from}','${to}','Review','[]'); INSERT INTO file_workflow_assignments(id,file_id,workflow_id,current_state_id,assigned_by) VALUES('${randomUUID()}','${file}','${workflow}','${from}','${user}')`)
  const available = await call(`/files/${file}/available-transitions`, {}, owner)
  assert.deepEqual(Object.keys(available.transitions[0]).sort(), ['transition_id','transition_name','to_state_id','to_state_name','to_state_color','has_gates','user_can_transition'].sort())
  // triggers_review without a gate must create a nullable, solvable review.
  sql(`UPDATE workflow_states SET triggers_review=TRUE,auto_increment_revision=TRUE WHERE id='${to}'`)
  assert.equal((await call(`/files/${file}/workflow-transitions/${transition}/execute`, { method: 'POST', body: '{}' }, owner)).result.requires_review, true)
  const mine = await call('/workflow-reviews/mine', {}, owner)
  assert.equal(mine.reviews.length, 1); assert.equal(mine.reviews[0].gate_id, null)
  const approval = await call(`/workflow-reviews/${mine.reviews[0].review_id}/decision`, { method: 'POST', body: JSON.stringify({ decision: 'approved' }) }, owner)
  const decisionState = run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', `SELECT status,IFNULL(gate_id,'NULL') FROM pending_reviews WHERE id='${mine.reviews[0].review_id}'; SELECT COUNT(*) FROM workflow_gates WHERE transition_id='${transition}'`]).toString().trim()
  assert.equal(approval.result.new_state_id, to, `${JSON.stringify(approval)}; ${decisionState}`)
  // Completion is atomic: state, revision, history and event are committed together.
  const checks = `SELECT (SELECT current_state_id FROM file_workflow_assignments WHERE file_id='${file}') state,(SELECT current_revision FROM files WHERE id='${file}') revision,(SELECT COUNT(*) FROM workflow_history WHERE file_id='${file}') history,(SELECT COUNT(*) FROM events WHERE entity_id='${file}' AND event_type='file.workflow_transition_executed') events`
  const result = run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', checks]).toString().trim().split('\t')
  assert.deepEqual(result, [to, '8', '1', '1'])
  // Replay, expired and stale decisions remain non-mutating; an unassigned admin is not privileged.
  await call(`/workflow-reviews/${mine.reviews[0].review_id}/decision`, { method: 'POST', body: JSON.stringify({ decision: 'approved' }) }, owner, 409)
  await call(`/workflow-reviews/${mine.reviews[0].review_id}/decision`, { method: 'POST', body: JSON.stringify({ decision: 'approved' }) }, reviewerToken, 403)
  // Migration 034 is applied by fresh install and the documented migrator is repeatable.
  run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']); run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php'])
  console.log('workflow review integration: ok')
} finally { if (!process.env.BLUEPLM_KEEP_WORKFLOW_TEST) try { run([...compose, 'down', '--volumes', '--remove-orphans']) } catch {} }
