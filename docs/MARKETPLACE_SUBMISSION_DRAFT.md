# GLPI catalog and Marketplace submission

## Current beta listing

- Display name: Winbox - GLPI
- Technical key: `winbox`
- Publisher: Connect IT
- Version: 0.4.6
- Catalog state: beta
- GLPI compatibility exercised: GLPI 11.0.9; declared range: GLPI 11.x
- Source: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin>
- License: GPL-3.0-or-later
- Release archive: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/download/v0.4.6/glpi-winbox-plugin-0.4.6.zip>
- Contact and issue reports: <https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues>
- Beta terms: free, no subscription, and no formal support service. Connect IT monitors the issue tracker and considers fixes on a best-effort basis.

## Short description

Open MikroTik routers from GLPI using Winbox and manage router configuration backups.

## Long description

Winbox - GLPI adds a Winbox connection tab to GLPI network equipment records. Store per-device connection details, launch the locally installed Winbox client through a short-lived one-time handoff, review launch events, and manage RouterOS configuration exports and backups. Optional PuTTY and KiTTY launch handlers are provided for SSH access.

Version 0.4.6 is a free public beta exercised on GLPI 11.0.9. It has no subscription, purchase, or formal support service. Connect IT monitors the public issue tracker and considers fixes and improvements on a best-effort basis. Compatibility and security review are ongoing.

## Publication sequence

1. Publish the repository and versioned release archive on GitHub.
2. Submit `winbox.xml` to the GLPI plugin catalog using the process used for GLPI Zebra Shipping.
3. Resolve any catalog response and wait for catalog acceptance. Catalog acceptance does not itself mean Marketplace approval.
4. After catalog acceptance, request separate Marketplace review from Teclib and provide the public source, release archive, compatibility evidence, and maintenance contact.

The plugin remains a free beta while that review is pending. No paid stable release is currently offered. Any later one-time commercial offer must be described separately and must preserve the rights granted by GPL-3.0-or-later.
