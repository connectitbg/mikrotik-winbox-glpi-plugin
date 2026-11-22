# MikroTik Tools for GLPI 1.0.0

First stable release. The plugin was previously listed as **Winbox - GLPI**; its internal GLPI key remains `winbox` so existing installations keep their plugin identity.

## Device access

- Added per-device access actions for WinBox, PuTTY, KiTTY, and WebFig.
- Added a single Windows setup executable that registers per-user browser protocol handlers and installs missing or incompatible managed clients from official HTTPS sources with pinned SHA-256 checksums. Compatible existing installations are left unchanged.
- Added single-use, expiring handoffs. WinBox handoffs expire after 90 seconds; SSH handoffs after 5 minutes.
- Added access logging in GLPI and a per-device access log governed by GLPI permissions.
- Added SSH host fingerprint verification before SSH backup/restore operations.

## Diagnostics and RouterOS features

- Added read-only RouterOS user-policy and service-endpoint diagnostics for `ssh`, `read`, `sensitive`, `ftp`, `write`, FTP, API, API-SSL, WWW, WWW-SSL, and SSH.
- Added a restore upload diagnostic in GLPI. It checks the pinned SSH identity, uploads a randomly named temporary file over SFTP, and attempts cleanup without importing configuration.
- Added an Open WebFig action that prefers WWW-SSL and falls back to WWW when TLS is not available.

## Configuration backup and restore

- Added masked `.rsc` export and confirmed import via RouterOS REST over HTTPS.
- Added sensitive RouterOS text export and confirmed restore through SSH/SFTP, with RouterOS dry-run where supported.
- Grouped access actions, diagnostics, and four backup actions into separate sections on the device tab.

## Security and operational notes

- Router credentials are stored as plain text in the GLPI database. Restrict GLPI access and use HTTPS.
- Regular SSH launch logs confirm client process startup, not successful RouterOS authentication.
- KiTTY receives its saved password on the command line. The managed KiTTY version also has a maintainer-reported risk involving crafted terminal sequences; PuTTY is preferred for untrusted SSH hosts.
- RouterOS diagnostics are read-only. The optional SSH setup helper generates commands for manual administrator review; it does not execute them remotely.
- The Windows installer is unsigned. Production distribution should use Connect IT's code-signing certificate.
- This release has been exercised on GLPI 11.0.9. Compatibility is declared for GLPI 11.x; broader version coverage and independent security review have not been claimed.

## Upgrade

Back up the GLPI database and `plugins/winbox` directory, then replace the plugin files while keeping the directory name `winbox`. Install/upgrade and enable **MikroTik Tools for GLPI** in **Setup → Plugins**. The existing `winbox` key, database tables, protocol names, client executable name, and Windows data folder are retained.

## Validation

- Rebuilt the Windows setup executable from source.
- Passed the isolated client-installer smoke suite, including preservation of existing clients, checksum and archive-path validation, HTTPS-only download checks, and SSH result classification.
- PHP syntax lint passed for all plugin PHP files on the GLPI test server.
- The upgraded plugin reports version 1.0.0 and is enabled in the test GLPI. The English-first bilingual installer page and configured setup executable both returned HTTP 200.
- These checks did not connect to a router or change router configuration.
