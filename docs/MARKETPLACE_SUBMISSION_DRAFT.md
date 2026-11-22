# GLPI catalog and Marketplace follow-up

## Status

The initial public catalog submission was sent on 2026-11-21 while the plugin was in beta. Teclib confirmation and catalog review were pending at the time of the submission. This document is updated for the first stable release, 1.0.0. The catalog entry and Marketplace status must be confirmed with Teclib; this repository update does not mean either has been approved.

After the GitHub release is published, Connect IT will email `glpi@teclib.com` with the stable release links, request that the submitted catalog metadata be updated, and ask what remains for catalog acceptance and separate Marketplace review.

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

1. Publish the source and versioned release archive on GitHub.
2. Send Teclib the stable 1.0.0 release and updated XML, requesting an update to the existing beta submission.
3. Resolve catalog feedback and wait for Teclib's catalog decision.
4. If Marketplace review is a separate step after catalog acceptance, request it with the public source, release archive, GLPI compatibility evidence, and maintenance contact.

Catalog publication and Marketplace approval are distinct decisions. Update this document only when Teclib confirms each status.
