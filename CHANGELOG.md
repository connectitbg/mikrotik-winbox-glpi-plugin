# Changelog

Project name: **MikroTik Tools for GLPI** (previously **Winbox - GLPI**). The GLPI technical key remains `winbox`.

## [1.0.2] — security review candidate

- Encrypt router passwords at rest with GLPI's `GLPIKey`; migrate existing plaintext rows during plugin install/upgrade and never render the saved password back into the form.
- Require configuration-update and network-device-update rights in both WinBox and SSH launch endpoints before issuing one-time handoff tokens.
- Change launch requests from GET to CSRF-protected POST.
- Compare the SSH fingerprints confirmed on screen with the fresh scan before saving pins.
- Use GLPI `Migration` helpers for schema upgrades and use the configured GLPI base URL when generating client downloads/handlers.
- Use GLPI's script helper for the SSH setup page and align source/catalog metadata for the next release.
- Route plugin UI strings through the `winbox` translation domain and add Bulgarian translations for the Winbox handoff page.
- Remove KiTTY integration from device actions and client setup because automatic password delivery requires the `-pass` command-line option; PuTTY remains the supported SSH GUI client.
- Candidate tested on the GLPI 12.0.2 test instance; not yet published to GitHub or Teclib.

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
