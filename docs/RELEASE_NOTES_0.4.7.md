# Winbox - GLPI 0.4.7 beta

Release date: 2026-11-21

This free beta improves Windows SSH client setup and launch behavior for PuTTY and KiTTY.

## Changes

- The Windows setup executable registers the Winbox, PuTTY, and KiTTY protocols per user and reports whether the two optional SSH clients were detected.
- SSH launch now checks for the selected PuTTY/KiTTY executable before redeeming the one-time GLPI code, so a missing local client does not disclose credentials or consume the handoff.
- Client discovery checks common per-user and system install folders, Windows App Paths, and `PATH`.
- The GLPI client setup page now provides official PuTTY and KiTTY download links, ordered install steps in English and Bulgarian, and SSH-specific troubleshooting guidance.
- PuTTY password automation uses `-pwfile` on PuTTY 0.83 and newer. KiTTY uses its `-pass` option. Process start is logged, but successful RouterOS authentication is not verified.

## Security and compatibility notes

- PuTTY and KiTTY remain separate third-party applications; the Winbox - GLPI setup EXE registers the protocol handlers and does not redistribute either SSH client.
- KiTTY's `-pass` command-line option can expose the password to other local processes. PuTTY 0.83 or newer avoids putting the password on the command line by reading it from a short-lived per-user file.
- Use HTTPS for GLPI when exchanging router credentials. The test GLPI site uses HTTP and should only be used with test credentials on a trusted network.
- The installer is unsigned and intended for the test environment.

## Installation

Upgrade the plugin to 0.4.7, then open the **Client setup files and instructions** page and download `Winbox-GLPI-Setup.exe`. Install the SSH client from its official project site if needed, run the setup EXE again, and confirm that the setup message detects the selected client. No administrator rights, `.reg` file, or PowerShell command is required for handler registration.
