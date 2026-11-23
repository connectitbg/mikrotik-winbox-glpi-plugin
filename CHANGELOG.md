# Changelog

Project name: **MikroTik Tools for GLPI** (previously **Winbox - GLPI**). The GLPI technical key remains `winbox`.

## [1.0.2] — 2026-11-23

Stable release with security-review corrections, profile delegation, client-handling changes, and refreshed documentation.

- Encrypt router passwords at rest with GLPI's `GLPIKey`; migrate existing plaintext database rows during plugin install/upgrade. Decrypted values are exposed only to profiles granted connection-field visibility.
- Add a configurable per-profile permission matrix for access buttons, access-log visibility, connection-field viewing, connection-field editing, diagnostics, and backup/restore. Preserve administrator choices, deny new or unknown profiles by default, and require the relevant GLPI device read/update rights in addition to plugin permission.
- Seed the matrix with Admin/Super-Admin granted all six permissions, Supervisor/Technician granted access buttons and access-log visibility, and other profiles denied. The project owner confirmed the matrix works on the GLPI 12.0.2 test instance.
- Change launch-code creation to CSRF-protected POST requests. WinBox, PuTTY, and SSH diagnostic codes are single-use, expire after 15 seconds, and show a live countdown that disables the launch link at expiry.
- Remove temporary password files from PuTTY/Plink launch and diagnostic flows. Deliver passwords through a per-launch, access-restricted Windows named pipe.
- Remove KiTTY launch support, client installation, and its protocol handler because its automatic-password option exposes credentials in process arguments. The installer removes only the obsolete plugin-specific handler and leaves independent KiTTY installations untouched.
- Compare the freshly scanned SSH host-key set with the exact set displayed and confirmed before saving a fingerprint pin.
- Use GLPI migration and query-builder helpers for schema changes and queries. Generate client download/handler URLs from the configured GLPI base URL rather than the incoming `Host` header.
- Serve the live countdown script through a GLPI route so it works when GLPI 12 uses a `/public` document root.
- Add a client setup/instructions button to each network device's Access section for users allowed to launch clients. Place it on its own row below the access actions, open it in a new tab, and retain the setup-page link in plugin configuration.
- Route plugin interface text through the `winbox` translation domain and include the compiled Bulgarian catalog. Keep the client installation instructions bilingual and the catalog metadata/release documentation in English.
- Document the native WinBox limitation: automatic login requires the password as a process argument, which local processes or administrators may inspect. The plugin does not control WinBox's **Keep Password** or **Autosave session** settings; their local-storage behavior remains an open item for Teclib. The plugin cannot guarantee zero workstation exposure while using native WinBox auto-login.
- Clarify that SSH launch logs record client-process startup, not successful RouterOS authentication; document backup/restore prerequisites, GLPI encryption-key backup requirements, and the fact that old database backups may still contain plaintext passwords.
- Update the installer and troubleshooting instructions to describe the supported WinBox/PuTTY setup and the removed KiTTY integration.

## [1.0.1] — 2026-11-22

- Confirmed plugin operation on GLPI 12.0.2 and declared compatibility with GLPI 11.x and 12.x.
- Updated plugin compatibility metadata and release documentation; no router-side changes are required.

## [1.0.0] — 2026-11-22

First stable release. See [full release notes](docs/RELEASE_NOTES_1.0.0.md).

- WinBox, PuTTY, KiTTY, and WebFig access from network equipment records (KiTTY support was removed before the first 1.0.2 publication; see above).
- Per-user Windows installer with managed client setup and browser protocol handlers.
- GLPI access logs and per-device access log.
- SSH fingerprint verification and RouterOS policy/service diagnostics.
- Masked `.rsc` export/import through REST over HTTPS.
- Sensitive text backup/restore through SSH/SFTP and restore-upload diagnostics.
- Reworked device page actions into access, diagnostics, and backup sections.

## Earlier beta releases

- [0.4.9](docs/RELEASE_NOTES_0.4.9.md)
- [0.4.8](docs/RELEASE_NOTES_0.4.8.md)
- [0.4.7](docs/RELEASE_NOTES_0.4.7.md)
- [0.4.6](docs/RELEASE_NOTES_0.4.6.md)
