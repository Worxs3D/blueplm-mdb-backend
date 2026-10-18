import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'
import { writeFile } from 'node:fs/promises'
import { join } from 'node:path'

function commentBody(comment, checkoutToken) {
  return JSON.stringify({ comment, checkoutToken })
}

function database(sql) {
  const project = process.env.COMPOSE_PROJECT_NAME
  const composeArgs = ['compose']
  if (project) composeArgs.push('-p', project)
  return execFileSync('docker', [
    ...composeArgs, '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
}

function revisionCommentEventCount(fileId) {
  return Number(database(`SELECT COUNT(*) FROM events WHERE type = 'file.revision_comment_updated' AND aggregate_id = '${fileId}';`))
}

export async function runFileRevisionCommentIntegration({
  request,
  requestStatus,
  ownerToken,
  memberToken,
  memberId,
  foreignToken,
  vault,
  vaultRoot,
  workspace,
}) {
  const memberVaultAccess = await request(`/users/${memberId}/vault-access`, {
    method: 'PUT',
    body: JSON.stringify({ vaultIds: [vault.id] }),
  }, ownerToken)
  assert.equal(memberVaultAccess.success, true)

  const primaryName = `revision-comment-primary-${randomUUID()}.txt`
  await writeFile(join(vaultRoot, primaryName), 'primary revision-comment fixture\n', 'utf8')
  const primaryFile = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: primaryName,
      storageRelativePath: primaryName,
      fileName: primaryName,
    }),
  }, ownerToken)
  const fileId = primaryFile.id
  const current = (await request(`/files/${fileId}/revisions`, {}, memberToken)).revisions[0]
  assert.ok(current?.id)

  const otherName = 'revision-comment-other.txt'
  await writeFile(join(vaultRoot, otherName), 'separate revision fixture\n', 'utf8')
  const otherFile = await request('/files/import', {
    method: 'POST',
    body: JSON.stringify({
      vaultId: vault.id,
      canonicalPath: otherName,
      storageRelativePath: otherName,
      fileName: otherName,
    }),
  }, ownerToken)
  const otherRevision = (await request(`/files/${otherFile.id}/revisions`, {}, ownerToken)).revisions[0]

  const checkout = await request(`/files/${fileId}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: join(workspace, 'revision-comment-member.txt') }),
  }, memberToken)
  const originalFields = ({ comment, ...fields }) => fields
  const originalSnapshot = originalFields(current)

  const saved = await request(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: JSON.stringify({
      comment: '  saved by checked-out member  ',
      checkoutToken: checkout.checkoutToken,
      checkedInBy: '00000000-0000-4000-8000-000000000001',
      checkedInByName: 'Untrusted Request Actor',
      revisionNumber: 999,
      contentHash: 'f'.repeat(64),
    }),
  }, memberToken)
  assert.deepEqual(saved, { success: true, comment: 'saved by checked-out member' })
  const savedEvent = JSON.parse(database(`
    SELECT JSON_OBJECT(
      'type', type,
      'aggregateId', aggregate_id,
      'payload', JSON_EXTRACT(payload, '$')
    )
    FROM events
    WHERE type = 'file.revision_comment_updated' AND aggregate_id = '${fileId}'
    ORDER BY created_at DESC
    LIMIT 1;
  `))
  assert.equal(savedEvent.type, 'file.revision_comment_updated')
  assert.equal(savedEvent.aggregateId, fileId)
  assert.deepEqual(savedEvent.payload, {
    revisionId: current.id,
    revisionNumber: current.revisionNumber,
    userId: memberId,
  })
  assert.equal(revisionCommentEventCount(fileId), 1)
  let revisions = (await request(`/files/${fileId}/revisions`, {}, memberToken)).revisions
  let updated = revisions.find((entry) => entry.id === current.id)
  assert.equal(updated.comment, 'saved by checked-out member')
  assert.deepEqual(originalFields(updated), originalSnapshot, 'only the revision comment should change')

  const beforeFailures = updated.comment
  const invalidToken = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('must not replace the note', 'invalid-checkout-token-long-enough'),
  }, memberToken)
  assert.equal(invalidToken.status, 409)
  assert.equal(invalidToken.body.error, 'INVALID_CHECKOUT')

  const wrongRevision = await requestStatus(`/files/${fileId}/revisions/${otherRevision.id}/comment`, {
    method: 'PATCH',
    body: commentBody('must not cross files', checkout.checkoutToken),
  }, memberToken)
  assert.equal(wrongRevision.status, 404)
  assert.equal(wrongRevision.body.error, 'NOT_FOUND')

  const foreignAttempt = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('must not cross organizations', checkout.checkoutToken),
  }, foreignToken)
  assert.equal(foreignAttempt.status, 404)
  assert.equal(foreignAttempt.body.error, 'NOT_FOUND')

  for (const body of [
    JSON.stringify({ checkoutToken: checkout.checkoutToken }),
    JSON.stringify({ comment: 1, checkoutToken: checkout.checkoutToken }),
    commentBody('x'.repeat(4001), checkout.checkoutToken),
  ]) {
    const invalidPayload = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
      method: 'PATCH', body,
    }, memberToken)
    assert.equal(invalidPayload.status, 400)
    assert.equal(invalidPayload.body.error, 'INVALID_REQUEST')
  }
  revisions = (await request(`/files/${fileId}/revisions`, {}, memberToken)).revisions
  updated = revisions.find((entry) => entry.id === current.id)
  assert.equal(updated.comment, beforeFailures, 'rejected updates must preserve the saved comment')

  const eventsBeforeClear = revisionCommentEventCount(fileId)
  const cleared = await request(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('   ', checkout.checkoutToken),
  }, memberToken)
  assert.deepEqual(cleared, { success: true, comment: null })
  assert.equal(revisionCommentEventCount(fileId), eventsBeforeClear + 1)
  revisions = (await request(`/files/${fileId}/revisions`, {}, memberToken)).revisions
  assert.equal(revisions.find((entry) => entry.id === current.id).comment, null)
  const eventsBeforeRestore = revisionCommentEventCount(fileId)
  const restored = await request(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody(beforeFailures, checkout.checkoutToken),
  }, memberToken)
  assert.deepEqual(restored, { success: true, comment: beforeFailures })
  assert.equal(revisionCommentEventCount(fileId), eventsBeforeRestore + 1)

  const viewer = await request('/users', {
    method: 'POST',
    body: JSON.stringify({
      email: `revision-viewer-${randomUUID()}@example.test`,
      displayName: 'Revision Viewer',
      password: 'Revision viewer password 123!',
      role: 'viewer',
    }),
  }, ownerToken)
  const viewerLogin = await request('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email: viewer.email, password: 'Revision viewer password 123!' }),
  })
  const eventsBeforeDeniedViewer = revisionCommentEventCount(fileId)
  const deniedViewer = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('viewer must not edit', checkout.checkoutToken),
  }, viewerLogin.token)
  assert.equal(deniedViewer.status, 403)
  assert.equal(deniedViewer.body.error, 'READ_ONLY_ROLE')
  assert.equal(revisionCommentEventCount(fileId), eventsBeforeDeniedViewer, 'denied updates must not emit a comment event')
  await request(`/users/${viewer.id}`, { method: 'DELETE' }, ownerToken)

  const eventsBeforeInjectedFailure = revisionCommentEventCount(fileId)
  database("CREATE TRIGGER test_fail_revision_comment_event_insert BEFORE INSERT ON events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected revision comment event failure';")
  try {
    const failedEventPatch = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
      method: 'PATCH',
      body: commentBody('event failure must roll back', checkout.checkoutToken),
    }, memberToken)
    assert.equal(failedEventPatch.status, 500)
    assert.equal(failedEventPatch.body.error, 'INTERNAL_ERROR')
    assert.equal(revisionCommentEventCount(fileId), eventsBeforeInjectedFailure, 'failed event writes must roll back with the note')
    const afterFailedEvent = (await request(`/files/${fileId}/revisions`, {}, memberToken)).revisions
    assert.equal(afterFailedEvent.find((entry) => entry.id === current.id).comment, beforeFailures)
  } finally {
    database('DROP TRIGGER IF EXISTS test_fail_revision_comment_event_insert;')
  }

  await request(`/files/${fileId}/checkout/cancel`, {
    method: 'POST',
    body: JSON.stringify({ checkoutToken: checkout.checkoutToken }),
  }, memberToken)
  const revokedLease = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('expired lease must not edit', checkout.checkoutToken),
  }, memberToken)
  assert.equal(revokedLease.status, 409)
  assert.equal(revokedLease.body.error, 'INVALID_CHECKOUT')

  const ownerCheckout = await request(`/files/${fileId}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: join(workspace, 'revision-comment-owner.txt') }),
  }, ownerToken)
  const otherClientToken = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('other client must not edit', ownerCheckout.checkoutToken),
  }, memberToken)
  assert.equal(otherClientToken.status, 409)
  assert.equal(otherClientToken.body.error, 'INVALID_CHECKOUT')
  await request(`/files/${fileId}/checkout/cancel`, {
    method: 'POST',
    body: JSON.stringify({ checkoutToken: ownerCheckout.checkoutToken }),
  }, ownerToken)

  const expiredCheckout = await request(`/files/${fileId}/checkout`, {
    method: 'POST',
    body: JSON.stringify({ clientWorkingPath: join(workspace, 'revision-comment-expired.txt') }),
  }, memberToken)
  assert.match(fileId, /^[0-9a-f-]{36}$/iu)
  assert.equal(database(`UPDATE checkouts SET expires_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 SECOND) WHERE file_id = '${fileId}'; SELECT ROW_COUNT()`), '1')
  const expiredLease = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('expired lease must not edit', expiredCheckout.checkoutToken),
  }, memberToken)
  assert.equal(expiredLease.status, 409)
  assert.equal(expiredLease.body.error, 'INVALID_CHECKOUT')
  await request(`/files/${fileId}/checkout/cancel`, {
    method: 'POST',
    body: JSON.stringify({ checkoutToken: expiredCheckout.checkoutToken }),
  }, memberToken)

  revisions = (await request(`/files/${fileId}/revisions`, {}, ownerToken)).revisions
  assert.equal(revisions.find((entry) => entry.id === current.id).comment, beforeFailures)

  await request(`/users/${memberId}`, { method: 'DELETE' }, ownerToken)
  const revokedMembership = await requestStatus(`/files/${fileId}/revisions/${current.id}/comment`, {
    method: 'PATCH',
    body: commentBody('revoked membership must not edit', checkout.checkoutToken),
  }, memberToken)
  assert.equal(revokedMembership.status, 401)
  assert.equal(revokedMembership.body.error, 'UNAUTHENTICATED')
}
