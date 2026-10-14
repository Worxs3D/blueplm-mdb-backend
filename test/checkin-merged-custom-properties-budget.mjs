import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'

const MAX_CUSTOM_PROPERTIES_BYTES = 200_000
const JSON_ENCODED_OBJECT_WRAPPER_BYTES = 11
const JSON_ENCODED_SUPPLEMENTARY_CODE_POINT_BYTES = 12
const SUPPLEMENTARY_CODE_POINTS_AT_LIMIT = Math.floor(
  (MAX_CUSTOM_PROPERTIES_BYTES - JSON_ENCODED_OBJECT_WRAPPER_BYTES) / JSON_ENCODED_SUPPLEMENTARY_CODE_POINT_BYTES,
)
const ASCII_FILLER_BYTES_AT_LIMIT = MAX_CUSTOM_PROPERTIES_BYTES
  - JSON_ENCODED_OBJECT_WRAPPER_BYTES
  - SUPPLEMENTARY_CODE_POINTS_AT_LIMIT * JSON_ENCODED_SUPPLEMENTARY_CODE_POINT_BYTES
const OVER_LIMIT_EXTRA_BYTES = 1

export async function runCheckinReplayShapeTest({ request, token, vault, workspace }) {
  const file = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'replay-shape/replay-shape.sldprt',
      fileName: 'replay-shape.sldprt',
      partNumber: 'PN-REPLAY-SHAPE',
      storageRelativePath: 'replay-shape.sldprt',
    }),
  }, token)
  const checkout = await request(`/files/${file.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
  }, token)
  const checkinRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'replay-shape/replay-shape.sldprt',
    fileName: 'replay-shape.sldprt',
    checkoutToken: checkout.checkoutToken,
    pendingMetadata: { config_tabs: {} },
  }
  const initial = await request(`/files/${file.id}/checkin`, {
    method: 'POST', body: JSON.stringify(checkinRequest),
  }, token)
  assert.deepEqual(initial.file.custom_properties._config_tabs, {})
  const replay = await request(`/files/${file.id}/checkin`, {
    method: 'POST', body: JSON.stringify(checkinRequest),
  }, token)
  assert.equal(replay.replayed, true)
  assert.deepEqual(replay.file.custom_properties._config_tabs, {})
}

export async function runCheckinDamagedJournalReplayTests({ request, requestStatus, token, vault, workspace }) {
  const file = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'damaged-journal/replay.sldprt',
      fileName: 'replay.sldprt',
      partNumber: 'PN-DAMAGED-JOURNAL',
      storageRelativePath: 'damaged-journal/replay.sldprt',
    }),
  }, token)
  const checkout = await request(`/files/${file.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
  }, token)
  const checkinRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'damaged-journal/replay.sldprt',
    fileName: 'replay.sldprt',
    checkoutToken: checkout.checkoutToken,
    pendingMetadata: { config_tabs: { Recovery: 'persisted' } },
  }
  const original = await request(`/files/${file.id}/checkin`, {
    method: 'POST', body: JSON.stringify(checkinRequest),
  }, token)
  const originalRevision = original.revision
  const query = (sql) => execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '--batch', '--raw', '--skip-column-names', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe', encoding: 'utf8' }).trim()
  const row = `request_id = '${checkinRequest.requestId}'`
  const originalResponse = query(`SELECT TO_BASE64(response) FROM file_checkin_requests WHERE ${row}`).replace(/\s+/gu, '')
  assert.match(originalResponse, /^[A-Za-z0-9+/]+={0,2}$/)

  try {
    for (const damagedResponse of ['[]', 'not-json']) {
      const setDamaged = damagedResponse === 'not-json'
        ? `SET SESSION check_constraint_checks = OFF; UPDATE file_checkin_requests SET response = '${damagedResponse}' WHERE ${row}`
        : `UPDATE file_checkin_requests SET response = '${damagedResponse}' WHERE ${row}`
      query(setDamaged)

      const replay = await requestStatus(`/files/${file.id}/checkin`, {
        method: 'POST', body: JSON.stringify(checkinRequest),
      }, token)
      assert.equal(replay.status, 503)
      assert.equal(replay.body.error, 'CHECKIN_RECOVERY_UNAVAILABLE')
      assert.equal(query(`SELECT response FROM file_checkin_requests WHERE ${row}`), damagedResponse)
      const currentFile = (await request(`/vaults/${vault.id}/files`, {}, token)).files.find((item) => item.id === file.id)
      assert.equal(currentFile.currentRevision, originalRevision)
      assert.deepEqual(currentFile.customProperties, original.file.custom_properties)
    }
  } finally {
    query(`UPDATE file_checkin_requests SET response = FROM_BASE64('${originalResponse}') WHERE ${row}`)
  }

  const restored = await request(`/files/${file.id}/checkin`, {
    method: 'POST', body: JSON.stringify(checkinRequest),
  }, token)
  assert.equal(restored.replayed, true)
  assert.equal(restored.revision, originalRevision)
  assert.deepEqual(restored.file.custom_properties._config_tabs, { Recovery: 'persisted' })
}

export async function runCheckinMergedCustomPropertiesBudgetTests({ request, requestStatus, token, vault, workspace }) {
  const file = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'checkin-properties-budget/budget.sldprt',
      fileName: 'budget.sldprt',
      partNumber: 'PN-PROPERTIES-BUDGET',
      storageRelativePath: 'budget.sldprt',
    }),
  }, token)
  const checkout = async () => request(`/files/${file.id}/checkout`, {
    method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
  }, token)
  const checkinStatus = (body, fileId = file.id) => requestStatus(`/files/${fileId}/checkin`, {
    method: 'POST', body: JSON.stringify(body),
  }, token)
  const latestFile = async () => (await request(`/vaults/${vault.id}/files`, {}, token)).files.find((item) => item.id === file.id)

  // PHP json_encode escapes each supplementary code point as a 12-byte surrogate pair.
  const exactBoundaryProperties = {
    text: `${'🌟'.repeat(SUPPLEMENTARY_CODE_POINTS_AT_LIMIT)}${'a'.repeat(ASCII_FILLER_BYTES_AT_LIMIT)}`,
  }
  assert.equal(
    JSON_ENCODED_OBJECT_WRAPPER_BYTES
      + (SUPPLEMENTARY_CODE_POINTS_AT_LIMIT * JSON_ENCODED_SUPPLEMENTARY_CODE_POINT_BYTES)
      + ASCII_FILLER_BYTES_AT_LIMIT,
    MAX_CUSTOM_PROPERTIES_BYTES,
  )
  const overBoundaryProperties = {
    text: `${'🌟'.repeat(SUPPLEMENTARY_CODE_POINTS_AT_LIMIT)}${'a'.repeat(ASCII_FILLER_BYTES_AT_LIMIT + OVER_LIMIT_EXTRA_BYTES)}`,
  }

  const firstCheckout = await checkout()
  const boundaryRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'checkin-properties-budget/budget.sldprt',
    fileName: 'budget.sldprt',
    checkoutToken: firstCheckout.checkoutToken,
    committedCustomProperties: overBoundaryProperties,
  }
  const overBoundaryResponse = await checkinStatus(boundaryRequest)
  assert.equal(overBoundaryResponse.status, 400)
  assert.equal(overBoundaryResponse.body.error, 'INVALID_REQUEST')

  const boundaryResult = await request(`/files/${file.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({ ...boundaryRequest, committedCustomProperties: exactBoundaryProperties }),
  }, token)
  assert.equal(boundaryResult.replayed, false)
  assert.deepEqual(boundaryResult.file.custom_properties, exactBoundaryProperties)

  const incrementalFile = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: 'checkin-properties-budget/incremental.sldprt',
      fileName: 'incremental.sldprt',
      partNumber: 'PN-INCREMENTAL-BUDGET',
      storageRelativePath: 'incremental.sldprt',
    }),
  }, token)
  let incrementalLease
  let failedIncrementalRequest
  for (let round = 0; round < 5; round++) {
    incrementalLease = await request(`/files/${incrementalFile.id}/checkout`, {
      method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
    }, token)
    const incrementRequest = {
      mode: 'metadata-only',
      requestId: randomUUID(),
      canonicalPath: 'checkin-properties-budget/incremental.sldprt',
      fileName: 'incremental.sldprt',
      checkoutToken: incrementalLease.checkoutToken,
      pendingMetadata: {
        config_tabs: Object.fromEntries(Array.from({ length: 40 }, (_, index) => [
          `round-${round}-entry-${index}`,
          'x'.repeat(1_000),
        ])),
      },
    }
    const response = await checkinStatus(incrementRequest, incrementalFile.id)
    if (round < 4) {
      assert.equal(response.status, 200, `incremental round ${round}: ${JSON.stringify(response.body)}`)
      assert.equal(response.body.replayed, false)
    } else {
      assert.equal(response.status, 400, `incremental over-budget round ${round}: ${JSON.stringify(response.body)}`)
      assert.equal(response.body.error, 'INVALID_REQUEST')
      failedIncrementalRequest = incrementRequest
    }
  }
  const incrementalStored = (await request(`/vaults/${vault.id}/files`, {}, token)).files.find((item) => item.id === incrementalFile.id)
  assert.equal(Object.keys(incrementalStored.customProperties._config_tabs).length, 160)
  const incrementalRetry = await request(`/files/${incrementalFile.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({
      ...failedIncrementalRequest,
      pendingMetadata: { config_tabs: { Recovery: 'small' } },
    }),
  }, token)
  assert.equal(incrementalRetry.replayed, false)
  assert.equal(Object.keys(incrementalRetry.file.custom_properties._config_tabs).length, 161)

  const storedProperties = boundaryResult.file.custom_properties
  const pendingCheckout = await checkout()
  const pendingRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'checkin-properties-budget/budget.sldprt',
    fileName: 'budget.sldprt',
    checkoutToken: pendingCheckout.checkoutToken,
    pendingMetadata: {
      config_tabs: { NewTab: 'x' },
      config_descriptions: { NewDescription: 'y' },
    },
  }
  const pendingOverBudget = await checkinStatus(pendingRequest)
  assert.equal(pendingOverBudget.status, 400)
  assert.equal(pendingOverBudget.body.error, 'INVALID_REQUEST')
  assert.deepEqual((await latestFile()).customProperties, storedProperties)

  const pendingRetry = await request(`/files/${file.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({
      ...pendingRequest,
      committedCustomProperties: { category: 'replacement' },
    }),
  }, token)
  assert.equal(pendingRetry.replayed, false)
  assert.deepEqual(pendingRetry.file.custom_properties, {
    category: 'replacement',
    _config_tabs: { NewTab: 'x' },
    _config_descriptions: { NewDescription: 'y' },
  })

  const replacementCheckout = await checkout()
  const replacementRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'checkin-properties-budget/budget.sldprt',
    fileName: 'budget.sldprt',
    checkoutToken: replacementCheckout.checkoutToken,
    committedCustomProperties: { replacement: 'a'.repeat(199_000) },
    pendingMetadata: {
      config_tabs: { NewTab: 'b'.repeat(1_000) },
      config_descriptions: { NewDescription: 'c'.repeat(1_000) },
    },
  }
  const replacementOverBudget = await checkinStatus(replacementRequest)
  assert.equal(replacementOverBudget.status, 400)
  assert.equal(replacementOverBudget.body.error, 'INVALID_REQUEST')
  const beforeRetry = (await latestFile()).customProperties
  assert.deepEqual(beforeRetry, pendingRetry.file.custom_properties)

  const replacementRetry = await request(`/files/${file.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({
      ...replacementRequest,
      committedCustomProperties: { replacement: 'small' },
      pendingMetadata: {
        config_tabs: { NewTab: 'small' },
        config_descriptions: { NewDescription: 'small' },
      },
    }),
  }, token)
  assert.equal(replacementRetry.replayed, false)
  assert.deepEqual(replacementRetry.file.custom_properties, {
    replacement: 'small',
    _config_tabs: { NewTab: 'small' },
    _config_descriptions: { NewDescription: 'small' },
  })

  const legacyPropertiesSql = `UPDATE files SET custom_properties = JSON_OBJECT('legacy', REPEAT('x', ${MAX_CUSTOM_PROPERTIES_BYTES + OVER_LIMIT_EXTRA_BYTES})) WHERE id = '${file.id}'`
  execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', legacyPropertiesSql,
  ], { cwd: process.cwd(), stdio: 'pipe' })
  const oversizedLegacyProperties = (await latestFile()).customProperties
  const legacyCheckout = await checkout()
  const legacyRequest = {
    mode: 'metadata-only',
    requestId: randomUUID(),
    canonicalPath: 'checkin-properties-budget/budget.sldprt',
    fileName: 'budget.sldprt',
    checkoutToken: legacyCheckout.checkoutToken,
  }
  const legacyOverBudget = await checkinStatus(legacyRequest)
  assert.equal(legacyOverBudget.status, 400)
  assert.equal(legacyOverBudget.body.error, 'INVALID_REQUEST')
  assert.deepEqual((await latestFile()).customProperties, oversizedLegacyProperties)
  const legacyShrink = await request(`/files/${file.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({ ...legacyRequest, committedCustomProperties: { legacy: 'explicitly-shrunk' } }),
  }, token)
  assert.equal(legacyShrink.replayed, false)
  assert.deepEqual(legacyShrink.file.custom_properties, { legacy: 'explicitly-shrunk' })

}
