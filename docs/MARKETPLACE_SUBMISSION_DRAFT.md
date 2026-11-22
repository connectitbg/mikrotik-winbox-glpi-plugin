# GLPI catalog and Marketplace follow-up

## Status

The initial public catalog submission was sent on 2026-11-21 while the plugin was in beta. Stable release 1.0.0 was published on GitHub on 2026-11-22, and an English follow-up with the updated XML and release links was sent to Teclib on 2026-11-22. Catalog review and Marketplace status are now pending Teclib's response; neither publication nor approval has been confirmed.

The follow-up asks Teclib to update the existing beta submission to stable 1.0.0 (or advise whether a new submission is needed), confirm the catalog review status, and explain any remaining requirements for catalog acceptance and separate Marketplace review.

## Current submission metadata

- Display name: MikroTik Tools for GLPI (formerly Winbox - GLPI)
- Technical key: `winbox`
- Publisher: Connect IT — <https://connectit.bg>
- Version: 1.0.0
- Catalog state: stable in `winbox.xml`; publication pending Teclib confirmation
- Tested on: GLPI 11.0.9; declared compatibility: GLPI 11.x
- Source: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin>
- License: GPL-3.0-or-later
- Release archive: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/download/v1.0.0/glpi-winbox-plugin-1.0.0.zip>
- Issue reports: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues>

## Short description

Manage MikroTik access, diagnostics, and configuration backups from GLPI network equipment records.

## Long description

MikroTik Tools for GLPI adds per-device access actions for WinBox, PuTTY, KiTTY, and WebFig. Its per-user Windows installer registers the browser protocol handlers and installs missing clients. The plugin provides GLPI access logging, SSH host fingerprint verification, read-only RouterOS policy and service diagnostics, masked `.rsc` export/import through REST over HTTPS, and sensitive text backup/restore through SSH/SFTP. WebFig prefers WWW-SSL and falls back to WWW. Actions are governed by GLPI permissions and RouterOS policies.

Version 1.0.0 is the first stable release for GLPI 11.x, exercised on GLPI 11.0.9. Router credentials are stored as plain text in the GLPI database, so HTTPS and restricted GLPI permissions are required. The source is licensed under GPL-3.0-or-later.

## Publication sequence

1. **Complete:** Publish the source, sanitized interface screenshot, versioned release archive, and catalog XML on GitHub.
2. **Complete:** Send Teclib the stable 1.0.0 release links and updated XML; request an update to the existing beta submission.
3. **Pending:** Resolve any catalog feedback and wait for Teclib's catalog decision.
4. **Pending:** Follow Teclib's instructions for separate Marketplace review, including any eligibility or partner requirements.

Catalog publication and Marketplace approval are distinct decisions. Update this document only when Teclib confirms each status.
