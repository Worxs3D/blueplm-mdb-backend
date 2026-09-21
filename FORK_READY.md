# BluePLM MDB – fork-ready design

This package is intentionally portable:

- The runtime contains no hosting provider URL, customer name, FTP credential, or database secret.
- `public/setup/` is a one-time server bootstrap. It creates organization, owner, optional TOTP authenticator protection, and network-vault metadata after validating a token held only in the private environment file.
- The desktop client receives only the public HTTPS endpoint. Database credentials and bootstrap secrets never leave the server/deployment machine.
- Network-vault paths are metadata. Each Windows client owns its connection credentials through Windows Credential Manager.
- `scripts/deploy-allinkl.mjs` is an optional All-Inkl deployment adapter; it obtains the target URL from `BLUEPLM_PUBLIC_URL` instead of hard-coding a deployment.

Forks should keep the `Installation` and `Totp` modules provider-neutral and add host-specific upload tooling only as separate scripts.

The setup page can show an explicitly labelled, optional ALL-INKL affiliate link. It does not make ALL-INKL a dependency: any suitable PHP/MariaDB host remains supported.
