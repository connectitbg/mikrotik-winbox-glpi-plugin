# GLPI catalog and Marketplace follow-up

## Status

The initial public catalog submission was sent on 2026-11-21 while the plugin was in beta. Stable release 1.0.0 was published on GitHub on 2026-11-22, and an English follow-up with the updated XML and release links was sent to Teclib on 2026-11-22. Release 1.0.1 now records tested GLPI 12 compatibility and is being prepared locally; publish it and send the updated catalog XML before asking Teclib to update the pending review. Neither catalog publication nor Marketplace approval has been confirmed.

The follow-up asks Teclib to update the existing beta submission to stable 1.0.0 (or advise whether a new submission is needed), confirm the catalog review status, and explain any remaining requirements for catalog acceptance and separate Marketplace review.

## Current submission metadata

- Display name: MikroTik Tools for GLPI (formerly Winbox - GLPI)
- Technical key: `winbox`
- Publisher: Connect IT — <https://connectit.bg>
- Version: 1.0.1 (prepared locally; not yet published)
- Catalog state: stable in `winbox.xml`; publication pending Teclib confirmation
- Tested on: GLPI 11.0.9 and 12.0.2; declared compatibility: GLPI 11.x and 12.x
- Source: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin>
- License: GPL-3.0-or-later
- Release archive: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/download/v1.0.1/glpi-winbox-plugin-1.0.1.zip>
- Issue reports: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues>

## Short description

Manage MikroTik access, diagnostics, and configuration backups from GLPI network equipment records.

## Long description

MikroTik Tools for GLPI adds per-device access actions for WinBox, PuTTY, KiTTY, and WebFig. Its per-user Windows installer registers the browser protocol handlers and installs missing clients. The plugin provides GLPI access logging, SSH host fingerprint verification, read-only RouterOS policy and service diagnostics, masked `.rsc` export/import through REST over HTTPS, and sensitive text backup/restore through SSH/SFTP. WebFig prefers WWW-SSL and falls back to WWW. Actions are governed by GLPI permissions and RouterOS policies.

Version 1.0.1 confirms compatibility with GLPI 12.x, tested on GLPI 12.0.2, while retaining GLPI 11.x support tested on 11.0.9. Router credentials are stored as plain text in the GLPI database, so HTTPS and restricted GLPI permissions are required. The source is licensed under GPL-3.0-or-later.

## Publication sequence

1. **Complete:** Publish the source, sanitized interface screenshot, versioned release archive, and catalog XML on GitHub.
2. **Complete:** Sent Teclib the stable 1.0.0 release links and XML; requested an update to the existing beta submission.
3. **Pending:** Publish 1.0.1 to GitHub, send Teclib its updated release link and XML, and request that the existing review include GLPI 12 compatibility.
4. **Pending:** Resolve any catalog feedback and wait for Teclib's catalog decision.
5. **Pending:** Follow Teclib's instructions for separate Marketplace review, including any eligibility or partner requirements.

Catalog publication and Marketplace approval are distinct decisions. Update this document only when Teclib confirms each status.
