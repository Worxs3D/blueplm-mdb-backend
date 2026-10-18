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
  await runImportPathBoundaryTests(context)
  await runCheckinPathBoundaryTests(context)
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
    const revisionAuthorFailures = []
    const originalAuthorId = queryTestDatabase(`SELECT checked_in_by FROM file_revisions WHERE file_id = '${importedFile.id}' AND revision_number = 1;`)
    const originalRevisionId = queryTestDatabase(`SELECT id FROM file_revisions WHERE file_id = '${importedFile.id}' AND revision_number = 1;`)
    const organizationId = queryTestDatabase(`SELECT organization_id FROM files WHERE id = '${importedFile.id}';`)
    const sameOrganizationAuthorId = randomUUID()
    const sameOrganizationAuthorEmail = `revision-author-${randomUUID()}@example.invalid`
    const otherOrganizationId = randomUUID()
    const otherOrganizationSlug = `revision-author-${randomUUID()}`
    const otherOrganizationAuthorId = randomUUID()
    const otherOrganizationAuthorEmail = `revision-author-${randomUUID()}@example.invalid`
    const readerPassword = 'Revision reader test password 123!'
    const readerEmail = `revision-reader-${randomUUID()}@example.invalid`
    const reader = await request('/users', {
      method: 'POST',
      body: JSON.stringify({ email: readerEmail, displayName: 'Revision Reader', password: readerPassword, role: 'member' }),
    }, token)
    const foreignReaderPassword = 'Foreign revision reader test password 123!'
    const foreignReaderEmail = `foreign-revision-reader-${randomUUID()}@example.invalid`
    const foreignReader = await request('/users', {
      method: 'POST',
      body: JSON.stringify({ email: foreignReaderEmail, displayName: 'Foreign Revision Reader', password: foreignReaderPassword, role: 'member' }),
    }, token)
    queryTestDatabase(`INSERT INTO users (id, email, display_name, password_hash) VALUES ('${sameOrganizationAuthorId}', '${sameOrganizationAuthorEmail}', 'Same Organization Author', 'not-used-test-hash'); INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${organizationId}', '${sameOrganizationAuthorId}', 'member');`)
    try {
      queryTestDatabase(`INSERT INTO vault_access (vault_id, user_id, granted_by) SELECT '${vault.id}', '${reader.id}', user_id FROM organization_memberships WHERE organization_id = '${organizationId}' AND role = 'owner' LIMIT 1;`)
      const memberLogin = await request('/auth/login', {
        method: 'POST', body: JSON.stringify({ email: readerEmail, password: readerPassword }),
      })
      queryTestDatabase(`UPDATE file_revisions SET checked_in_by = '${sameOrganizationAuthorId}' WHERE file_id = '${importedFile.id}' AND revision_number = 1;`)
      revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
      assert.equal(revisions[0].checkedInBy, sameOrganizationAuthorId)
      assert.equal(revisions[0].checkedInByName, 'Same Organization Author')
      assert.equal(Object.hasOwn(revisions[0], 'email'), false)
      assert.equal(JSON.stringify(revisions[0]).includes(sameOrganizationAuthorEmail), false)
      assert.equal(revisions[0].id, originalRevisionId)
      assert.equal(revisions[0].revisionNumber, 1)
      const memberRevisions = (await request(`/files/${importedFile.id}/revisions`, {}, memberLogin.token)).revisions
      const memberIdentity = await request('/auth/me', {}, memberLogin.token)
      assert.equal(memberIdentity.user.role, 'member')
      assert.equal(memberRevisions[0].checkedInBy, sameOrganizationAuthorId)
      assert.equal(memberRevisions[0].checkedInByName, 'Same Organization Author')
      assert.equal(Object.hasOwn(memberRevisions[0], 'email'), false)
      assert.equal(JSON.stringify(memberRevisions[0]).includes(sameOrganizationAuthorEmail), false)
      assert.equal(memberRevisions[0].id, originalRevisionId)
      assert.equal((await requestStatus(`/users/${sameOrganizationAuthorId}/profile`, {}, memberLogin.token)).status, 403)

      queryTestDatabase(`INSERT INTO organizations (id, name, slug) VALUES ('${otherOrganizationId}', 'Revision Author Test Organization', '${otherOrganizationSlug}'); DELETE FROM organization_memberships WHERE user_id = '${foreignReader.id}'; INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${otherOrganizationId}', '${foreignReader.id}', 'member');`)
      const foreignLogin = await request('/auth/login', {
        method: 'POST', body: JSON.stringify({ email: foreignReaderEmail, password: foreignReaderPassword }),
      })
      assert.equal((await requestStatus(`/files/${importedFile.id}/revisions`, {}, foreignLogin.token)).status, 404)

      queryTestDatabase(`DELETE FROM organization_memberships WHERE organization_id = '${organizationId}' AND user_id = '${sameOrganizationAuthorId}';`)
      revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
      assert.equal(revisions[0].checkedInBy, sameOrganizationAuthorId)
      assert.equal(revisions[0].checkedInByName, null)

      queryTestDatabase(`INSERT INTO users (id, email, display_name, password_hash) VALUES ('${otherOrganizationAuthorId}', '${otherOrganizationAuthorEmail}', 'Other Organization Author', 'not-used-test-hash'); INSERT INTO organization_memberships (organization_id, user_id, role) VALUES ('${otherOrganizationId}', '${otherOrganizationAuthorId}', 'member'); UPDATE file_revisions SET checked_in_by = '${otherOrganizationAuthorId}' WHERE file_id = '${importedFile.id}' AND revision_number = 1;`)
      revisions = (await request(`/files/${importedFile.id}/revisions`, {}, token)).revisions
      assert.equal(revisions[0].checkedInBy, otherOrganizationAuthorId)
      assert.equal(revisions[0].checkedInByName, null)
      assert.equal(Object.hasOwn(revisions[0], 'email'), false)
      assert.equal(JSON.stringify(revisions[0]).includes(otherOrganizationAuthorEmail), false)
    } catch (error) {
      revisionAuthorFailures.push(error instanceof Error ? error.message : String(error))
    } finally {
      queryTestDatabase(`UPDATE file_revisions SET checked_in_by = '${originalAuthorId}' WHERE file_id = '${importedFile.id}' AND revision_number = 1; DELETE FROM vault_access WHERE vault_id = '${vault.id}' AND user_id = '${reader.id}'; DELETE FROM sessions WHERE user_id IN ('${reader.id}', '${foreignReader.id}'); DELETE FROM organization_memberships WHERE user_id IN ('${reader.id}', '${foreignReader.id}', '${sameOrganizationAuthorId}', '${otherOrganizationAuthorId}'); DELETE FROM users WHERE id IN ('${reader.id}', '${foreignReader.id}', '${sameOrganizationAuthorId}', '${otherOrganizationAuthorId}'); DELETE FROM organizations WHERE id = '${otherOrganizationId}';`)
    }
    assert.deepEqual(revisionAuthorFailures, [])
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

export async function runImportPathBoundaryTests(context) {
  const { requestStatus, vault, token } = context
  const suffix = randomUUID()
  const invalidPaths = [
    `/absolute-${suffix}.sldprt`,
    `\\absolute-${suffix}.sldprt`,
    `//server/share/${suffix}.sldprt`,
    `\\\\server\\share\\${suffix}.sldprt`,
    `C:\\absolute-${suffix}.sldprt`,
    `C:/absolute-${suffix}.sldprt`,
    `C:relative-${suffix}.sldprt`,
    `\\\\?\\C:\\absolute-${suffix}.sldprt`,
    `\\\\.\\device-${suffix}.sldprt`,
    `\\/mixed-root-${suffix}.sldprt`,
    `folder/bad:${suffix}.sldprt`,
    `folder/bad?${suffix}.sldprt`,
    `folder/control\u0001${suffix}.sldprt`,
    `folder/trailing-${suffix}.sldprt.`,
    `folder/trailing-space-${suffix}.sldprt `,
    'folder/CON.sldprt',
    'folder/COM¹.sldprt',
    'folder/LPT³.txt',
  ]
  const countBefore = Number(queryTestDatabase(`SELECT COUNT(*) FROM files WHERE vault_id = '${vault.id}';`))
  const revisionsBefore = Number(queryTestDatabase(`SELECT COUNT(*) FROM file_revisions r JOIN files f ON f.id = r.file_id WHERE f.vault_id = '${vault.id}';`))

  for (const [field, invalidPath] of invalidPaths.flatMap(path => [
    ['canonicalPath', path],
    ['storageRelativePath', path],
  ])) {
    const body = {
      vaultId: vault.id,
      canonicalPath: `path-boundary-${suffix}/candidate-${randomUUID()}.sldprt`,
      fileName: 'path-boundary-candidate.sldprt',
      storageRelativePath: `path-boundary-${suffix}/content-${randomUUID()}.sldprt`,
    }
    body[field] = invalidPath
    const response = await requestStatus('/files/import', {
      method: 'POST', body: JSON.stringify(body),
    }, token)
    assert.equal(response.status, 400, `${field} accepted unsafe path ${JSON.stringify(invalidPath)}: ${JSON.stringify(response.body)}`)
    assert.equal(response.body.error, 'INVALID_REQUEST')
  }

  assert.equal(Number(queryTestDatabase(`SELECT COUNT(*) FROM files WHERE vault_id = '${vault.id}';`)), countBefore)
  assert.equal(Number(queryTestDatabase(`SELECT COUNT(*) FROM file_revisions r JOIN files f ON f.id = r.file_id WHERE f.vault_id = '${vault.id}';`)), revisionsBefore)

  const safePath = `path-boundary-${suffix}\\relative-file.sldprt`
  const safeStoragePath = `legacy\\relative-object-${suffix}.sldprt`
  const accepted = await requestStatus('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: safePath,
      fileName: 'relative-file.sldprt',
      storageRelativePath: safeStoragePath,
    }),
  }, token)
  assert.equal(accepted.status, 201, JSON.stringify(accepted.body))
  assert.equal(queryTestDatabase(`SELECT CONCAT(canonical_path, '|', storage_relative_path) FROM files WHERE id = '${accepted.body.id}';`), `path-boundary-${suffix}/relative-file.sldprt|legacy/relative-object-${suffix}.sldprt`)
  const unicodePath = `unicode-${suffix}\\über-file.sldprt`
  const unicodeAccepted = await requestStatus('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: unicodePath,
      fileName: 'über-file.sldprt',
      storageRelativePath: `legacy\\objekt-ß-${suffix}.sldprt`,
    }),
  }, token)
  assert.equal(unicodeAccepted.status, 201, JSON.stringify(unicodeAccepted.body))
  assert.equal(queryTestDatabase(`SELECT CONCAT(canonical_path, '|', storage_relative_path) FROM files WHERE id = '${unicodeAccepted.body.id}';`), `unicode-${suffix}/über-file.sldprt|legacy/objekt-ß-${suffix}.sldprt`)
  const nonReservedDeviceLikePath = `path-boundary-${suffix}/COM10.sldprt`
  const nonReservedDeviceLike = await requestStatus('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: nonReservedDeviceLikePath,
      fileName: 'COM10.sldprt',
      storageRelativePath: nonReservedDeviceLikePath,
    }),
  }, token)
  assert.equal(nonReservedDeviceLike.status, 201, JSON.stringify(nonReservedDeviceLike.body))
}

export async function runCheckinPathBoundaryTests(context) {
  const { request, requestStatus, vault, token } = context
  const suffix = randomUUID()
  const canonicalPath = `path-boundary-${suffix}/part.sldprt`
  const file = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath,
      fileName: 'part.sldprt',
      storageRelativePath: `initial/${suffix}.sldprt`,
      contentHash: 'a'.repeat(64),
      sizeBytes: 1,
    }),
  }, token)
  const lease = await request(`/files/${file.id}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: `path-boundary/${suffix}` }),
  }, token)
  const invalidPaths = [
    `/absolute-${suffix}.sldprt`,
    `\\absolute-${suffix}.sldprt`,
    `//server/share/${suffix}.sldprt`,
    `\\\\server\\share\\${suffix}.sldprt`,
    `C:\\absolute-${suffix}.sldprt`,
    `C:/absolute-${suffix}.sldprt`,
    `C:relative-${suffix}.sldprt`,
    `\\\\?\\C:\\absolute-${suffix}.sldprt`,
    `\\\\.\\device-${suffix}.sldprt`,
    `\\/mixed-root-${suffix}.sldprt`,
    `folder/bad:${suffix}.sldprt`,
    `folder/bad?${suffix}.sldprt`,
    `folder/control\u0001${suffix}.sldprt`,
    `folder/trailing-${suffix}.sldprt.`,
    `folder/trailing-space-${suffix}.sldprt `,
    'folder/CON.sldprt',
    'folder/COM¹.sldprt',
    'folder/LPT³.txt',
  ]
  const requestIds = []
  const snapshot = () => queryTestDatabase([
    `SELECT CONCAT('file|', current_revision, '|', storage_relative_path, '|', COALESCE(content_hash, ''), '|', size_bytes) FROM files WHERE id = '${file.id}';`,
    `SELECT CONCAT('revisions|', COUNT(*)) FROM file_revisions WHERE file_id = '${file.id}';`,
    `SELECT CONCAT('lease|', COUNT(*), '|', COALESCE(MAX(token_hash), '')) FROM checkouts WHERE file_id = '${file.id}';`,
    `SELECT CONCAT('receipts|', COUNT(*)) FROM file_checkin_requests WHERE file_id = '${file.id}';`,
    `SELECT CONCAT('events|', COUNT(*)) FROM events WHERE aggregate_id = '${file.id}';`,
  ].join(' '))
  const before = snapshot()

  for (const storageRelativePath of invalidPaths) {
    const requestId = randomUUID()
    requestIds.push(requestId)
    const response = await requestStatus(`/files/${file.id}/checkin`, {
      method: 'POST',
      body: JSON.stringify({
        mode: 'content',
        requestId,
        canonicalPath,
        fileName: 'part.sldprt',
        checkoutToken: lease.checkoutToken,
        storageRelativePath,
        contentHash: 'b'.repeat(64),
        sizeBytes: 2,
      }),
    }, token)
    assert.equal(response.status, 400, `Check-in accepted unsafe storage path ${JSON.stringify(storageRelativePath)}: ${JSON.stringify(response.body)}`)
    assert.equal(response.body.error, 'INVALID_REQUEST')
    assert.equal(snapshot(), before, 'Rejected check-in changed file, revisions, lease, receipts, or events.')
  }

  const valid = await request(`/files/${file.id}/checkin`, {
    method: 'POST',
    body: JSON.stringify({
      mode: 'content',
      requestId: randomUUID(),
      canonicalPath: `path-boundary-${suffix}/part-renamed.sldprt`,
      fileName: 'part-renamed.sldprt',
      checkoutToken: lease.checkoutToken,
      storageRelativePath: `legacy\\COM10-relative-revision-${suffix}-ö.sldprt`,
      contentHash: 'c'.repeat(64),
      sizeBytes: 3,
    }),
  }, token)
  assert.equal(valid.revision, 2)
  assert.equal(valid.file.storage_relative_path, `legacy/COM10-relative-revision-${suffix}-ö.sldprt`)
  assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM checkouts WHERE file_id = '${file.id}';`), '0')
  assert.equal(queryTestDatabase(`SELECT COUNT(*) FROM file_checkin_requests WHERE file_id = '${file.id}' AND request_id IN (${requestIds.map(id => `'${id}'`).join(',')});`), '0')
}
