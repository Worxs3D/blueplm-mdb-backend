import assert from 'node:assert/strict'
import { execFileSync, spawn } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { performance } from 'node:perf_hooks'

const SECOND_ORG_MEMBERSHIP_AGE_DAYS = 1

function queryTestDatabase(sql) {
  return execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim()
}

function checkoutRequestState(requestId) {
  const [status, tokenPurged] = queryTestDatabase(
    `SELECT status, token_ciphertext IS NULL FROM checkout_acquisition_requests WHERE organization_id = (SELECT id FROM organizations WHERE slug = 'integration-org') AND request_id = '${requestId.toLowerCase()}';`,
  ).split('\t')
  return { status, tokenPurged: tokenPurged === '1' }
}

function spawnCheckoutCancelLockHolder(fileId, organizationId, fileOnly = false) {
  const args = [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'api',
    'php', 'test/checkout-cancel-lock-holder.php', fileId, organizationId,
  ]
  if (fileOnly) args.push('file-only')
  const child = spawn('docker', args, { cwd: process.cwd(), stdio: ['pipe', 'pipe', 'pipe'] })
  let stdout = ''
  let stderr = ''
  child.stdout.setEncoding('utf8')
  child.stderr.setEncoding('utf8')
  child.stdout.on('data', chunk => { stdout += chunk })
  child.stderr.on('data', chunk => { stderr += chunk })
  const done = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('close', (code) => {
      if (code === 0) resolve({ stdout, stderr })
      else reject(new Error(`Checkout lock holder exited ${code}: ${stderr}`))
    })
  })
  return { child, done, output: () => stdout }
}

function spawnUserLifecycleLockHolder(userId) {
  const child = spawn('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'api',
    'php', 'test/checkout-user-lifecycle-lock-holder.php', userId,
  ], { cwd: process.cwd(), stdio: ['pipe', 'pipe', 'pipe'] })
  let stdout = ''
  let stderr = ''
  child.stdout.setEncoding('utf8')
  child.stderr.setEncoding('utf8')
  child.stdout.on('data', chunk => { stdout += chunk })
  child.stderr.on('data', chunk => { stderr += chunk })
  const done = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('close', code => {
      if (code === 0) resolve({ stdout, stderr })
      else reject(new Error(`Checkout lifecycle lock holder exited ${code}: ${stderr}`))
    })
  })
  return { child, done, output: () => stdout }
}

function spawnRequestLockHolder(operation, organizationId, requestId) {
  const child = spawn('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'api',
    'php', 'test/request-lock-holder.php', operation, organizationId, requestId,
  ], { cwd: process.cwd(), stdio: ['pipe', 'pipe', 'pipe'] })
  let stdout = ''
  let stderr = ''
  child.stdout.setEncoding('utf8')
  child.stderr.setEncoding('utf8')
  child.stdout.on('data', chunk => { stdout += chunk })
  child.stderr.on('data', chunk => { stderr += chunk })
  const done = new Promise((resolve, reject) => {
    child.once('error', reject)
    child.once('close', (code) => {
      if (code === 0) resolve({ stdout, stderr })
      else reject(new Error(`Request lock holder exited ${code}: ${stderr}`))
    })
  })
  return { child, done, output: () => stdout }
}

async function waitForOutput(worker, expected, timeoutMs = 20000) {
  const started = Date.now()
  while (!worker.output().includes(expected)) {
    if (Date.now() - started >= timeoutMs) throw new Error(`Timed out waiting for worker output ${expected}.`)
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}

function releaseLockHolder(worker) {
  if (worker.child.exitCode !== null) return
  worker.child.stdin.on('error', () => {})
  worker.child.stdin.write('continue\n')
}

function checkoutRequestLockName(organizationId, requestId) {
  const databaseName = queryTestDatabase('SELECT DATABASE();')
  const digest = createHash('sha256')
    .update([databaseName, 'checkout', organizationId.toLowerCase(), requestId.toLowerCase()].join('\0'))
    .digest('hex')
    .slice(0, 56)
  return `blueplm:${digest}`
}

function userLifecycleLockName(userId) {
  const databaseName = queryTestDatabase('SELECT DATABASE();')
  const digest = createHash('sha256')
    .update([databaseName, 'checkout-user-lifecycle', userId.toLowerCase()].join('\0'))
    .digest('hex')
    .slice(0, 56)
  return `blueplm:${digest}`
}

function queryTestDatabaseAsRoot(sql, timeoutMs = 0) {
  const options = { cwd: process.cwd(), stdio: 'pipe' }
  if (timeoutMs > 0) options.timeout = timeoutMs
  return execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-uroot', '-proot-test-password', 'blueplm', '-e', sql,
  ], options).toString().trim()
}

function advisoryLockOwner(lockName) {
  return Number(queryTestDatabaseAsRoot(`SELECT COALESCE(IS_USED_LOCK('${lockName}'), 0);`))
}

async function waitForAdvisoryLockOwner(lockName, timeoutMs = 20000) {
  const started = Date.now()
  while (advisoryLockOwner(lockName) === 0) {
    if (Date.now() - started >= timeoutMs) throw new Error('Checkout lifecycle mutex was not acquired.')
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}

function userLifecycleLockWaitCount(timeoutMs) {
  // Native PDO prepares hide the bound lock name in PROCESSLIST.INFO. These
  // serial scenarios assert a zero-waiter baseline before starting one contender.
  return Number(queryTestDatabaseAsRoot("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = 'blueplm' AND USER = 'blueplm' AND STATE = 'User lock' AND INFO LIKE '%GET_LOCK%';", timeoutMs))
}

async function waitForUserLifecycleLockWaitCount(lockName, expectedCount, timeoutMs = 4500) {
  const deadline = performance.now() + timeoutMs
  let probeTimedOut = false
  while (true) {
    const remainingMs = deadline - performance.now()
    if (remainingMs <= 0) {
      const detail = probeTimedOut ? ' Database waiter probes also timed out under load.' : ''
      throw new Error(`Expected exactly ${expectedCount} API waiter for lifecycle mutex ${lockName}.${detail}`)
    }
    let observedCount
    try {
      observedCount = userLifecycleLockWaitCount(Math.min(1000, remainingMs))
    } catch (error) {
      if (error.code === 'ETIMEDOUT') {
        probeTimedOut = true
        continue
      }
      throw error
    }
    if (observedCount > expectedCount) throw new Error(`Expected exactly ${expectedCount} API waiter for lifecycle mutex ${lockName}, observed ${observedCount}.`)
    if (observedCount === expectedCount) return
    await new Promise(resolve => setTimeout(resolve, Math.min(25, Math.max(1, deadline - performance.now()))))
  }
}

function namedLockFileWaitCount(lockName) {
  const sql = `SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = IS_USED_LOCK('${lockName}');`
  return Number(queryTestDatabaseAsRoot(sql))
}

async function waitForNamedLockFileWait(lockName, timeoutMs = 20000) {
  const started = Date.now()
  while (namedLockFileWaitCount(lockName) === 0) {
    if (Date.now() - started >= timeoutMs) throw new Error('Named-lock owner did not reach its expected InnoDB file-lock wait.')
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}


export async function runCheckoutRequestIdRecoveryTests(context) {
  const { request, requestStatus, duplicatePart, imported, workspace, token, updatedMemberLogin, vault, foreignOrgId, foreignUser, sameHashRequest } = context
// A held check-in request lock fails closed without writing a revision, and
// the same requestId is safe to retry after the lock becomes available.
const busyCheckinCheckout = await request(`/files/${imported.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
const busyCheckinRequestId = randomUUID()
const testOrganizationId = queryTestDatabase("SELECT id FROM organizations WHERE slug = 'integration-org';")
const selfDeleteAdmin = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'uppercase-self-delete@example.test', displayName: 'Uppercase Self Delete', password: 'Uppercase self delete password 123!', role: 'admin' }),
}, token)
const selfDeleteAdminLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'uppercase-self-delete@example.test', password: 'Uppercase self delete password 123!' }),
})
const uppercaseSelfDelete = await requestStatus(`/users/${selfDeleteAdmin.id.toUpperCase()}`, { method: 'DELETE' }, selfDeleteAdminLogin.token)
assert.equal(uppercaseSelfDelete.status, 400)
assert.equal(uppercaseSelfDelete.body.error, 'SELF_DELETE')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM organization_memberships WHERE organization_id = '${testOrganizationId}' AND user_id = '${selfDeleteAdmin.id}';`), '1')
const checkinLockHolder = spawnRequestLockHolder('checkin', testOrganizationId, busyCheckinRequestId)
await waitForOutput(checkinLockHolder, 'READY')
const busyCheckinRevisionCount = (await request(`/files/${imported.id}/revisions`, {}, token)).revisions.length
const busyCheckinBody = {
  ...sameHashRequest,
  requestId: busyCheckinRequestId,
  checkoutToken: busyCheckinCheckout.checkoutToken,
  storageRelativePath: 'immutable/revision-5-busy-retry.sldprt',
}
const busyCheckin = await requestStatus(`/files/${imported.id}/checkin`, {
  method: 'POST', body: JSON.stringify(busyCheckinBody),
}, token)
assert.equal(busyCheckin.status, 503)
assert.equal(busyCheckin.body.error, 'CHECKIN_RECOVERY_BUSY')
assert.equal((await request(`/files/${imported.id}/revisions`, {}, token)).revisions.length, busyCheckinRevisionCount)
releaseLockHolder(checkinLockHolder)
await checkinLockHolder.done
const retriedBusyCheckin = await request(`/files/${imported.id}/checkin`, {
  method: 'POST', body: JSON.stringify(busyCheckinBody),
}, token)
assert.equal(retriedBusyCheckin.revision, busyCheckinRevisionCount + 1)

// Checkout acquisition retries are durable across a committed response that
// the desktop may not have received. Same-ID retries return the same lease;
// cancel, check-in, expiry, and unreadable ciphertext never resurrect it.
const checkoutRequestId = randomUUID()
const [checkoutAcquisitionA, checkoutAcquisitionB] = await Promise.all([
  request(`/files/${duplicatePart.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: checkoutRequestId }),
  }, token),
  request(`/files/${duplicatePart.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: checkoutRequestId }),
  }, token),
])
assert.equal(checkoutAcquisitionA.checkoutToken, checkoutAcquisitionB.checkoutToken)
assert.equal(checkoutAcquisitionA.expiresAt, checkoutAcquisitionB.expiresAt)
assert.equal([checkoutAcquisitionA.replayed, checkoutAcquisitionB.replayed].sort().join(','), 'false,true')
const inaccessibleCheckoutReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: checkoutRequestId }),
}, updatedMemberLogin.token)
assert.equal(inaccessibleCheckoutReplay.status, 404)
assert.equal(Object.hasOwn(inaccessibleCheckoutReplay.body, 'checkoutToken'), false)
const renewedCheckout = await request(`/files/${duplicatePart.id.toUpperCase()}/checkout/renew`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: checkoutAcquisitionA.checkoutToken }),
}, token)
const renewedCheckoutReplay = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: checkoutRequestId }),
}, token)
assert.equal(renewedCheckoutReplay.checkoutToken, checkoutAcquisitionA.checkoutToken)
assert.equal(renewedCheckoutReplay.expiresAt, renewedCheckout.expiresAt)
assert.equal(renewedCheckoutReplay.replayed, true)

const concurrentRenewRequestId = checkoutRequestId
const concurrentRenewLease = checkoutAcquisitionA
const [concurrentRenew, concurrentReplay] = await Promise.all([
  request(`/files/${duplicatePart.id}/checkout/renew`, {
    method: 'POST', body: JSON.stringify({ checkoutToken: concurrentRenewLease.checkoutToken }),
  }, token),
  request(`/files/${duplicatePart.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: concurrentRenewRequestId }),
  }, token),
])
assert.equal(concurrentReplay.checkoutToken, concurrentRenewLease.checkoutToken)
assert.ok([renewedCheckout.expiresAt, concurrentRenew.expiresAt].includes(concurrentReplay.expiresAt))
const afterConcurrentRenewReplay = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: concurrentRenewRequestId }),
}, token)
assert.equal(afterConcurrentRenewReplay.expiresAt, concurrentRenew.expiresAt)

await request(`/files/${duplicatePart.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: checkoutAcquisitionA.checkoutToken }),
}, token)
const caseRouteFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'uppercase-cancel.sldprt', fileName: 'uppercase-cancel.sldprt', storageRelativePath: 'uppercase-cancel.sldprt' }),
}, token)
const caseRouteLease = await request(`/files/${caseRouteFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
assert.equal((await requestStatus(`/files/${caseRouteFile.id.toUpperCase()}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: caseRouteLease.checkoutToken }),
}, token)).status, 204)
const cancelledCheckoutReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: checkoutRequestId }),
}, token)
assert.equal(cancelledCheckoutReplay.status, 409)
assert.equal(cancelledCheckoutReplay.body.error, 'CHECKOUT_REQUEST_TERMINAL')
assert.equal(Object.hasOwn(cancelledCheckoutReplay.body, 'checkoutToken'), false)
assert.deepEqual(checkoutRequestState(checkoutRequestId), { status: 'cancelled', tokenPurged: true })
const changedCheckoutPathReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: `${workspace}-other`, requestId: checkoutRequestId }),
}, token)
assert.equal(changedCheckoutPathReplay.status, 409)
assert.equal(changedCheckoutPathReplay.body.error, 'IDEMPOTENCY_KEY_REUSED')

const consumedCheckoutRequestId = randomUUID()
const consumedCheckout = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: consumedCheckoutRequestId }),
}, token)
await request(`/files/${duplicatePart.id}/checkin`, {
  method: 'POST',
  body: JSON.stringify({ checkoutToken: consumedCheckout.checkoutToken, storageRelativePath: 'legacy-checkout-recovery/duplicate-part.sldprt' }),
}, token)
const consumedCheckoutReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: consumedCheckoutRequestId }),
}, token)
assert.equal(consumedCheckoutReplay.status, 409)
assert.equal(consumedCheckoutReplay.body.error, 'CHECKOUT_REQUEST_TERMINAL')
assert.equal(Object.hasOwn(consumedCheckoutReplay.body, 'checkoutToken'), false)
assert.deepEqual(checkoutRequestState(consumedCheckoutRequestId), { status: 'consumed', tokenPurged: true })

const expiredCheckoutRequestId = randomUUID()
const expiredCheckout = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: expiredCheckoutRequestId }),
}, token)
const expireSql = `UPDATE checkouts SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${duplicatePart.id}'; UPDATE checkout_acquisition_requests SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${duplicatePart.id}' AND request_id = '${expiredCheckoutRequestId}';`
execFileSync('docker', [
  'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
  'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', expireSql,
], { cwd: process.cwd(), stdio: 'pipe' })
const expiredCheckoutReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: expiredCheckoutRequestId }),
}, token)
assert.equal(expiredCheckoutReplay.status, 409)
assert.equal(expiredCheckoutReplay.body.error, 'CHECKOUT_REQUEST_TERMINAL')
assert.equal(Object.hasOwn(expiredCheckoutReplay.body, 'checkoutToken'), false)
assert.deepEqual(checkoutRequestState(expiredCheckoutRequestId), { status: 'expired', tokenPurged: true })

const damagedCheckoutRequestId = randomUUID()
const damagedCheckout = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: damagedCheckoutRequestId }),
}, token)
const damageSql = `UPDATE checkout_acquisition_requests SET token_ciphertext = 'not-a-valid-ciphertext' WHERE file_id = '${duplicatePart.id}' AND request_id = '${damagedCheckoutRequestId}';`
execFileSync('docker', [
  'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
  'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', damageSql,
], { cwd: process.cwd(), stdio: 'pipe' })
const damagedCheckoutReplay = await requestStatus(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: damagedCheckoutRequestId }),
}, token)
assert.equal(damagedCheckoutReplay.status, 503)
assert.equal(damagedCheckoutReplay.body.error, 'CHECKOUT_RECOVERY_UNAVAILABLE')
assert.equal(Object.hasOwn(damagedCheckoutReplay.body, 'checkoutToken'), false)
await request(`/files/${duplicatePart.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: damagedCheckout.checkoutToken }),
}, token)

const checkoutRaceFileA = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'checkout-race-a.sldprt', fileName: 'checkout-race-a.sldprt', storageRelativePath: 'checkout-race-a.sldprt' }),
}, token)
const checkoutRaceFileB = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'checkout-race-b.sldprt', fileName: 'checkout-race-b.sldprt', storageRelativePath: 'checkout-race-b.sldprt' }),
}, token)
const collisionRequestId = randomUUID()
const [collisionA, collisionB] = await Promise.all([
  requestStatus(`/files/${checkoutRaceFileA.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: collisionRequestId }),
  }, token),
  requestStatus(`/files/${checkoutRaceFileB.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: collisionRequestId }),
  }, token),
])
assert.deepEqual([collisionA.status, collisionB.status].sort(), [200, 409])
const checkoutCollision = [
  { fileId: checkoutRaceFileA.id, result: collisionA },
  { fileId: checkoutRaceFileB.id, result: collisionB },
].find(entry => entry.result.status === 200)
assert.equal([collisionA, collisionB].find(result => result.status === 409).body.error, 'IDEMPOTENCY_KEY_REUSED')
assert.equal(Object.hasOwn([collisionA, collisionB].find(result => result.status === 409).body, 'checkoutToken'), false)
await request(`/files/${checkoutCollision.fileId}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: checkoutCollision.result.body.checkoutToken }),
}, token)

// Case variants of one UUID must share a request lock and the case-insensitive
// request-table key, including a concurrent cross-file collision and retry.
const [distinctRequestA, distinctRequestB] = await Promise.all([
  requestStatus(`/files/${checkoutRaceFileA.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: randomUUID() }),
  }, token),
  requestStatus(`/files/${checkoutRaceFileB.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: randomUUID() }),
  }, token),
])
assert.deepEqual([distinctRequestA.status, distinctRequestB.status], [200, 200])
await request(`/files/${checkoutRaceFileA.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: distinctRequestA.body.checkoutToken }),
}, token)
await request(`/files/${checkoutRaceFileB.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: distinctRequestB.body.checkoutToken }),
}, token)

const mixedCaseRequestId = randomUUID()
const [mixedCaseA, mixedCaseB] = await Promise.all([
  requestStatus(`/files/${checkoutRaceFileA.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: mixedCaseRequestId.toUpperCase() }),
  }, token),
  requestStatus(`/files/${checkoutRaceFileB.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: mixedCaseRequestId.toLowerCase() }),
  }, token),
])
assert.deepEqual([mixedCaseA.status, mixedCaseB.status].sort(), [200, 409])
assert.equal([mixedCaseA, mixedCaseB].find(result => result.status === 409).body.error, 'IDEMPOTENCY_KEY_REUSED')
const mixedCaseWinner = [
  { fileId: checkoutRaceFileA.id, result: mixedCaseA },
  { fileId: checkoutRaceFileB.id, result: mixedCaseB },
].find(entry => entry.result.status === 200)
const mixedCaseRetry = await request(`/files/${mixedCaseWinner.fileId.toUpperCase()}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: mixedCaseRequestId.toUpperCase() }),
}, token)
assert.equal(mixedCaseRetry.replayed, true)
assert.equal(mixedCaseRetry.checkoutToken, mixedCaseWinner.result.body.checkoutToken)
await request(`/files/${mixedCaseWinner.fileId}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: mixedCaseRetry.checkoutToken }),
}, token)

// A fresh requestId cannot acquire a file while another user's live lease is active.
const foreignLease = await request(`/files/${checkoutRaceFileB.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: randomUUID() }),
}, token)
const blockedForeignLease = await requestStatus(`/files/${checkoutRaceFileB.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: randomUUID() }),
}, updatedMemberLogin.token)
assert.equal(blockedForeignLease.status, 409)
assert.equal(blockedForeignLease.body.error, 'CHECKED_OUT')
assert.equal(Object.hasOwn(blockedForeignLease.body, 'checkoutToken'), false)
await request(`/files/${checkoutRaceFileB.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: foreignLease.checkoutToken }),
}, token)

// Hold the real file row in a second PDO connection, start an HTTP acquisition,
// observe its InnoDB wait, then execute the cancel transaction's FK-backed event
// insert. This catches org/file lock inversion that Promise.all alone cannot prove.
const cancelledDuringAcquireRequestId = randomUUID()
const busyCheckoutRequestId = randomUUID()
const requestLockHolder = spawnRequestLockHolder('checkout', testOrganizationId, busyCheckoutRequestId)
await waitForOutput(requestLockHolder, 'READY')
const busyCheckout = await requestStatus(`/files/${checkoutRaceFileA.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: busyCheckoutRequestId }),
}, token)
assert.equal(busyCheckout.status, 503)
assert.equal(busyCheckout.body.error, 'CHECKOUT_RECOVERY_BUSY')
releaseLockHolder(requestLockHolder)
await requestLockHolder.done
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id = '${testOrganizationId}' AND request_id = '${busyCheckoutRequestId}';`), '0')
const retryAfterBusyCheckout = await request(`/files/${checkoutRaceFileA.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: busyCheckoutRequestId }),
}, token)
assert.equal(retryAfterBusyCheckout.replayed, false)
await request(`/files/${checkoutRaceFileA.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: retryAfterBusyCheckout.checkoutToken }),
}, token)

await request(`/files/${checkoutRaceFileB.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: cancelledDuringAcquireRequestId }),
}, token)
const cancelLockHolder = spawnCheckoutCancelLockHolder(checkoutRaceFileB.id, testOrganizationId)
await waitForOutput(cancelLockHolder, 'READY')
const concurrentAcquireRequestId = randomUUID()
const concurrentAcquireLockName = checkoutRequestLockName(testOrganizationId, concurrentAcquireRequestId)
const concurrentAcquire = requestStatus(`/files/${checkoutRaceFileB.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: concurrentAcquireRequestId }),
}, token).then(value => ({ value }), error => ({ error }))
let lockWaitError = null
try {
  await waitForNamedLockFileWait(concurrentAcquireLockName)
} catch (error) {
  lockWaitError = error
} finally {
  releaseLockHolder(cancelLockHolder)
  await cancelLockHolder.done
}
const acquireResult = await concurrentAcquire
if (lockWaitError) throw lockWaitError
if (acquireResult.error) throw acquireResult.error
const acquiredAfterConcurrentCancel = acquireResult.value
assert.equal(acquiredAfterConcurrentCancel.status, 200)
assert.deepEqual(checkoutRequestState(cancelledDuringAcquireRequestId), { status: 'cancelled', tokenPurged: true })
await request(`/files/${checkoutRaceFileB.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: acquiredAfterConcurrentCancel.body.checkoutToken }),
}, token)

// A legacy checkout may replace an expired ID-backed lease, but it must first
// terminalize the old durable record and erase its recoverable token ciphertext.
const legacyReplacementRequestId = randomUUID()
const legacyReplacementLease = await request(`/files/${checkoutRaceFileA.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: legacyReplacementRequestId }),
}, token)
queryTestDatabase(`UPDATE checkouts SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${checkoutRaceFileA.id}'; UPDATE checkout_acquisition_requests SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${checkoutRaceFileA.id}' AND request_id = '${legacyReplacementRequestId}';`)
const legacyReplacement = await request(`/files/${checkoutRaceFileA.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
assert.notEqual(legacyReplacement.checkoutToken, legacyReplacementLease.checkoutToken)
assert.deepEqual(checkoutRequestState(legacyReplacementRequestId), { status: 'expired', tokenPurged: true })
await request(`/files/${checkoutRaceFileA.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: legacyReplacement.checkoutToken }),
}, token)

// Concurrent reuse of one request ID across different files is serialized, so
// one operation commits and the other receives a stable idempotency conflict.
const raceCheckoutA = await request(`/files/${imported.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
const raceCheckoutB = await request(`/files/${duplicatePart.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
const sharedRequestId = randomUUID()
const [raceA, raceB] = await Promise.all([
  requestStatus(`/files/${imported.id}/checkin`, {
    method: 'POST', body: JSON.stringify({
      mode: 'metadata-only', requestId: sharedRequestId,
      canonicalPath: 'race/imported.sldprt', fileName: 'imported.sldprt',
      checkoutToken: raceCheckoutA.checkoutToken,
    }),
  }, token),
  requestStatus(`/files/${duplicatePart.id}/checkin`, {
    method: 'POST', body: JSON.stringify({
      mode: 'metadata-only', requestId: sharedRequestId.toUpperCase(),
      canonicalPath: 'race/duplicate-part.sldprt', fileName: 'duplicate-part.sldprt',
      checkoutToken: raceCheckoutB.checkoutToken,
    }),
  }, token),
])
assert.deepEqual([raceA.status, raceB.status].sort(), [200, 409])
assert.equal([raceA, raceB].find(result => result.status === 409).body.error, 'IDEMPOTENCY_KEY_REUSED')
assert.equal(duplicatePart.created, true)

// Removing a member and self-deleting must terminalize that user's durable
// leases in the same transaction, without changing another tenant's record.
const foreignLeaseFileId = randomUUID()
const foreignLeaseVaultId = randomUUID()
const foreignLeaseRequestId = randomUUID()
const foreignLeaseHash = 'f'.repeat(64)
queryTestDatabase(`
  INSERT INTO vaults (id, organization_id, name, network_root) VALUES ('${foreignLeaseVaultId}', '${foreignOrgId}', 'Foreign Checkout Vault', 'C:/foreign-vault');
  INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path) VALUES ('${foreignLeaseFileId}', '${foreignOrgId}', '${foreignLeaseVaultId}', 'foreign/keep.sldprt', 'keep.sldprt', 'foreign/keep.sldprt');
  INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES ('${foreignLeaseFileId}', '${foreignUser.id}', '${foreignLeaseHash}', 'C:/foreign-workspace', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR));
  INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES ('${foreignOrgId}', '${foreignLeaseRequestId}', '${foreignUser.id}', '${foreignLeaseFileId}', '${foreignLeaseVaultId}', 'C:/foreign-workspace', '${'a'.repeat(64)}', '${foreignLeaseHash}', 'foreign-tenant-ciphertext-fixture', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR), 'active');
`)
const removalUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'lease-removal@example.test', displayName: 'Lease Removal', password: 'Lease removal password 123!' }),
}, token)
await request(`/users/${removalUser.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }),
}, token)
const removalLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'lease-removal@example.test', password: 'Lease removal password 123!' }),
})
const removalFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'lease-removal.sldprt', fileName: 'lease-removal.sldprt', storageRelativePath: 'lease-removal.sldprt' }),
}, token)
const removalRequestId = randomUUID()
await request(`/files/${removalFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: removalRequestId }),
}, removalLogin.token)
const secondOrgRemovalFileId = randomUUID()
const secondOrgRemovalRequestId = randomUUID()
queryTestDatabase(`
  INSERT INTO organization_memberships (organization_id, user_id, role, created_at) VALUES ('${foreignOrgId}', '${removalUser.id}', 'member', DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ${SECOND_ORG_MEMBERSHIP_AGE_DAYS} DAY));
  INSERT INTO vault_access (vault_id, user_id, granted_by) VALUES ('${foreignLeaseVaultId}', '${removalUser.id}', '${foreignUser.id}');
  INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path) VALUES ('${secondOrgRemovalFileId}', '${foreignOrgId}', '${foreignLeaseVaultId}', 'foreign/same-user.sldprt', 'same-user.sldprt', 'foreign/same-user.sldprt');
`)
const removalForeignLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'lease-removal@example.test', password: 'Lease removal password 123!' }),
})
assert.equal((await request('/organizations/current', {}, removalForeignLogin.token)).organization.id, foreignOrgId)
const secondOrgRemovalLease = await request(`/files/${secondOrgRemovalFileId}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: secondOrgRemovalRequestId }),
}, removalForeignLogin.token)
const removalLockHolder = spawnCheckoutCancelLockHolder(removalFile.id, testOrganizationId)
await waitForOutput(removalLockHolder, 'READY')
const removalAdditionalFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'lease-removal-additional.sldprt', fileName: 'lease-removal-additional.sldprt', storageRelativePath: 'lease-removal-additional.sldprt' }),
}, token)
const removalAdditionalRequestId = randomUUID()
await request(`/files/${removalAdditionalFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: removalAdditionalRequestId }),
}, removalLogin.token)
const removalMutexName = userLifecycleLockName(removalUser.id)
const concurrentRemovalCheckout = requestStatus(`/files/${removalFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: removalRequestId }),
}, removalLogin.token).then(value => ({ value }), error => ({ error }))
let concurrentMemberRemoval = null
let concurrentRemovalLockError = null
try {
  await waitForNamedLockFileWait(removalMutexName)
  assert.equal(userLifecycleLockWaitCount(1000), 0)
  concurrentMemberRemoval = requestStatus(`/users/${removalUser.id.toUpperCase()}`, { method: 'DELETE' }, token)
    .then(value => ({ value }), error => ({ error }))
  await waitForUserLifecycleLockWaitCount(removalMutexName, 1)
} catch (error) {
  concurrentRemovalLockError = error
} finally {
  if (removalLockHolder.child.exitCode === null) releaseLockHolder(removalLockHolder)
  try { await removalLockHolder.done } catch (error) { if (!concurrentRemovalLockError) concurrentRemovalLockError = error }
}
const [concurrentCheckoutResult, concurrentRemovalResult] = await Promise.all([concurrentRemovalCheckout, concurrentMemberRemoval])
if (concurrentRemovalLockError) throw concurrentRemovalLockError
assert.equal(concurrentRemovalResult.error, undefined)
assert.equal(concurrentRemovalResult.value.status, 204)
assert.equal(concurrentCheckoutResult.error, undefined)
assert.equal(concurrentCheckoutResult.value.status, 409)
assert.equal(concurrentCheckoutResult.value.body.error, 'CHECKOUT_REQUEST_TERMINAL')
assert.deepEqual(checkoutRequestState(removalRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${removalFile.id}';`), '0')
assert.deepEqual(checkoutRequestState(removalAdditionalRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${removalAdditionalFile.id}';`), '0')
assert.equal((await requestStatus('/organizations/current', {}, removalForeignLogin.token)).status, 200)
assert.equal(queryTestDatabase(`SELECT disabled_at IS NOT NULL FROM users WHERE id = '${removalUser.id}';`), '0')
assert.equal((await requestStatus(`/files/${secondOrgRemovalFileId}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: secondOrgRemovalLease.checkoutToken }),
}, removalForeignLogin.token)).status, 204)
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${secondOrgRemovalRequestId}';`), 'cancelled\t1')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${secondOrgRemovalFileId}' AND user_id = '${removalUser.id}';`), '0')
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${foreignLeaseRequestId}';`), 'active\t1')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${foreignLeaseFileId}' AND user_id = '${foreignUser.id}';`), '1')

// An acquisition that owns the per-user mutex first must be visible to the
// later removal scan and be terminalized before removal commits.
const acquireFirstUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'acquire-first@example.test', displayName: 'Acquire First', password: 'Acquire first password 123!' }),
}, token)
await request(`/users/${acquireFirstUser.id}/vault-access`, { method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }) }, token)
const acquireFirstLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'acquire-first@example.test', password: 'Acquire first password 123!' }),
})
const acquireFirstFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'acquire-first.sldprt', fileName: 'acquire-first.sldprt', storageRelativePath: 'acquire-first.sldprt' }),
}, token)
const acquireFirstFileHolder = spawnCheckoutCancelLockHolder(acquireFirstFile.id, testOrganizationId, true)
await waitForOutput(acquireFirstFileHolder, 'READY')
const acquireFirstRequestId = randomUUID()
const acquireFirstMutexName = userLifecycleLockName(acquireFirstUser.id)
const acquireFirstRequest = requestStatus(`/files/${acquireFirstFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: acquireFirstRequestId }),
}, acquireFirstLogin.token).then(value => ({ value }), error => ({ error }))
let acquireFirstInterleavingError = null
try {
  await waitForNamedLockFileWait(acquireFirstMutexName)
  assert.equal(userLifecycleLockWaitCount(1000), 0)
  const acquireFirstRemoval = requestStatus(`/users/${acquireFirstUser.id}`, { method: 'DELETE' }, token)
    .then(value => ({ value }), error => ({ error }))
  await waitForUserLifecycleLockWaitCount(acquireFirstMutexName, 1)
  releaseLockHolder(acquireFirstFileHolder)
  const acquireFirstHolderResult = await acquireFirstFileHolder.done
  const [checkoutResult, removalResult] = await Promise.all([acquireFirstRequest, acquireFirstRemoval])
  assert.equal(checkoutResult.error, undefined)
  assert.equal(checkoutResult.value.status, 200)
  assert.equal(removalResult.error, undefined)
  assert.equal(removalResult.value.status, 204)
  assert.ok(acquireFirstHolderResult.stdout.includes('COMMITTED'))
} catch (error) {
  acquireFirstInterleavingError = error
} finally {
  if (acquireFirstFileHolder.child.exitCode === null) {
    releaseLockHolder(acquireFirstFileHolder)
    await acquireFirstFileHolder.done
  }
}
if (acquireFirstInterleavingError) throw acquireFirstInterleavingError
assert.deepEqual(checkoutRequestState(acquireFirstRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${acquireFirstFile.id}';`), '0')

// A removal that owns the mutex first waits for its existing File-first
// cancellation, then denies a stale authenticated acquisition on another file.
const removeFirstUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'remove-first@example.test', displayName: 'Remove First', password: 'Remove first password 123!' }),
}, token)
await request(`/users/${removeFirstUser.id}/vault-access`, { method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }) }, token)
const removeFirstLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'remove-first@example.test', password: 'Remove first password 123!' }),
})
const removeFirstLeasedFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'remove-first-existing.sldprt', fileName: 'remove-first-existing.sldprt', storageRelativePath: 'remove-first-existing.sldprt' }),
}, token)
const removeFirstTargetFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'remove-first-target.sldprt', fileName: 'remove-first-target.sldprt', storageRelativePath: 'remove-first-target.sldprt' }),
}, token)
const removeFirstExistingLease = await request(`/files/${removeFirstLeasedFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, removeFirstLogin.token)
const removeFirstFileHolder = spawnCheckoutCancelLockHolder(removeFirstLeasedFile.id, testOrganizationId)
await waitForOutput(removeFirstFileHolder, 'READY')
const removeFirstMutexName = userLifecycleLockName(removeFirstUser.id)
const removeFirstRemoval = requestStatus(`/users/${removeFirstUser.id}`, { method: 'DELETE' }, token)
  .then(value => ({ value }), error => ({ error }))
let removeFirstInterleavingError = null
try {
  await waitForAdvisoryLockOwner(removeFirstMutexName)
  await waitForNamedLockFileWait(removeFirstMutexName)
  assert.equal(userLifecycleLockWaitCount(1000), 0)
  const deniedStaleCheckout = requestStatus(`/files/${removeFirstTargetFile.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
  }, removeFirstLogin.token).then(value => ({ value }), error => ({ error }))
  await waitForUserLifecycleLockWaitCount(removeFirstMutexName, 1)
  releaseLockHolder(removeFirstFileHolder)
  const removeFirstHolderResult = await removeFirstFileHolder.done
  const [removalResult, checkoutResult] = await Promise.all([removeFirstRemoval, deniedStaleCheckout])
  assert.equal(removalResult.error, undefined)
  assert.equal(removalResult.value.status, 204)
  assert.equal(checkoutResult.error, undefined)
  assert.equal(checkoutResult.value.status, 401)
  assert.equal(checkoutResult.value.body.error, 'UNAUTHENTICATED')
  assert.ok(removeFirstHolderResult.stdout.includes('COMMITTED'))
} catch (error) {
  removeFirstInterleavingError = error
} finally {
  if (removeFirstFileHolder.child.exitCode === null) {
    releaseLockHolder(removeFirstFileHolder)
    await removeFirstFileHolder.done
  }
}
if (removeFirstInterleavingError) throw removeFirstInterleavingError
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id IN ('${removeFirstLeasedFile.id}', '${removeFirstTargetFile.id}');`), '0')
assert.equal(typeof removeFirstExistingLease.checkoutToken, 'string')

// Lifecycle contention fails closed for both checkout contracts and removal;
// the same durable request ID is retryable after the held mutex is released.
const busyLifecycleUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'busy-lifecycle@example.test', displayName: 'Busy Lifecycle', password: 'Busy lifecycle password 123!' }),
}, token)
await request(`/users/${busyLifecycleUser.id}/vault-access`, { method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }) }, token)
const busyLifecycleLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'busy-lifecycle@example.test', password: 'Busy lifecycle password 123!' }),
})
const busyLifecycleDurableFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'busy-lifecycle-durable.sldprt', fileName: 'busy-lifecycle-durable.sldprt', storageRelativePath: 'busy-lifecycle-durable.sldprt' }),
}, token)
const busyLifecycleLegacyFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'busy-lifecycle-legacy.sldprt', fileName: 'busy-lifecycle-legacy.sldprt', storageRelativePath: 'busy-lifecycle-legacy.sldprt' }),
}, token)
const busyLifecycleRequestId = randomUUID()
const busyLifecycleLockName = userLifecycleLockName(busyLifecycleUser.id)
const busyLifecycleHolder = spawnUserLifecycleLockHolder(busyLifecycleUser.id)
await waitForOutput(busyLifecycleHolder, 'READY')
let busyLifecycleResults
let busyLifecycleHolderResult
try {
  await waitForAdvisoryLockOwner(busyLifecycleLockName)
  const requests = [
    requestStatus(`/files/${busyLifecycleDurableFile.id}/checkout`, {
      method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: busyLifecycleRequestId }),
    }, busyLifecycleLogin.token),
    requestStatus(`/files/${busyLifecycleLegacyFile.id}/checkout`, {
      method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
    }, busyLifecycleLogin.token),
    requestStatus(`/users/${busyLifecycleUser.id}`, { method: 'DELETE' }, token),
  ]
  busyLifecycleResults = await Promise.all(requests)
} finally {
  releaseLockHolder(busyLifecycleHolder)
  busyLifecycleHolderResult = await busyLifecycleHolder.done
}
assert.ok(busyLifecycleHolderResult.stdout.includes('RELEASED'))
for (const result of busyLifecycleResults) {
  assert.equal(result.status, 503)
  assert.equal(result.body.error, 'CHECKOUT_BUSY')
}
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id IN ('${busyLifecycleDurableFile.id}', '${busyLifecycleLegacyFile.id}');`), '0')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkout_acquisition_requests WHERE organization_id = '${testOrganizationId}' AND request_id = '${busyLifecycleRequestId}';`), '0')
const retriedBusyDurableCheckout = await request(`/files/${busyLifecycleDurableFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: busyLifecycleRequestId }),
}, busyLifecycleLogin.token)
const retriedBusyLegacyCheckout = await request(`/files/${busyLifecycleLegacyFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, busyLifecycleLogin.token)
assert.equal(retriedBusyDurableCheckout.replayed, false)
assert.equal(typeof retriedBusyLegacyCheckout.checkoutToken, 'string')
const retriedBusyUserRemoval = await requestStatus(`/users/${busyLifecycleUser.id}`, { method: 'DELETE' }, token)
assert.equal(retriedBusyUserRemoval.status, 204)
assert.deepEqual(checkoutRequestState(busyLifecycleRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id IN ('${busyLifecycleDurableFile.id}', '${busyLifecycleLegacyFile.id}');`), '0')
assert.equal(queryTestDatabase(`SELECT disabled_at IS NOT NULL FROM users WHERE id = '${busyLifecycleUser.id}';`), '1')

const selfRemovalUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'self-removal@example.test', displayName: 'Self Removal', password: 'Self removal password 123!' }),
}, token)
await request(`/users/${selfRemovalUser.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }),
}, token)
const selfRemovalLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'self-removal@example.test', password: 'Self removal password 123!' }),
})
const selfRemovalRequestId = randomUUID()
await request(`/files/${removalFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: selfRemovalRequestId }),
}, selfRemovalLogin.token)
const selfRemovalForeignFileId = randomUUID()
const selfRemovalForeignRequestId = randomUUID()
const selfRemovalForeignLeaseHash = 'd'.repeat(64)
queryTestDatabase(`
  INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${foreignOrgId}', '${selfRemovalUser.id}', 'member');
  INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path) VALUES ('${selfRemovalForeignFileId}', '${foreignOrgId}', '${foreignLeaseVaultId}', 'foreign/self-delete.sldprt', 'self-delete.sldprt', 'foreign/self-delete.sldprt');
  INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES ('${selfRemovalForeignFileId}', '${selfRemovalUser.id}', '${selfRemovalForeignLeaseHash}', 'C:/foreign-workspace', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR));
  INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES ('${foreignOrgId}', '${selfRemovalForeignRequestId}', '${selfRemovalUser.id}', '${selfRemovalForeignFileId}', '${foreignLeaseVaultId}', 'C:/foreign-workspace', '${'c'.repeat(64)}', '${selfRemovalForeignLeaseHash}', 'self-delete-foreign-ciphertext', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR), 'active');
`)
assert.equal((await requestStatus('/account', { method: 'DELETE' }, selfRemovalLogin.token)).status, 204)
assert.deepEqual(checkoutRequestState(selfRemovalRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${selfRemovalForeignRequestId}';`), 'cancelled\t0')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${selfRemovalForeignFileId}';`), '0')

// Non-migration SQL failures in direct ledger updates must fail closed and
// roll back the matching legacy checkout mutation.
const updateFailureFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'ledger-update-failure.sldprt', fileName: 'ledger-update-failure.sldprt', storageRelativePath: 'ledger-update-failure.sldprt' }),
}, token)
const updateFailureRequestId = randomUUID()
const updateFailureLease = await request(`/files/${updateFailureFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: updateFailureRequestId }),
}, token)
queryTestDatabase("CREATE TRIGGER test_fail_checkout_ledger_update BEFORE UPDATE ON checkout_acquisition_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected update failure';")
try {
  const renewFailure = await requestStatus(`/files/${updateFailureFile.id}/checkout/renew`, {
    method: 'POST', body: JSON.stringify({ checkoutToken: updateFailureLease.checkoutToken }),
  }, token)
  assert.equal(renewFailure.status, 500)
  const cancelFailure = await requestStatus(`/files/${updateFailureFile.id}/checkout/cancel`, {
    method: 'POST', body: JSON.stringify({ checkoutToken: updateFailureLease.checkoutToken }),
  }, token)
  assert.equal(cancelFailure.status, 500)
  assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${testOrganizationId}' AND request_id = '${updateFailureRequestId}';`), 'active\t1')
  assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${updateFailureFile.id}';`), '1')
} finally {
  queryTestDatabase('DROP TRIGGER IF EXISTS test_fail_checkout_ledger_update;')
}
assert.equal((await requestStatus(`/files/${updateFailureFile.id}/checkout/cancel`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: updateFailureLease.checkoutToken }),
}, token)).status, 204)
assert.deepEqual(checkoutRequestState(updateFailureRequestId), { status: 'cancelled', tokenPurged: true })

// Membership removal must roll back lease, ledger, membership, and session
// changes together if terminalizing the durable request fails.
const failedRemovalUser = await request('/users', {
  method: 'POST', body: JSON.stringify({ email: 'failed-removal@example.test', displayName: 'Failed Removal', password: 'Failed removal password 123!' }),
}, token)
await request(`/users/${failedRemovalUser.id}/vault-access`, {
  method: 'PUT', body: JSON.stringify({ vaultIds: [vault.id] }),
}, token)
const failedRemovalLogin = await request('/auth/login', {
  method: 'POST', body: JSON.stringify({ email: 'failed-removal@example.test', password: 'Failed removal password 123!' }),
})
const failedRemovalFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'failed-removal.sldprt', fileName: 'failed-removal.sldprt', storageRelativePath: 'failed-removal.sldprt' }),
}, token)
const failedRemovalRequestId = randomUUID()
await request(`/files/${failedRemovalFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace, requestId: failedRemovalRequestId }),
}, failedRemovalLogin.token)
const failedRemovalForeignFileId = randomUUID()
const failedRemovalForeignRequestId = randomUUID()
const failedRemovalForeignLeaseHash = '9'.repeat(64)
queryTestDatabase(`
  INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${foreignOrgId}', '${failedRemovalUser.id}', 'member');
  INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path) VALUES ('${failedRemovalForeignFileId}', '${foreignOrgId}', '${foreignLeaseVaultId}', 'foreign/failed-removal.sldprt', 'failed-removal.sldprt', 'foreign/failed-removal.sldprt');
  INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES ('${failedRemovalForeignFileId}', '${failedRemovalUser.id}', '${failedRemovalForeignLeaseHash}', 'C:/foreign-workspace', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR));
  INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES ('${foreignOrgId}', '${failedRemovalForeignRequestId}', '${failedRemovalUser.id}', '${failedRemovalForeignFileId}', '${foreignLeaseVaultId}', 'C:/foreign-workspace', '${'9'.repeat(64)}', '${failedRemovalForeignLeaseHash}', 'failed-removal-foreign-ciphertext', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR), 'active');
`)
const failedRemovalSnapshotSql = `
  SELECT 'checkout', COALESCE(GROUP_CONCAT(CONCAT_WS(':', file_id, user_id, token_hash, client_working_path, DATE_FORMAT(expires_at, '%Y-%m-%d %H:%i:%s.%f')) ORDER BY file_id SEPARATOR '|'), '') FROM checkouts WHERE user_id = '${failedRemovalUser.id}';
  SELECT 'ledger', COALESCE(GROUP_CONCAT(CONCAT_WS(':', organization_id, request_id, file_id, status, token_hash, token_ciphertext, DATE_FORMAT(expires_at, '%Y-%m-%d %H:%i:%s.%f')) ORDER BY organization_id, request_id SEPARATOR '|'), '') FROM checkout_acquisition_requests WHERE user_id = '${failedRemovalUser.id}';
  SELECT 'membership', COALESCE(GROUP_CONCAT(CONCAT_WS(':', organization_id, user_id, role) ORDER BY organization_id SEPARATOR '|'), '') FROM organization_memberships WHERE user_id = '${failedRemovalUser.id}';
  SELECT 'session', COALESCE(GROUP_CONCAT(CONCAT_WS(':', organization_id, token_hash, DATE_FORMAT(expires_at, '%Y-%m-%d %H:%i:%s.%f')) ORDER BY organization_id, token_hash SEPARATOR '|'), '') FROM sessions WHERE user_id = '${failedRemovalUser.id}';
  SELECT 'disabled_at', COALESCE(DATE_FORMAT(disabled_at, '%Y-%m-%d %H:%i:%s.%f'), 'NULL') FROM users WHERE id = '${failedRemovalUser.id}';
`
const failedRemovalStateBefore = queryTestDatabase(failedRemovalSnapshotSql)
queryTestDatabase("CREATE TRIGGER test_fail_removal_event_insert BEFORE INSERT ON events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected removal event failure';")
try {
  const failedMemberRemoval = await requestStatus(`/users/${failedRemovalUser.id}`, { method: 'DELETE' }, token)
  assert.equal(failedMemberRemoval.status, 500)
  assert.equal(failedMemberRemoval.body.error, 'INTERNAL_ERROR')
  assert.equal(queryTestDatabase(failedRemovalSnapshotSql), failedRemovalStateBefore)
} finally {
  queryTestDatabase('DROP TRIGGER IF EXISTS test_fail_removal_event_insert;')
}
const retriedMemberRemoval = await requestStatus(`/users/${failedRemovalUser.id}`, { method: 'DELETE' }, token)
assert.equal(retriedMemberRemoval.status, 204)
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${failedRemovalFile.id}';`), '0')
assert.deepEqual(checkoutRequestState(failedRemovalRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM organization_memberships WHERE organization_id = '${testOrganizationId}' AND user_id = '${failedRemovalUser.id}';`), '0')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM sessions WHERE user_id = '${failedRemovalUser.id}';`), '0')
assert.equal(queryTestDatabase(`SELECT disabled_at IS NOT NULL FROM users WHERE id = '${failedRemovalUser.id}';`), '0')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${failedRemovalForeignFileId}' AND user_id = '${failedRemovalUser.id}';`), '1')
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${failedRemovalForeignRequestId}';`), 'active\t1')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM organization_memberships WHERE organization_id = '${foreignOrgId}' AND user_id = '${failedRemovalUser.id}';`), '1')

// The bounded maintenance candidate scans are part of checkout recovery's
// availability contract: scan failures must become a retryable 503 before any
// lease, request-ledger, or event mutation.
const candidateScanFailurePath = `candidate-scan-failure-${randomUUID()}.sldprt`
const candidateScanFailureFile = await request('/files/import', {
  method: 'POST',
  body: JSON.stringify({
    vaultId: vault.id,
    canonicalPath: candidateScanFailurePath,
    fileName: candidateScanFailurePath,
    storageRelativePath: candidateScanFailurePath,
  }),
}, token)
const candidateScanFailureRequestId = randomUUID()
const candidateScanFailureSnapshot = () => queryTestDatabase([
  `SELECT CONCAT('file|', current_revision, '|', storage_relative_path) FROM files WHERE id = '${candidateScanFailureFile.id}';`,
  `SELECT CONCAT('revisions|', COUNT(*)) FROM file_revisions WHERE file_id = '${candidateScanFailureFile.id}';`,
  `SELECT CONCAT('leases|', COUNT(*)) FROM checkouts WHERE file_id = '${candidateScanFailureFile.id}';`,
  `SELECT CONCAT('requests|', COUNT(*)) FROM checkout_acquisition_requests WHERE file_id = '${candidateScanFailureFile.id}';`,
  `SELECT CONCAT('events|', COUNT(*)) FROM events WHERE aggregate_id = '${candidateScanFailureFile.id}';`,
].join(' '))
const candidateScanFailureBefore = candidateScanFailureSnapshot()
const hiddenCheckoutsTable = `checkouts_fault_${randomUUID().replaceAll('-', '')}`
queryTestDatabaseAsRoot(`RENAME TABLE checkouts TO \`${hiddenCheckoutsTable}\`;`)
let candidateScanFailureResponse
try {
  candidateScanFailureResponse = await requestStatus(`/files/${candidateScanFailureFile.id}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: workspace, requestId: candidateScanFailureRequestId }),
  }, token)
} finally {
  queryTestDatabaseAsRoot(`RENAME TABLE \`${hiddenCheckoutsTable}\` TO checkouts;`)
}
assert.equal(candidateScanFailureResponse.status, 503)
assert.equal(candidateScanFailureResponse.body.error, 'CHECKOUT_RECOVERY_UNAVAILABLE')
assert.equal(candidateScanFailureSnapshot(), candidateScanFailureBefore)

// An exact missing-table 1146 remains compatible with legacy clients that do
// not send request IDs; both direct ledger updates may be a no-op on old schema.
const legacyCompatibilityFile = await request('/files/import', {
  method: 'POST', body: JSON.stringify({ vaultId: vault.id, canonicalPath: 'legacy-ledger-compatibility.sldprt', fileName: 'legacy-ledger-compatibility.sldprt', storageRelativePath: 'legacy-ledger-compatibility.sldprt' }),
}, token)
const legacyLease = await request(`/files/${legacyCompatibilityFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
queryTestDatabase('DROP TABLE checkout_acquisition_requests;')
const legacyRenewed = await request(`/files/${legacyCompatibilityFile.id}/checkout/renew`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: legacyLease.checkoutToken }),
}, token)
assert.equal(typeof legacyRenewed.expiresAt, 'string')
queryTestDatabase(`UPDATE checkouts SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${legacyCompatibilityFile.id}';`)
const legacyReacquired = await request(`/files/${legacyCompatibilityFile.id}/checkout`, {
  method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
}, token)
assert.notEqual(legacyReacquired.checkoutToken, legacyLease.checkoutToken)


}
