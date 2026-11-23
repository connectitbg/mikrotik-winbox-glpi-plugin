# GLPI catalog and Marketplace follow-up

## Status

The initial catalog submission and follow-up were sent to Teclib in November 2026. Teclib's security review is tracked at [GitHub issue #1](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/1). Candidate 1.0.2 addresses the review findings and is being tested on GLPI 12.0.2. Do not send the updated catalog XML or ask Teclib to resume review until the candidate is tested, published as a matching GitHub release, and its download URL works. Neither catalog publication nor Marketplace approval has been confirmed.

The follow-up asks Teclib to update the existing beta submission to stable 1.0.0 (or advise whether a new submission is needed), confirm the catalog review status, and explain any remaining requirements for catalog acceptance and separate Marketplace review.

## Current submission metadata

- Display name: MikroTik Tools for GLPI (formerly Winbox - GLPI)
- Technical key: `winbox`
- Publisher: Connect IT — <https://connectit.bg>
- Version: 1.0.2 (security-review candidate; test pass completed, not yet published)
- Catalog state: stable in `winbox.xml`; publication pending Teclib confirmation
- Tested on: GLPI 11.0.9 and GLPI 12.0.2; declared compatibility: GLPI 11.x and 12.x. The current 1.0.2 candidate was deployed and linted on the GLPI 12.0.2 test server.
- Source: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin>
- License: GPL-3.0-or-later
- Release archive: publish the matching 1.0.2 asset only after test verification
- Issue reports: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues>

## Short description

Manage MikroTik access, diagnostics, and configuration backups from GLPI network equipment records.

## Long description

MikroTik Tools for GLPI adds per-device access actions for WinBox, PuTTY, and WebFig. Its per-user Windows installer registers the browser protocol handlers and installs missing clients. The plugin provides GLPI access logging, SSH host fingerprint verification, read-only RouterOS policy and service diagnostics, masked `.rsc` export/import through REST over HTTPS, and sensitive text backup/restore through SSH/SFTP. WebFig prefers WWW-SSL and falls back to WWW. Actions are governed by GLPI permissions and RouterOS policies. KiTTY integration was removed before publication because automatic login requires exposing the saved password as a process command-line argument.

Version 1.0.2 addresses the Teclib review by encrypting stored router passwords with the GLPI security key, enforcing permissions on the server-side launch endpoints, requiring CSRF-protected POST requests for token creation, comparing SSH fingerprints before pinning, and using GLPI database migration helpers. The candidate retains the GLPI 11.x and 12.x compatibility range and has passed the current test-server checks on GLPI 12.0.2. An authenticated browser check of the GET rejection remains pending; no router was contacted. The source is licensed under GPL-3.0-or-later.

## Publication sequence

1. **Complete:** Publish the source, sanitized interface screenshot, versioned release archive, and catalog XML on GitHub.
2. **Complete:** Sent Teclib the stable 1.0.0 release links and XML; requested an update to the existing beta submission.
3. **Complete:** Deploy the 1.0.2 candidate to GLPI 12.0.2, lint all plugin PHP files, clear GLPI cache, verify the installation page response, and validate the gettext catalog. No router was contacted or modified.
4. **Pending:** Publish the reviewed 1.0.2 source and release asset on GitHub; confirm the versioned `download_url` resolves.
5. **Pending:** Send Teclib the matching XML and release link, request that the existing security review be updated, and address any further findings.
6. **Pending:** Follow Teclib's instructions for separate Marketplace review, including any eligibility or partner requirements.

Catalog publication and Marketplace approval are distinct decisions. Update this document only when Teclib confirms each status.
