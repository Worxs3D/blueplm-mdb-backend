import assert from 'node:assert/strict'
import { execFileSync, spawn } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'

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

function spawnCheckoutCancelLockHolder(fileId, organizationId) {
  const child = spawn('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'api',
    'php', 'test/checkout-cancel-lock-holder.php', fileId, organizationId,
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
      else reject(new Error(`Checkout lock holder exited ${code}: ${stderr}`))
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

async function waitForOutput(worker, expected, timeoutMs = 5000) {
  const started = Date.now()
  while (!worker.output().includes(expected)) {
    if (Date.now() - started >= timeoutMs) throw new Error(`Timed out waiting for worker output ${expected}.`)
    await new Promise(resolve => setTimeout(resolve, 25))
  }
}

function checkoutRequestLockName(organizationId, requestId) {
  const databaseName = queryTestDatabase('SELECT DATABASE();')
  const digest = createHash('sha256')
    .update([databaseName, 'checkout', organizationId.toLowerCase(), requestId.toLowerCase()].join('\0'))
    .digest('hex')
    .slice(0, 56)
  return `blueplm:${digest}`
}

function checkoutRequestLockWaitCount(lockName) {
  const sql = `SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id WHERE t.trx_mysql_thread_id = IS_USED_LOCK('${lockName}');`
  return Number(execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-uroot', '-proot-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim())
}

async function waitForCheckoutRequestLockWait(lockName, timeoutMs = 5000) {
  const started = Date.now()
  while (checkoutRequestLockWaitCount(lockName) === 0) {
    if (Date.now() - started >= timeoutMs) throw new Error('Checkout request did not reach its expected InnoDB file-lock wait.')
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
checkinLockHolder.child.stdin.write('continue\n')
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
const renewedCheckout = await request(`/files/${duplicatePart.id}/checkout/renew`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: checkoutAcquisitionA.checkoutToken }),
}, token)
const renewedUppercaseFileId = await request(`/files/${duplicatePart.id.toUpperCase()}/checkout/renew`, {
  method: 'POST', body: JSON.stringify({ checkoutToken: checkoutAcquisitionA.checkoutToken }),
}, token)
assert.equal(typeof renewedUppercaseFileId.expiresAt, 'string')
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
requestLockHolder.child.stdin.write('continue\n')
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
  await waitForCheckoutRequestLockWait(concurrentAcquireLockName)
} catch (error) {
  lockWaitError = error
} finally {
  cancelLockHolder.child.stdin.write('continue\n')
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
const secondOrgRemovalLeaseHash = 'e'.repeat(64)
queryTestDatabase(`
  INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${foreignOrgId}', '${removalUser.id}', 'member');
  INSERT INTO files (id, organization_id, vault_id, canonical_path, file_name, storage_relative_path) VALUES ('${secondOrgRemovalFileId}', '${foreignOrgId}', '${foreignLeaseVaultId}', 'foreign/same-user.sldprt', 'same-user.sldprt', 'foreign/same-user.sldprt');
  INSERT INTO checkouts (file_id, user_id, token_hash, client_working_path, expires_at) VALUES ('${secondOrgRemovalFileId}', '${removalUser.id}', '${secondOrgRemovalLeaseHash}', 'C:/foreign-workspace', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR));
  INSERT INTO checkout_acquisition_requests (organization_id, request_id, user_id, file_id, vault_id, client_working_path, request_fingerprint, token_hash, token_ciphertext, expires_at, status) VALUES ('${foreignOrgId}', '${secondOrgRemovalRequestId}', '${removalUser.id}', '${secondOrgRemovalFileId}', '${foreignLeaseVaultId}', 'C:/foreign-workspace', '${'b'.repeat(64)}', '${secondOrgRemovalLeaseHash}', 'same-user-foreign-org-ciphertext', DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 HOUR), 'active');
`)
await request(`/users/${removalUser.id}`, { method: 'DELETE' }, token)
assert.deepEqual(checkoutRequestState(removalRequestId), { status: 'cancelled', tokenPurged: true })
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${removalFile.id}';`), '0')
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${secondOrgRemovalRequestId}';`), 'active\t1')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${secondOrgRemovalFileId}' AND user_id = '${removalUser.id}';`), '1')
assert.equal(queryTestDatabase(`SELECT status, token_ciphertext IS NOT NULL FROM checkout_acquisition_requests WHERE organization_id = '${foreignOrgId}' AND request_id = '${foreignLeaseRequestId}';`), 'active\t1')
assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${foreignLeaseFileId}' AND user_id = '${foreignUser.id}';`), '1')

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
