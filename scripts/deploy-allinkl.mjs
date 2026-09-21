import { readdirSync, readFileSync } from 'node:fs'
import { basename, dirname, join, relative } from 'node:path'
import { spawnSync } from 'node:child_process'
import process from 'node:process'

const project = dirname(import.meta.dirname)
const configuration = readFileSync(join(dirname(project), '.env'), 'utf8')

function readSection(source, section) {
  const header = source.match(new RegExp(`^\\[${section}\\]\\s*$`, 'mi'))
  if (!header || header.index === undefined) return {}
  const remaining = source.slice(header.index + header[0].length)
  const nextHeader = remaining.search(/^\[/m)
  const block = nextHeader === -1 ? remaining : remaining.slice(0, nextHeader)
  return Object.fromEntries([...block.matchAll(/^\s*([^#;=\s]+)\s*=\s*(.*?)\s*$/gm)].map(([, key, value]) => [key, value]))
}

function readValue(source, key) {
  return source.match(new RegExp(`^\\s*${key}\\s*=\\s*(.*?)\\s*$`, 'm'))?.[1] ?? null
}

function listFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const fullPath = join(directory, entry.name)
    return entry.isDirectory() ? listFiles(fullPath) : [fullPath]
  })
}

function curlConfig(user, password) {
  // Passed through stdin, never through the process list or console output.
  const escaped = `${user}:${password}`.replaceAll('"', '\\"')
  return `user = "${escaped}"\n`
}

const configuredFtp = readSection(configuration, 'webspaceFTP')
const tlsSetting = (configuredFtp.FTP_TLS ?? '').trim().toLowerCase()
const ftp = {
  URL:
    configuredFtp.URL ??
    configuredFtp.FTP_URL ??
    (configuredFtp.FTP_HOST
      ? `${['1', 'true', 'yes', 'required', 'explicit'].includes(tlsSetting) ? 'ftps' : 'ftp'}://${configuredFtp.FTP_HOST}`
      : undefined),
  Port: configuredFtp.Port ?? configuredFtp.FTP_PORT,
  Pfad: configuredFtp.Pfad ?? configuredFtp.FTP_PATH,
  Benutzername: configuredFtp.Benutzername ?? configuredFtp.FTP_USER,
  Passwort: configuredFtp.Passwort ?? configuredFtp.FTP_PASSWORD,
}
const requiresTls =
  ftp.URL?.startsWith('ftps://') || ['1', 'true', 'yes', 'required', 'explicit'].includes(tlsSetting)

if (!ftp.URL || !ftp.Pfad || !ftp.Benutzername || !ftp.Passwort) {
  throw new Error('The [webspaceFTP] configuration is incomplete.')
}

let endpoint = new URL(ftp.URL.includes('://') ? ftp.URL : `ftps://${ftp.URL}`)
// WHATWG URL normalises the default FTPS port 21 to an empty string. Preserve
// the configured/effective port separately before deciding whether this is
// explicit FTPS (AUTH TLS on port 21) or implicit FTPS.
const configuredPort = String(ftp.Port ?? endpoint.port ?? '21')
// All-Inkl's configured port 21 uses explicit FTPS (AUTH TLS), represented by
// curl as ftp:// plus --ftp-ssl-reqd. ftps:// would instead request implicit
// TLS and fails before authentication on that port.
if (endpoint.protocol === 'ftps:' && configuredPort === '21') {
  // Assigning URL.protocol cannot convert non-standard `ftps:` URLs to the
  // special `ftp:` scheme on all Node versions, so construct the FTP URL.
  endpoint = new URL(`ftp://${endpoint.host}${endpoint.pathname}`)
}
endpoint.port = configuredPort
const remoteRoot = [endpoint.pathname.replace(/\/$/, ''), ftp.Pfad.replace(/^\/+|\/+$/g, '')]
  .filter(Boolean)
  .join('/')

const files = [...listFiles(join(project, 'src')), ...listFiles(join(project, 'public')), ...listFiles(join(project, 'migrations'))]
const uploads = files.map((file) => ({
  file,
  target: `${remoteRoot}/${relative(project, file).replaceAll('\\', '/')}`,
}))

function buildCurlArgs(target, uploadFile) {
  const args = ['--fail', '--silent', '--show-error', '--ftp-create-dirs']
  if (requiresTls && target.protocol === 'ftp:') args.unshift('--ftp-ssl-reqd')
  if (uploadFile) args.push('--upload-file', uploadFile)
  else args.push('--list-only')
  args.push(target.toString())
  return args
}

if (process.argv[2] === '--probe') {
  const probe = new URL(endpoint)
  probe.pathname = remoteRoot || '/'
  const result = spawnSync('curl.exe', ['--config', '-', ...buildCurlArgs(probe)], {
    input: curlConfig(ftp.Benutzername, ftp.Passwort),
    encoding: 'utf8',
  })
  if (result.status !== 0) {
    throw new Error(`Read-only FTP probe failed: ${result.stderr || result.stdout}`)
  }
  console.log(`FTP probe succeeded for ${probe.protocol}//${probe.host}${probe.pathname}`)
  process.exit(0)
}

if (process.argv[2] !== '--apply') {
  console.log(`Dry run: ${uploads.length} PHP API files would be uploaded.`)
  for (const upload of uploads) console.log(relative(project, upload.file))
  process.exit(0)
}

for (const upload of uploads) {
  const target = new URL(endpoint)
  target.pathname = upload.target
  const result = spawnSync('curl.exe', ['--config', '-', ...buildCurlArgs(target, upload.file)], {
    input: curlConfig(ftp.Benutzername, ftp.Passwort),
    encoding: 'utf8',
  })
  if (result.status !== 0) throw new Error(`Upload failed for ${basename(upload.file)}: ${result.stderr || result.stdout}`)
}

const maintenanceToken = readValue(configuration, 'BLUEPLM_MAINTENANCE_TOKEN') ?? process.env.BLUEPLM_MAINTENANCE_TOKEN
if (!maintenanceToken) throw new Error('BLUEPLM_MAINTENANCE_TOKEN is unavailable locally; files were uploaded but migrations were not run.')
const publicApi = readValue(configuration, 'BLUEPLM_PUBLIC_URL') ?? process.env.BLUEPLM_PUBLIC_URL
if (!publicApi) throw new Error('BLUEPLM_PUBLIC_URL is unavailable locally; files were uploaded but remote verification was skipped.')
const migration = await fetch(`${publicApi}/admin/migrate`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ maintenanceToken }),
})
if (!migration.ok) throw new Error(`Remote migration failed with HTTP ${migration.status}.`)
const health = await fetch(`${publicApi}/health`)
if (!health.ok) throw new Error(`Remote health check failed with HTTP ${health.status}.`)
const healthBody = await health.json()
if (healthBody.ok !== true || healthBody.supabase !== false) throw new Error('Remote health check returned an unexpected backend state.')
console.log(`Uploaded ${uploads.length} PHP API files. Migrations and health check succeeded.`)
