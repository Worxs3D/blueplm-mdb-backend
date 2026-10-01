// Focused workflow-review integration matrix. It owns its Compose project and
// verifies review decisions through both the HTTP contract and MariaDB state.
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'

const project = `blueplm-workflow-${process.pid}`
const compose = ['compose', '-p', project, '-f', 'docker-compose.test.yml']
const server = 'http://127.0.0.1:18081'
const installToken = 'integration-installation-token-must-be-at-least-32-chars'
const password = 'Workflow owner password 123!'
const run = (args, options = {}) => execFileSync('docker', args, { cwd: process.cwd(), stdio: 'pipe', ...options })
const sql = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement])
const rows = statement => run([...compose, 'exec', '-T', 'mariadb', 'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', statement]).toString().trim().split(/\r?\n/).filter(Boolean).map(line => line.split('\t'))
async function call(path, init = {}, token, expected = 200) {
  const response = await fetch(`${server}${path}`, { ...init, headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) } })
  const body = await response.json()
  assert.equal(response.status, expected, `${path}: ${JSON.stringify(body)}`)
  return body
}
const decision = (id, token, value = 'approved', expected = 200) => call(`/workflow-reviews/${id}/decision`, { method: 'POST', body: JSON.stringify({ decision: value, comment: `${value} matrix` }) }, token, expected)
const mineFor = async (token, file) => (await call('/workflow-reviews/mine', {}, token)).reviews.filter(review => review.file_id === file)

try {
  // Explicit project/port/image context makes this safe alongside other suites.
  run([...compose, 'up', '--build', '-d', '--wait'], { env: { ...process.env, BLUEPLM_PHP_TEST_PORT: '18081' } })
  for (let i = 0; i < 30; i++) { try { if ((await call('/health')).ok) break } catch {} await new Promise(resolve => setTimeout(resolve, 250)) }
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
  assert.equal(run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']).toString().trim(), '')
  assert.equal(run([...compose, 'exec', '-T', 'api', 'php', 'bin/migrate.php']).toString().trim(), '')
  console.log('workflow review integration matrix: A-H ok')
} finally { if (!process.env.BLUEPLM_KEEP_WORKFLOW_TEST) try { run([...compose, 'down', '--volumes', '--remove-orphans']) } catch {} }
