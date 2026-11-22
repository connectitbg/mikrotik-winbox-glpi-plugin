# Winbox - GLPI 0.4.8 beta

This free beta release fixes the Windows SSH client setup flow for PuTTY and KiTTY.

## Changes

- The per-user Windows setup EXE now installs managed PuTTY 0.85 and KiTTY 0.76.1.13 copies under `%LOCALAPPDATA%\Winbox-GLPI\clients` and registers the Winbox, PuTTY, KiTTY, and temporary SSH diagnostic protocol handlers.
- The test build adds a temporary **Test SSH login** action using checksum-pinned PuTTY Plink 0.85. It performs a single non-interactive authentication probe with the saved GLPI credentials and classifies the result without logging the password or router output.
- The GLPI launcher prefers the managed SSH client copies so an older copy in `Program Files` or `C:\Tools` cannot silently take precedence.
- SSH client packages are downloaded over HTTPS and checked against pinned SHA-256 hashes before installation. KiTTY archive paths and expanded size are checked before extraction.
- The setup page and client instructions explain the one-file installation flow, retries, log location, and manual fallback. The page presents English instructions first and Bulgarian instructions second.
- The setup page now has separate, visible PuTTY and KiTTY download cards with direct version-pinned package links, plus a clear explanation that the combined EXE registers the Chrome/Windows protocol handlers.
- PuTTY is installed as a standalone per-user executable. No machine-wide install or administrator rights are required.
- SSH launches stop before opening an interactive client when no saved password is available or PuTTY cannot safely accept a password file. Users do not type router credentials at launch.

## Security notes

- KiTTY 0.76.1.13 is the latest release listed by its maintainer. The maintainer's issue tracker documents remote code execution triggered by crafted terminal sequences from a connected host: <https://github.com/cyd01/KiTTY/issues/525>. Prefer PuTTY when connecting to an SSH host you do not fully trust.
- KiTTY receives the saved password through a command-line option. Other local processes may be able to inspect it. PuTTY 0.85 uses its `-pwfile` option with a short-lived per-user file.
- The Windows installer is unsigned. The GLPI test environment uses HTTP; use test credentials only.

## Upgrade

Upgrade the plugin to 0.4.8, then open **Client setup files and instructions** and download `Winbox-GLPI-Setup.exe`. Run the EXE once per Windows user and wait for the completion message. If a download fails, allow access to the official release hosts and run the EXE again. No separate `.reg` file or PowerShell command is needed for normal setup.
