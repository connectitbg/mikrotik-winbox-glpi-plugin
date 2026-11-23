# GLPI catalog and Marketplace follow-up

## Status

The initial catalog submission, stable 1.0.0 update, and 1.0.2 release follow-up were sent to Teclib in November 2026. The latest reply was sent on 23 November 2026 in the existing **Winbox - GLPI beta: catalog review and Marketplace eligibility** thread. Teclib's pre-publication review is tracked in [GitHub issue #1](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/1). Version 1.0.2 incorporates the security and documentation corrections recorded in the release notes. The project owner has confirmed the profile matrix and current device-page controls on the GLPI 12.0.2 test instance.

The 1.0.2 release links and updated XML have been sent in the existing Teclib email thread. Catalog listing and separate in-app Marketplace review remain subject to Teclib's response; a GitHub release does not grant either approval.

## Submission metadata

- Display name: MikroTik Tools for GLPI
- Technical key: `winbox`
- Publisher: Connect IT — <https://connectit.bg>
- Version: 1.0.2 (stable)
- Catalog state in XML: stable; Teclib publication/update confirmation pending
- Compatibility declared: GLPI `^11.0 || ^12.0`
- Validation: GLPI 12.0.2 test instance for the current review cycle; earlier stable release exercised on GLPI 11.0.9
- Source: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin>
- License: GPL-3.0-or-later
- Release tag: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/tag/v1.0.2>
- Release archive: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/download/v1.0.2/glpi-winbox-plugin-1.0.2.zip>
- Versioned catalog XML: <https://raw.githubusercontent.com/connectitbg/mikrotik-winbox-glpi-plugin/v1.0.2/winbox.xml>
- Issue reports: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues>

## Short description

Manage MikroTik access, diagnostics, and configuration backups from GLPI network equipment records.

## Long description

MikroTik Tools for GLPI adds per-device WinBox, PuTTY, and WebFig actions. Its per-user Windows installer registers the browser handlers and installs missing clients. The plugin provides access logs, SSH fingerprint verification, read-only RouterOS policy/service diagnostics, masked `.rsc` export/import through REST over HTTPS, and sensitive text backup/restore through SSH/SFTP. WebFig prefers WWW-SSL and falls back to WWW.

Administrators can delegate access buttons, access-log visibility, connection-field viewing/editing, diagnostics, and backup/restore by existing GLPI profile. Admin and Super-Admin receive all six permissions by default; Supervisor and Technician receive access buttons and access-log visibility; other profiles start denied. Device-level GLPI rights remain required. Router passwords are encrypted at rest with the GLPI security key; PuTTY/Plink receive the saved password through an access-restricted per-launch Windows named pipe. KiTTY integration was removed because its automatic password option exposes the password in process arguments.

WinBox's native automatic-login interface requires the password as a process argument. Local processes or administrators may inspect it, and WinBox's **Keep Password** or **Autosave session** settings may retain session data. The plugin does not control those native settings. This limitation is disclosed for Teclib review; automatic login through a local native client cannot guarantee zero workstation exposure.

## Review corrections in 1.0.2

- Encrypt saved router credentials with GLPI's security key and migrate existing plaintext rows.
- Separate launch permission from connection-field visibility and editing with a profile-level matrix enforced by server-side access checks.
- Use CSRF-protected POST launch requests, single-use 15-second handoff codes, and live countdown pages.
- Confirm the exact scanned SSH fingerprint set before pinning it.
- Use GLPI migration helpers/query builder and the configured GLPI base URL.
- Deliver PuTTY/Plink passwords through a per-launch Windows pipe without a local password file; remove KiTTY integration because its password flag exposes credentials in process arguments.
- Document the unavoidable native WinBox password-argument and session-save limitation.

## Publication and review sequence

1. **Complete:** Publish the 1.0.2 source, release archive, changelog, release notes, and updated English catalog XML on GitHub.
2. **Complete:** Deploy the 1.0.2 candidate to GLPI 12.0.2, lint all 30 plugin PHP files, clear GLPI cache, confirm the installer/countdown routes, and verify the countdown asset. No router was contacted or changed.
3. **Complete:** Project owner confirmed the profile matrix, countdown, and device-level installer link placement on the test instance.
4. **Complete:** Replied in the existing Teclib email thread on 23 November 2026 with the matching 1.0.2 release links and updated catalog XML; requested an update to the existing entry and continuation of the review.
5. **Pending:** Teclib confirmation of the catalog update and any further review findings.
6. **Pending:** Follow Teclib's requirements for separate in-app Marketplace review, including any eligibility or partner requirements.

The authenticated HTTP-method check for GET requests to both launch-creation endpoints remains a follow-up verification item. The routes declare POST as the only allowed method. Keep this distinction visible in any further review correspondence.
