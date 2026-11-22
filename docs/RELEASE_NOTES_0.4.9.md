# Winbox - GLPI 0.4.9 beta

This free beta adds a read-only RouterOS capability and service diagnostic to each configured network device.

## Changes

- Added a **Check RouterOS functions** page restricted to GLPI configuration update rights and update access to the device.
- The diagnostic reads the saved SSH account's active RouterOS group and reports the `ssh`, `read`, `sensitive`, `ftp`, and `write` policy flags. Unknown states remain visibly distinct from inactive policies.
- Added non-authenticating reachability checks for FTP, API, API-SSL, WWW, WWW-SSL, and SSH from the GLPI server. The FTP greeting check does not send credentials.
- Added per-device port settings for FTP, API, API-SSL, WWW, and WWW-SSL. The FTP network service port is explicitly separate from the RouterOS `ftp` account policy; SFTP continues to use SSH.
- Added one **Open WebFig** button that prefers WWW-SSL and falls back to WWW. The browser remains responsible for certificate validation.
- No RouterOS configuration or files are changed by the new capability check. The `write` flag is inspected from the account group; it is never probed with a write command.

## Upgrade

Back up the GLPI database, update the plugin files, and run the plugin upgrade from **Setup → Plugins** so the new per-device service port columns are added. No router-side changes are required. The read-only diagnostic logs into the configured SSH endpoint only after an authorized GLPI user selects **Run read-only checks**.

## Beta limitations

The SSH policy result requires a trusted pinned host fingerprint and credentials that can read the active user and group. If RouterOS does not expose the active group policy to that account, the affected flags are reported as **Unknown**. Reachable API/API-SSL ports confirm TCP/TLS transport only, not API authentication or authorization. The free beta has no subscription or formal support service.
