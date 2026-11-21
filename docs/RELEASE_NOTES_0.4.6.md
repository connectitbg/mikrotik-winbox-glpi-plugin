# Winbox - GLPI 0.4.6 beta

Release date: 2026-11-21

This is a free public beta for GLPI 11.x, exercised on GLPI 11.0.9. The release has no subscription, purchase, or formal support service. Connect IT monitors the public issue tracker and considers fixes on a best-effort basis.

## Included

- Per-device MikroTik connection settings and Winbox launch through a short-lived one-time handoff.
- Optional PuTTY and KiTTY SSH launch handlers.
- Per-router access log entries for client launches and configuration actions.
- Masked RouterOS `.rsc` export and import through RouterOS REST over verified HTTPS.
- Sensitive RouterOS text export and restore over SSH, with SSH host fingerprint pinning.
- A Windows client installer and the client setup instructions served by GLPI.

## Before use

- Back up the GLPI database before installing or upgrading the plugin.
- Use HTTPS for GLPI when exchanging router credentials. HTTP is suitable only for a trusted test network.
- Treat sensitive text exports as secret-bearing files and store them securely. RouterOS sensitive values may be present when the router account has the required `sensitive` policy.
- RouterOS restore can change network settings and disconnect the device. Use a backup for the correct router and RouterOS version.
- The Winbox - GLPI client executable is unsigned. The plugin and client have not completed an independent security review.

## Installation

Extract `glpi-winbox-plugin-0.4.6.zip` into GLPI's `plugins` directory so the package creates `plugins/winbox`, then install and enable **Winbox - GLPI** from **Setup → Plugins**.
