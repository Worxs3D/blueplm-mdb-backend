import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'

const server = process.env.BLUEPLM_PHP_TEST_SERVER ?? 'http://127.0.0.1:18080'
const bootstrapToken = 'integration-bootstrap-token-must-be-at-least-32-chars'
const maintenanceToken = 'integration-maintenance-token-must-be-at-least-32-chars'
const password = 'Setup owner password 123!'

async function waitForDatabase() {
  let lastError
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await fetch(`${server}/health`)
      if (response.ok) {
        const probe = await fetch(`${server}/admin/migrate`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ maintenanceToken: 'invalid' }),
        })
        if (probe.status === 403) return
      }
    } catch (error) { lastError = error }
    await new Promise((resolve) => setTimeout(resolve, 250))
  }
  throw new Error(`Test database did not become ready: ${String(lastError)}`)
}

function cookie(response) {
  const values = typeof response.headers.getSetCookie === 'function'
    ? response.headers.getSetCookie()
    : [response.headers.get('set-cookie')]
  const raw = values.filter(Boolean).at(-1)
  assert.ok(raw, 'Expected setup/admin session cookie')
  return raw.split(';', 1)[0]
}

function form(values) {
  return new URLSearchParams(values).toString()
}

function base32Decode(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  const bits = [...value.replace(/[^A-Z2-7]/gi, '').toUpperCase()]
    .map((character) => alphabet.indexOf(character).toString(2).padStart(5, '0')).join('')
  return Buffer.from((bits.match(/.{8}/g) ?? []).map((group) => String.fromCharCode(Number.parseInt(group, 2))).join(''), 'binary')
}

function totp(secret, timestamp = Date.now()) {
  const counter = Math.floor(timestamp / 1000 / 30)
  const data = Buffer.alloc(8)
  data.writeUInt32BE(Math.floor(counter / 0x100000000), 0)
  data.writeUInt32BE(counter >>> 0, 4)
  const digest = createHmac('sha1', base32Decode(secret)).update(data).digest()
  const offset = digest[19] & 15
  const value = ((digest[offset] & 127) << 24) | (digest[offset + 1] << 16) | (digest[offset + 2] << 8) | digest[offset + 3]
  return String(value % 1_000_000).padStart(6, '0')
}

await waitForDatabase()
const germanSetup = await fetch(`${server}/setup/?lang=de`, { redirect: 'manual' })
assert.equal(germanSetup.status, 200)
const germanSetupHtml = await germanSetup.text()
assert.match(germanSetupHtml, /BluePLM MDB Ersteinrichtung/)
assert.match(germanSetupHtml, /Firmen-Kürzel \(nur Kleinbuchstaben\)/)
const setup = await fetch(`${server}/setup/`, { redirect: 'manual' })
assert.equal(setup.status, 200)
const setupCookie = cookie(setup)
const setupHtml = await setup.text()
const csrf = setupHtml.match(/name="csrf" value="([a-f0-9]{64})"/)?.[1]
assert.ok(csrf)
assert.doesNotMatch(setupHtml, /https:\/\/all-inkl\.com\/PAC5A8BC16A32D0/)
assert.doesNotMatch(setupHtml, /affiliate link/i)
assert.match(setupHtml, /Enter only <code>BLUEPLM_BOOTSTRAP_TOKEN<\/code> below/)
assert.match(setupHtml, /Company slug \(lowercase only\)/)
assert.match(setupHtml, /Google Drive Shared Drive folder/)
assert.match(setupHtml, /name="storageProvider" value="google_drive"/)

const setupWithVault = await fetch(`${server}/setup/?vaultPath=${encodeURIComponent('\\\\test-server\\selected-vault')}`, { redirect: 'manual' })
assert.equal(setupWithVault.status, 200)
const setupWithVaultHtml = await setupWithVault.text()
assert.match(setupWithVaultHtml, /input\[name="networkRoot"\]/)
assert.match(setupWithVaultHtml, /selected-vault/)

const setupWithGoogleDrive = await fetch(`${server}/setup/?googleDriveFolderId=1AbCdEfGhIjKlMnOpQrStUvWxYz_012345`, { redirect: 'manual' })
assert.equal(setupWithGoogleDrive.status, 200)
const setupWithGoogleDriveHtml = await setupWithGoogleDrive.text()
assert.match(setupWithGoogleDriveHtml, /googleDriveFolderId/)
assert.match(setupWithGoogleDriveHtml, /1AbCdEfGhIjKlMnOpQrStUvWxYz_012345/)

const invalidSlug = await fetch(`${server}/setup/`, {
  method: 'POST', redirect: 'manual',
  headers: { Cookie: setupCookie, 'Content-Type': 'application/x-www-form-urlencoded' },
  body: form({ csrf, bootstrapToken, organizationName: 'Setup Test Org', organizationSlug: 'DÖN', displayName: 'Setup Owner', email: 'setup-owner@example.test', password, vaultName: 'Archive Vault', networkRoot: '\\test-server\\BluePLM-Archive' }),
})
assert.equal(invalidSlug.status, 400)
assert.match(await invalidSlug.text(), /Company slug \(lowercase only\) must use 2–100 lowercase letters/)

const submitted = await fetch(`${server}/setup/`, {
  method: 'POST', redirect: 'manual',
  headers: { Cookie: setupCookie, 'Content-Type': 'application/x-www-form-urlencoded' },
  body: form({ csrf, bootstrapToken, organizationName: 'Setup Test Org', organizationSlug: 'setup-test-org', displayName: 'Setup Owner', email: 'setup-owner@example.test', password, vaultName: 'Archive Vault', networkRoot: '\\\\test-server\\BluePLM-Archive', enableTotp: '1' }),
})
assert.equal(submitted.status, 200)
const submittedHtml = await submitted.text()
assert.match(submittedHtml, /The setup endpoint is now permanently disabled/)
const secret = submittedHtml.match(/Authenticator setup key \(showing once\):<\/strong><br><code>([A-Z2-7]+)<\/code>/)?.[1]
assert.ok(secret, 'Expected one-time TOTP secret')
assert.match(submittedHtml, /Open the administration portal/)

const lockedSetup = await fetch(`${server}/setup/`, { redirect: 'manual' })
assert.equal(lockedSetup.status, 404)

const login = await fetch(`${server}/admin/`, {
  method: 'POST', redirect: 'manual',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: form({ action: 'login', email: 'setup-owner@example.test', password }),
})
assert.equal(login.status, 200)
const adminCookie = cookie(login)
assert.match(await login.text(), /Authenticator code/)

const verified = await fetch(`${server}/admin/`, {
  method: 'POST', redirect: 'manual',
  headers: { Cookie: adminCookie, 'Content-Type': 'application/x-www-form-urlencoded' },
  body: form({ action: 'verify-totp-login', code: totp(secret) }),
})
assert.equal(verified.status, 303, await verified.text())
const authenticatedCookie = cookie(verified)
const dashboard = await fetch(`${server}/admin/`, { headers: { Cookie: authenticatedCookie } })
assert.equal(dashboard.status, 200)
const dashboardHtml = await dashboard.text()
assert.match(dashboardHtml, /Authenticator protection/)
assert.match(dashboardHtml, /Enabled for this administrator/)

console.log('Setup portal, installation lock, NAS metadata, and TOTP admin login passed.')
