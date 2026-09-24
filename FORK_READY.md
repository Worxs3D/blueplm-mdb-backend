# BluePLM MDB portability constraints

This package is intentionally provider-neutral and suitable for public review:

- No hosting-provider URL, customer name, FTP credential, database credential,
  token, or private network address is committed.
- Initial setup and administration are part of the BluePLM desktop app. PHP
  exposes only authenticated JSON endpoints; browser setup/admin pages return
  `404`.
- The desktop installer uses FTPS, inspects the selected MariaDB database, and
  requires an explicit operator choice before migrating or deleting data.
- The live `.env` is never overwritten during inspection. A short-lived
  `.env.install` is promoted only after a successful new installation.
- The desktop client receives only the public HTTPS endpoint and an opaque
  session token. MariaDB and deployment credentials remain server-side.
- Network-vault paths are metadata. User credentials remain in the operating
  system credential store and are never passed on a command line.
- No affiliate or referral links are included.

Host-specific upload helpers must remain separate from the provider-neutral API.
Google Drive is intentionally outside this network-vault contribution and can
be proposed separately after productive testing.
