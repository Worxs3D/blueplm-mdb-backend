import assert from 'node:assert/strict'
import { randomUUID } from 'node:crypto'

export async function runFolderRenameIntegration({ request, requestStatus, token, vault, workspace }) {
  assert.equal(typeof request, 'function')
  assert.equal(typeof requestStatus, 'function')
  assert.ok(token)
  assert.ok(vault?.id)
  assert.ok(workspace)

  const createdFolderIds = []
  const createdFileIds = []
  const activeCheckouts = []
  const prefix = `folder-rename-${randomUUID()}`
  const sourcePath = `${prefix}/source`
  const nestedPath = `${sourcePath}/nested`
  const siblingPath = `${prefix}/sourceX`
  const movedPath = `${prefix}/renamed`

  const createFolder = async (folderPath) => {
    const result = await request('/folders', {
      method: 'POST',
      body: JSON.stringify({ vaultId: vault.id, folderPath }),
    }, token)
    createdFolderIds.push(result.folder.id)
    return result
  }
  const importFile = async (canonicalPath) => {
    const fileName = canonicalPath.split('/').at(-1)
    const result = await request('/files/import', {
      method: 'POST',
      body: JSON.stringify({
        vaultId: vault.id,
        canonicalPath,
        fileName,
        storageRelativePath: canonicalPath,
      }),
    }, token)
    createdFileIds.push(result.id)
    return result
  }
  const patchFolder = (folderId, folderPath) => requestStatus(`/folders/${folderId}`, {
    method: 'PATCH',
    body: JSON.stringify({ folderPath }),
  }, token)
  const folders = async () => (await request(`/vaults/${vault.id}/folders`, {}, token)).folders
  const files = async () => (await request(`/vaults/${vault.id}/files`, {}, token)).files

  try {
    const source = (await createFolder(sourcePath)).folder
    const nested = (await createFolder(nestedPath)).folder
    const sibling = (await createFolder(siblingPath)).folder
    const rootFile = await importFile(`${sourcePath}/root.sldprt`)
    const nestedFile = await importFile(`${nestedPath}/child.sldprt`)
    const siblingFile = await importFile(`${siblingPath}/keep.sldprt`)

    const checkout = await request(`/files/${nestedFile.id}/checkout`, {
      method: 'POST',
      body: JSON.stringify({ clientWorkingPath: workspace }),
    }, token)
    activeCheckouts.push({ fileId: nestedFile.id, checkoutToken: checkout.checkoutToken })
    const blockedByCheckout = await patchFolder(source.id, movedPath)
    assert.equal(blockedByCheckout.status, 409)
    assert.equal(blockedByCheckout.body.error, 'CHECKED_OUT')
    await request(`/files/${nestedFile.id}/checkout/cancel`, {
      method: 'POST',
      body: JSON.stringify({ checkoutToken: checkout.checkoutToken }),
    }, token)
    activeCheckouts.pop()

    const moved = await patchFolder(source.id, movedPath)
    assert.equal(moved.status, 200)
    assert.equal(moved.body.success, true)
    const folderRows = await folders()
    assert.ok(folderRows.some((row) => row.id === source.id && row.folder_path === movedPath))
    assert.ok(folderRows.some((row) => row.id === nested.id && row.folder_path === `${movedPath}/nested`))
    const fileRows = await files()
    assert.ok(fileRows.some((row) => row.id === rootFile.id && row.canonicalPath === `${movedPath}/root.sldprt`))
    assert.ok(fileRows.some((row) => row.id === nestedFile.id && row.canonicalPath === `${movedPath}/nested/child.sldprt`))
    assert.ok(fileRows.some((row) => row.id === siblingFile.id && row.canonicalPath === `${siblingPath}/keep.sldprt`))
    assert.equal(fileRows.find((row) => row.id === nestedFile.id).fileName, 'child.sldprt')

    const unicodePrefix = prefix + '-unicode'
    const cafeRoot = (await createFolder(unicodePrefix + '/café')).folder
    const cafeChild = (await createFolder(unicodePrefix + '/cafe/child')).folder
    const cafeFile = await importFile(unicodePrefix + '/cafe/child/part.sldprt')
    const accentEquivalentSelfMove = await patchFolder(cafeRoot.id, unicodePrefix + '/cafe/child')
    assert.equal(accentEquivalentSelfMove.status, 409)
    assert.equal(accentEquivalentSelfMove.body.error, 'INVALID_MOVE')
    const cafeDestination = unicodePrefix + '/cafe-renamed'
    const cafeMove = await patchFolder(cafeRoot.id, cafeDestination)
    assert.equal(cafeMove.status, 200)
    assert.ok((await folders()).some((row) => row.id === cafeChild.id && row.folder_path === cafeDestination + '/child'))
    assert.ok((await files()).some((row) => row.id === cafeFile.id && row.canonicalPath === cafeDestination + '/child/part.sldprt'))

    const reverseRoot = (await createFolder(unicodePrefix + '/cafe')).folder
    const reverseChild = (await createFolder(unicodePrefix + '/café/child')).folder
    const reverseFile = await importFile(unicodePrefix + '/café/child/reverse.sldprt')
    const reverseDestination = unicodePrefix + '/accent-reversed'
    const reverseMove = await patchFolder(reverseRoot.id, reverseDestination)
    assert.equal(reverseMove.status, 200)
    assert.ok((await folders()).some((row) => row.id === reverseChild.id && row.folder_path === reverseDestination + '/child'))
    assert.ok((await files()).some((row) => row.id === reverseFile.id && row.canonicalPath === reverseDestination + '/child/reverse.sldprt'))

    const caseRoot = (await createFolder(unicodePrefix + '/CaseRoot')).folder
    const caseChild = (await createFolder(unicodePrefix + '/caseroot/Nested')).folder
    const caseFile = await importFile(unicodePrefix + '/CASEROOT/nested/Part.sldprt')
    const caseEquivalentSelfMove = await patchFolder(caseRoot.id, unicodePrefix + '/caseroot/new')
    assert.equal(caseEquivalentSelfMove.status, 409)
    assert.equal(caseEquivalentSelfMove.body.error, 'INVALID_MOVE')
    const caseDestination = unicodePrefix + '/case-renamed'
    const caseCollision = await importFile(caseDestination + '/Nested/Part.sldprt')
    const caseCollisionMove = await patchFolder(caseRoot.id, caseDestination)
    assert.equal(caseCollisionMove.status, 409)
    assert.equal(caseCollisionMove.body.error, 'PATH_EXISTS')
    assert.ok((await folders()).some((row) => row.id === caseRoot.id && row.folder_path === unicodePrefix + '/CaseRoot'))
    assert.ok((await folders()).some((row) => row.id === caseChild.id && row.folder_path === unicodePrefix + '/caseroot/Nested'))
    assert.ok((await files()).some((row) => row.id === caseFile.id && row.canonicalPath === unicodePrefix + '/CASEROOT/nested/Part.sldprt'))
    assert.ok((await files()).some((row) => row.id === caseCollision.id && row.canonicalPath === caseDestination + '/Nested/Part.sldprt'))

    const longSegment = '_'.repeat(1100)
    const longRootPath = unicodePrefix + '/' + longSegment
    const longRoot = (await createFolder(longRootPath)).folder
    const longChild = (await createFolder(longRootPath + '/nested')).folder
    const longFile = await importFile(longRootPath + '/nested/long.sldprt')
    const longEquivalentSelfMove = await patchFolder(longRoot.id, longRootPath + '/child')
    assert.equal(longEquivalentSelfMove.status, 409)
    assert.equal(longEquivalentSelfMove.body.error, 'INVALID_MOVE')
    assert.ok((await folders()).some((row) => row.id === longRoot.id && row.folder_path === longRootPath))
    assert.ok((await folders()).some((row) => row.id === longChild.id && row.folder_path === longRootPath + '/nested'))
    assert.ok((await files()).some((row) => row.id === longFile.id && row.canonicalPath === longRootPath + '/nested/long.sldprt'))

    const identityMove = await patchFolder(source.id, movedPath)
    assert.equal(identityMove.status, 200)

    const intoSelf = await patchFolder(source.id, `${movedPath}/nested/new`)
    assert.equal(intoSelf.status, 409)
    assert.equal(intoSelf.body.error, 'INVALID_MOVE')

    const activeTargetPath = `${prefix}/active-target`
    await createFolder(activeTargetPath)
    const activeFolderCollision = await patchFolder(source.id, activeTargetPath)
    assert.equal(activeFolderCollision.status, 409)
    assert.equal(activeFolderCollision.body.error, 'PATH_EXISTS')

    const deletedTargetPath = `${prefix}/deleted-target`
    const deletedTarget = (await createFolder(deletedTargetPath)).folder
    await request(`/folders/${deletedTarget.id}`, { method: 'DELETE' }, token)
    const deletedFolderCollision = await patchFolder(source.id, deletedTargetPath)
    assert.equal(deletedFolderCollision.status, 409)
    assert.equal(deletedFolderCollision.body.error, 'PATH_EXISTS')

    const fileCollisionTarget = `${prefix}/file-collision`
    const collisionFile = await importFile(`${fileCollisionTarget}/root.sldprt`)
    const fileCollision = await patchFolder(source.id, fileCollisionTarget)
    assert.equal(fileCollision.status, 409)
    assert.equal(fileCollision.body.error, 'PATH_EXISTS')
    const afterCollisionFiles = await files()
    assert.ok(afterCollisionFiles.some((row) => row.id === nestedFile.id && row.canonicalPath === `${movedPath}/nested/child.sldprt`))
    assert.ok(afterCollisionFiles.some((row) => row.id === collisionFile.id && row.canonicalPath === `${fileCollisionTarget}/root.sldprt`))

    const invalidRoot = await patchFolder(source.id, '')
    assert.equal(invalidRoot.status, 400)
    const sourceAfterFailures = await folders()
    assert.ok(sourceAfterFailures.some((row) => row.id === source.id && row.folder_path === movedPath))

    const contenderA = (await createFolder(`${prefix}/contender-a`)).folder
    const contenderB = (await createFolder(`${prefix}/contender-b`)).folder
    const contestedPath = `${prefix}/contested`
    const concurrent = await Promise.all([
      patchFolder(contenderA.id, contestedPath),
      patchFolder(contenderB.id, contestedPath),
    ])
    assert.deepEqual(concurrent.map((result) => result.status).sort(), [200, 409])
    assert.ok((await folders()).filter((row) => row.folder_path === contestedPath).length === 1)
  } finally {
    for (const checkout of activeCheckouts.reverse()) {
      await requestStatus('/files/' + checkout.fileId + '/checkout/cancel', {
        method: 'POST',
        body: JSON.stringify({ checkoutToken: checkout.checkoutToken }),
      }, token)
    }
    for (const fileId of createdFileIds.reverse()) {
      await requestStatus('/files/' + fileId + '/trash', { method: 'POST' }, token)
      await requestStatus('/files/' + fileId, { method: 'DELETE' }, token)
    }
    for (const folderId of createdFolderIds.reverse()) {
      await requestStatus('/folders/' + folderId, { method: 'DELETE' }, token)
    }
  }
}
