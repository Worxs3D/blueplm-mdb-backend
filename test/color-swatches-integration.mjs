import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { randomUUID } from 'node:crypto'

/**
 * @typedef {object} ColorSwatchFixtureRequests
 * @property {(path: string, init?: RequestInit, token?: string) => Promise<any>} request
 * @property {(path: string, init?: RequestInit, token?: string) => Promise<{status: number, body: any}>} requestStatus
 */

/**
 * Exercise the complete owner/member/cross-organization swatch contract.
 *
 * @param {ColorSwatchFixtureRequests & {
 *   ownerToken: string,
 *   memberToken: string,
 *   foreignAdminToken: string,
 * }} context
 * @returns {Promise<{organizationSwatchId: string, ownerPersonalSwatchId: string}>}
 */
export async function runColorSwatchesIntegration({
  request,
  requestStatus,
  ownerToken,
  memberToken,
  foreignAdminToken,
}) {
  assert.equal((await requestStatus('/color-swatches')).status, 401)
  assert.equal((await requestStatus('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#abcdef', isOrg: false }),
  })).status, 401)
  assert.equal((await requestStatus('/color-swatches/00000000-0000-0000-0000-000000000000', {
    method: 'DELETE',
  })).status, 401)

  const ownerSwatchListBeforeCreate = await requestStatus('/color-swatches', {}, ownerToken)
  assert.equal(ownerSwatchListBeforeCreate.status, 200)
  assert.deepEqual(Object.keys(ownerSwatchListBeforeCreate.body), ['swatches'])

  const swatchCreateResponse = await requestStatus('/color-swatches', {
    method: 'POST',
    body: JSON.stringify({ color: '#12aBcF', isOrg: false }),
  }, ownerToken)
  assert.equal(swatchCreateResponse.status, 201)
  const swatchCreated = swatchCreateResponse.body
  assert.deepEqual(Object.keys(swatchCreated).sort(), ['color', 'createdAt', 'id', 'isOrg'])
  assert.match(swatchCreated.id, /^[0-9a-f-]{36}$/i)
  assert.equal(swatchCreated.color, '#12aBcF')
  assert.equal(swatchCreated.isOrg, false)
  assert.match(swatchCreated.createdAt, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/)

  const duplicateSwatch = await request('/color-swatches', {
    method: 'POST',
    body: JSON.stringify({ color: '#12aBcF', isOrg: false }),
  }, ownerToken)
  assert.notEqual(duplicateSwatch.id, swatchCreated.id)
  assert.equal((await requestStatus('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#12345', isOrg: false }),
  }, ownerToken)).status, 400)
  assert.equal((await requestStatus('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#123456', isOrg: 'false' }),
  }, ownerToken)).status, 400)

  const orgSwatch = await request('/color-swatches', {
    method: 'POST',
    body: JSON.stringify({ color: '#abcdef', isOrg: true }),
  }, ownerToken)
  assert.equal(orgSwatch.isOrg, true)
  assert.equal((await requestStatus('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#fedcba', isOrg: true }),
  }, memberToken)).status, 403)

  const ownerSwatches = (await request('/color-swatches', {}, ownerToken)).swatches
  assert.ok(ownerSwatches.some((entry) => entry.id === swatchCreated.id))
  assert.ok(ownerSwatches.some((entry) => entry.id === duplicateSwatch.id))
  assert.ok(ownerSwatches.some((entry) => entry.id === orgSwatch.id))
  assert.deepEqual(Object.keys(ownerSwatches[0]).sort(), ['color', 'createdAt', 'id', 'isOrg'])
  const compareSwatches = (left, right) => left.createdAt < right.createdAt
    ? -1
    : left.createdAt > right.createdAt
      ? 1
      : left.id < right.id
        ? -1
        : left.id > right.id
          ? 1
          : 0
  assert.deepEqual(ownerSwatches, [...ownerSwatches].sort(compareSwatches))

  const memberSwatch = await request('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#2468ac', isOrg: false }),
  }, memberToken)
  assert.equal(memberSwatch.isOrg, false)
  const memberSwatches = (await request('/color-swatches', {}, memberToken)).swatches
  assert.ok(memberSwatches.some((entry) => entry.id === orgSwatch.id))
  assert.ok(memberSwatches.some((entry) => entry.id === memberSwatch.id))
  assert.equal(memberSwatches.some((entry) => entry.id === swatchCreated.id || entry.id === duplicateSwatch.id), false)
  const ownerSwatchesAfterMemberCreate = (await request('/color-swatches', {}, ownerToken)).swatches
  assert.equal(ownerSwatchesAfterMemberCreate.some((entry) => entry.id === memberSwatch.id), false)

  assert.equal((await requestStatus(`/color-swatches/${memberSwatch.id}`, {
    method: 'DELETE',
  }, memberToken)).status, 204)
  assert.equal((await requestStatus(`/color-swatches/${orgSwatch.id}`, { method: 'DELETE' }, memberToken)).status, 403)
  assert.equal((await requestStatus(`/color-swatches/${swatchCreated.id}`, { method: 'DELETE' }, memberToken)).status, 404)
  assert.equal((await request('/color-swatches', {}, foreignAdminToken)).swatches.length, 0)
  assert.equal((await requestStatus(`/color-swatches/${orgSwatch.id}`, { method: 'DELETE' }, foreignAdminToken)).status, 404)
  assert.equal((await requestStatus(`/color-swatches/${swatchCreated.id}`, { method: 'DELETE' }, foreignAdminToken)).status, 404)

  const adminOrgSwatch = await request('/color-swatches', {
    method: 'POST', body: JSON.stringify({ color: '#13579b', isOrg: true }),
  }, foreignAdminToken)
  assert.equal(adminOrgSwatch.isOrg, true)
  assert.equal((await requestStatus(`/color-swatches/${adminOrgSwatch.id}`, {
    method: 'DELETE',
  }, foreignAdminToken)).status, 204)
  assert.equal((await request('/color-swatches', {}, foreignAdminToken)).swatches.length, 0)
  assert.equal((await requestStatus('/color-swatches/00000000-0000-0000-0000-000000000000', {
    method: 'DELETE',
  }, ownerToken)).status, 404)
  assert.equal((await requestStatus(`/color-swatches/${swatchCreated.id}`, {
    method: 'DELETE',
  }, ownerToken)).status, 204)
  assert.equal((await requestStatus(`/color-swatches/${swatchCreated.id}`, {
    method: 'DELETE',
  }, ownerToken)).status, 404)
  assert.ok((await request('/color-swatches', {}, ownerToken)).swatches.some((entry) => entry.id === duplicateSwatch.id))

  return {
    organizationSwatchId: orgSwatch.id,
    ownerPersonalSwatchId: duplicateSwatch.id,
  }
}

/**
 * Verify that both read-only organization roles retain scoped swatch reads and
 * receive the global 403 guard for POST and DELETE.
 * The two referenced swatches are created earlier and must remain in place
 * until this later call, after the viewer and guest sessions exist.
 *
 * @param {ColorSwatchFixtureRequests & {
 *   readOnlyUsers: Array<{token: string, userId: string}>,
 *   organizationSwatchId: string,
 *   ownerPersonalSwatchId: string,
 * }} context
 * @returns {Promise<void>}
 */
export async function assertColorSwatchesReadOnly({
  request,
  requestStatus,
  readOnlyUsers,
  organizationSwatchId,
  ownerPersonalSwatchId,
}) {
  assert.deepEqual(readOnlyUsers.map(({ role }) => role).sort(), ['guest', 'viewer'])
  assert.match(organizationSwatchId, /^[0-9a-f-]{36}$/i)
  assert.match(ownerPersonalSwatchId, /^[0-9a-f-]{36}$/i)
  assert.notEqual(organizationSwatchId, ownerPersonalSwatchId)
  const personalSwatchIds = []
  const unexpectedCreateIds = []
  let testError
  try {
    for (const [index, { userId }] of readOnlyUsers.entries()) {
      const id = randomUUID()
      const color = index === 0 ? '#d13579' : '#d1357a'
      seedPersonalSwatch(id, userId, color)
      personalSwatchIds.push(id)
    }

    for (const [index, { token, role }] of readOnlyUsers.entries()) {
      assert.equal((await request('/auth/me', {}, token)).user.role, role)
      const personalSwatchId = personalSwatchIds[index]
      const otherReadOnlyPersonalSwatchIds = personalSwatchIds.filter((_, otherIndex) => otherIndex !== index)
      const readOnlySwatches = (await request('/color-swatches', {}, token)).swatches
      assert.ok(readOnlySwatches.some((entry) => entry.id === organizationSwatchId))
      const ownPersonalSwatch = readOnlySwatches.find((entry) => entry.id === personalSwatchId)
      assert.ok(ownPersonalSwatch)
      assert.equal(ownPersonalSwatch.isOrg, false)
      assert.equal(ownPersonalSwatch.color, index === 0 ? '#d13579' : '#d1357a')
      assert.equal(readOnlySwatches.some((entry) => entry.id === ownerPersonalSwatchId), false)
      for (const otherPersonalSwatchId of otherReadOnlyPersonalSwatchIds) {
        assert.equal(readOnlySwatches.some((entry) => entry.id === otherPersonalSwatchId), false)
      }

      const deniedCreate = await requestStatus('/color-swatches', {
        method: 'POST', body: JSON.stringify({ color: '#d1357b', isOrg: false }),
      }, token)
      const unexpectedCreateId = deniedCreate.body?.id
      if (typeof unexpectedCreateId === 'string' && /^[0-9a-f-]{36}$/i.test(unexpectedCreateId)) {
        unexpectedCreateIds.push(unexpectedCreateId)
      }
      assert.equal(deniedCreate.status, 403)
      assert.equal(deniedCreate.body.error, 'READ_ONLY_ROLE')
      for (const swatchId of [personalSwatchId, ownerPersonalSwatchId, organizationSwatchId]) {
        const deniedDelete = await requestStatus(`/color-swatches/${swatchId}`, {
          method: 'DELETE',
        }, token)
        assert.equal(deniedDelete.status, 403)
        assert.equal(deniedDelete.body.error, 'READ_ONLY_ROLE')
      }
    }
  } catch (error) {
    testError = error
    throw error
  } finally {
    try {
      removeSwatchFixtures([
        ...personalSwatchIds,
        ...unexpectedCreateIds,
        ownerPersonalSwatchId,
        organizationSwatchId,
      ])
    } catch (cleanupError) {
      if (testError !== undefined && testError instanceof Error) {
        testError.message += ` (fixture cleanup also failed: ${String(cleanupError)})`
      } else if (testError === undefined) {
        throw cleanupError
      }
    }
  }
}

/** @param {string} id @param {string} userId @param {string} color */
function seedPersonalSwatch(id, userId, color) {
  assert.match(id, /^[0-9a-f-]{36}$/i)
  assert.match(userId, /^[0-9a-f-]{36}$/i)
  assert.match(color, /^#[0-9a-fA-F]{6}$/)
  const sql = `INSERT INTO color_swatches (id, organization_id, user_id, color)
    SELECT '${id}', organization_id, user_id, '${color}'
    FROM organization_memberships WHERE user_id = '${userId}' ORDER BY organization_id LIMIT 1;
    SELECT ROW_COUNT();`
  const insertedRows = execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim().split(/\r?\n/u).at(-1)
  assert.equal(insertedRows, '1', `Expected exactly one membership row for read-only user ${userId}.`)
}

/** @param {string[]} ids */
function removeSwatchFixtures(ids) {
  if (ids.length === 0) return
  assert.ok(ids.every((id) => /^[0-9a-f-]{36}$/i.test(id)), 'Expected only UUID swatch fixtures.')
  const sql = `SELECT COUNT(*) FROM color_swatches WHERE id IN (${ids.map((id) => `'${id}'`).join(',')});
    DELETE FROM color_swatches WHERE id IN (${ids.map((id) => `'${id}'`).join(',')});
    SELECT ROW_COUNT();`
  const numericRows = execFileSync('docker', [
    'compose', '-f', 'docker-compose.test.yml', 'exec', '-T', 'mariadb',
    'mariadb', '-N', '-B', '-ublueplm', '-pblueplm-test-password', 'blueplm', '-e', sql,
  ], { cwd: process.cwd(), stdio: 'pipe' }).toString().trim().split(/\r?\n/u).filter((line) => /^\d+$/u.test(line))
  assert.equal(numericRows.length, 2, 'Expected row counts before and after swatch fixture cleanup.')
  assert.equal(numericRows[1], numericRows[0], 'Expected all existing swatch fixtures to be removed.')
}
