import { randomBytes } from 'node:crypto'

// Emits values only to the terminal. The caller pastes them into the private
// server .env file; this script deliberately never writes a secret to Git or
// a public web directory.
for (const name of ['BLUEPLM_SESSION_SECRET', 'BLUEPLM_BOOTSTRAP_TOKEN', 'BLUEPLM_MAINTENANCE_TOKEN']) {
  console.log(`${name}=${randomBytes(32).toString('base64url')}`)
}
