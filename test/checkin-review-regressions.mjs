import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'

function queryTestDatabase(sql) {
  return execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim()
}

/** @param {Record<string, Function>} context */
export async function runCheckinReviewRegressionTests(context) {
  const { request, requestStatus, imported, vault, workspace, token } = context
  const fileId = imported.id
  const failures = []
  async function scenario(name, run) {
    try {
      await run()
    } catch (error) {
      failures.push(`${name}: ${error instanceof Error ? error.message : String(error)}`)
    }
  }
  const baseRequest = storageRelativePath => ({
    mode: 'content',
    requestId: randomUUID(),
    canonicalPath: 'checkin-path-validation.sldprt',
    fileName: 'checkin-path-validation.sldprt',
    checkoutToken: 'invalid-checkout-token',
    storageRelativePath,
    contentHash: 'a'.repeat(64),
    sizeBytes: 1,
  })

  await scenario('storage path normalization and limit', async () => {
    const invalidPaths = []
    for (const path of ['.', '..', 'dir/.', 'dir/..', 'dir//file', 'dir/']) {
      const response = await requestStatus(`/files/${fileId}/checkin`, {
        method: 'POST', body: JSON.stringify(baseRequest(path)),
      }, token)
      if (response.status !== 400 || response.body.error !== 'INVALID_REQUEST') invalidPaths.push(`${JSON.stringify(path)} => ${response.status} ${response.body.error}`)
    }
    const tooLong = await requestStatus(`/files/${fileId}/checkin`, {
      method: 'POST', body: JSON.stringify(baseRequest('a'.repeat(2049))),
    }, token)
    if (tooLong.status !== 400 || tooLong.body.error !== 'INVALID_REQUEST') invalidPaths.push(`2049 bytes => ${tooLong.status} ${tooLong.body.error}`)
    const atLimit = await requestStatus(`/files/${fileId}/checkin`, {
      method: 'POST', body: JSON.stringify(baseRequest('a'.repeat(2048))),
    }, token)
    if (atLimit.status !== 409 || atLimit.body.error !== 'INVALID_CHECKOUT') invalidPaths.push(`2048 bytes => ${atLimit.status} ${atLimit.body.error}`)
    const backslashes = await requestStatus(`/files/${fileId}/checkin`, {
      method: 'POST', body: JSON.stringify(baseRequest('parts\\windows.sldprt')),
    }, token)
    if (backslashes.status !== 409 || backslashes.body.error !== 'INVALID_CHECKOUT') invalidPaths.push(`backslash path => ${backslashes.status} ${backslashes.body.error}`)
    assert.deepEqual(invalidPaths, [])
  })

  await scenario('initial import and rescan revision metadata', async () => {
    const importPath = `review-metadata-${randomUUID()}.sldprt`
    const partNumber = `PN-REVIEW-${randomUUID()}`
    const importedFile = await request('/files/import', {
      method: 'POST',
      body: JSON.stringify({
        vaultId: vault.id,
        canonicalPath: importPath,
        fileName: importPath,
        storageRelativePath: importPath,
        partNumber,
      }),
    }, token)
    assert.equal(importedFile.created, true)
    let revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
    assert.equal(revisions[0].revisionNumber, 1)
    const metadataFailures = []
    if (revisions[0].partNumber !== partNumber) metadataFailures.push(`initial revision partNumber was ${String(revisions[0].partNumber)}`)

    queryTestDatabase(`UPDATE file_revisions SET part_number = NULL WHERE file_id = '${importedFile.id}' AND revision_number = 1;`)
    const rescanned = await request('/files/import', {
      method: 'POST',
      body: JSON.stringify({
        vaultId: vault.id,
        canonicalPath: importPath,
        fileName: importPath,
        storageRelativePath: importPath,
        partNumber,
      }),
    }, token)
    assert.equal(rescanned.created, false)
    revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
    if (revisions[0].partNumber !== partNumber) metadataFailures.push(`rescanned revision partNumber was ${String(revisions[0].partNumber)}`)

    const nextPartNumber = `PN-REVIEW-RESCAN-${randomUUID()}`
    await request('/files/import', {
      method: 'POST',
      body: JSON.stringify({
        vaultId: vault.id,
        canonicalPath: importPath,
        fileName: importPath,
        storageRelativePath: importPath,
        partNumber: nextPartNumber,
      }),
    }, token)
    revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
    if (revisions[0].partNumber !== partNumber) metadataFailures.push(`rescan replaced existing revision partNumber with ${String(revisions[0].partNumber)}`)
    assert.deepEqual(metadataFailures, [])
  })

  const objectPath = `review-object-shape-${randomUUID()}.sldprt`
  const emptyPath = `review-empty-object-${randomUUID()}.sldprt`
  const objectFile = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({ vaultId: vault.id, canonicalPath: objectPath, fileName: objectPath, storageRelativePath: objectPath }),
  }, token)
  const emptyFile = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({ vaultId: vault.id, canonicalPath: emptyPath, fileName: emptyPath, storageRelativePath: emptyPath }),
  }, token)
  const objectProperties = `JSON_OBJECT('_config_tabs', JSON_OBJECT(), '_config_descriptions', JSON_OBJECT())`
  queryTestDatabase(`UPDATE files SET custom_properties = ${objectProperties} WHERE id = '${objectFile.id}'; UPDATE file_revisions SET custom_properties = ${objectProperties} WHERE file_id = '${objectFile.id}' AND revision_number = 1; UPDATE files SET custom_properties = JSON_OBJECT() WHERE id = '${emptyFile.id}'; UPDATE file_revisions SET custom_properties = JSON_OBJECT() WHERE file_id = '${emptyFile.id}' AND revision_number = 1;`)

  await scenario('file-list metadata preserves nested empty objects', async () => {
    const listed = (await request(`/vaults/${vault.id}/files`, {}, token)).files.find(file => file.id === objectFile.id)
    assert.deepEqual(listed.customProperties, { _config_tabs: {}, _config_descriptions: {} })
  })
  await scenario('file-list metadata preserves empty objects', async () => {
    const listed = (await request(`/vaults/${vault.id}/files`, {}, token)).files.find(file => file.id === emptyFile.id)
    assert.deepEqual(listed.customProperties, {})
  })
  await scenario('revision metadata preserves nested empty objects', async () => {
    const revision = (await request(`/files/${objectFile.id}/revisions`, {}, token)).revisions[0]
    assert.deepEqual(revision.customProperties, { _config_tabs: {}, _config_descriptions: {} })
  })
  await scenario('revision metadata preserves empty objects', async () => {
    const revision = (await request(`/files/${emptyFile.id}/revisions`, {}, token)).revisions[0]
    assert.deepEqual(revision.customProperties, {})
  })

  await scenario('missing durable check-in journal mapping', async () => {
    const journalPath = `review-journal-${randomUUID()}.sldprt`
    const journalFile = await request('/files/import', {
      method: 'POST',
      body: JSON.stringify({
        vaultId: vault.id,
        canonicalPath: journalPath,
        fileName: journalPath,
        storageRelativePath: journalPath,
      }),
    }, token)
    const checkout = await request(`/files/${journalFile.id}/checkout`, {
      method: 'POST', body: JSON.stringify({ clientWorkingPath: workspace }),
    }, token)
    queryTestDatabase('RENAME TABLE file_checkin_requests TO file_checkin_requests_unavailable;')
    try {
      const response = await requestStatus(`/files/${journalFile.id}/checkin`, {
        method: 'POST',
        body: JSON.stringify({
          mode: 'content',
          requestId: randomUUID(),
          canonicalPath: 'checkin-recovery-unavailable.sldprt',
          fileName: 'checkin-recovery-unavailable.sldprt',
          checkoutToken: checkout.checkoutToken,
          storageRelativePath: 'checkin-recovery-unavailable.sldprt',
          contentHash: 'b'.repeat(64),
          sizeBytes: 1,
        }),
      }, token)
      assert.equal(response.status, 503)
      assert.equal(response.body.error, 'CHECKIN_RECOVERY_UNAVAILABLE')
    } finally {
      queryTestDatabase('RENAME TABLE file_checkin_requests_unavailable TO file_checkin_requests;')
    }
  })

  assert.deepEqual(failures, [])
}
