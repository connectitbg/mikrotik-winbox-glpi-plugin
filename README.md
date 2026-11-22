# MikroTik Tools for GLPI

**MikroTik Tools for GLPI** is a GLPI 11 plugin for working with MikroTik RouterOS devices from their network equipment records. It is published by [Connect IT](https://connectit.bg) and keeps the existing GLPI plugin key `winbox` for upgrade compatibility. The Windows installer, protocol names, and per-user data folder retain their existing technical names.

The first stable release is **1.0.0**. The source is licensed under **GPL-3.0-or-later**. See [release notes](docs/RELEASE_NOTES_1.0.0.md) and [support information](SUPPORT.md).

## Features

- Per-device connection settings and access actions for WinBox, PuTTY, KiTTY, and WebFig.
- A Windows setup application that registers browser protocol handlers and installs only missing or incompatible managed clients. Existing compatible installations are left unchanged.
- GLPI access logging, including a per-device access log, subject to GLPI profile rights.
- RouterOS SSH host fingerprint verification and read-only policy/service diagnostics.
- Masked RouterOS `.rsc` export and import through RouterOS REST over HTTPS.
- Sensitive text export and restore over SSH/SFTP, with explicit confirmation and a restore-upload diagnostic.
- A single WebFig action that tries WWW-SSL first and uses WWW only when the TLS endpoint is unavailable.

See the [client setup instructions](client/READ%20ME%20INSTALL.txt) for Windows installation and launch steps.

## Requirements

- GLPI **11.x** (tested on GLPI 11.0.9).
- PHP 8.2 or later, as required by the GLPI version in use.
- A supported RouterOS account and enabled service for each feature. Permissions vary by action; the plugin's diagnostics report the policies/services it can verify.
- For sensitive backup/restore, the GLPI server needs OpenSSH `ssh`, `sftp`, and `ssh-keyscan`, plus network access to the router's configured SSH port.
- For REST export/import, the GLPI server needs network access to the RouterOS REST API over HTTPS.

## Install or upgrade

1. Download the `glpi-winbox-plugin-1.0.0.zip` asset from the [1.0.0 GitHub release](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/tag/v1.0.0).
2. Back up the GLPI database and existing `plugins/winbox` directory.
3. Extract the archive into GLPI's `plugins` directory so the plugin remains at `plugins/winbox`.
4. In GLPI, open **Setup → Plugins**, install or upgrade **MikroTik Tools for GLPI**, then enable it.
5. Open each network equipment record, choose the **Winbox** tab, and enter the connection details for that device.

The display name is updated while the internal plugin key stays `winbox`; this lets GLPI recognize an existing installation. Do not rename the plugin directory.

## Windows client setup

1. Open `/plugins/winbox/InstallClient` on the GLPI server and download `Winbox-GLPI-Setup.exe` to the Windows computer used to access GLPI.
2. Double-click the installer. It registers the `mikrotik://`, `mikrotik-putty://`, `mikrotik-kitty://`, and temporary SSH diagnostic handlers for the signed-in Windows user. Administrator rights, a separate `.reg` file, and manual PowerShell commands are not required.
3. The installer detects the available clients. Compatible existing applications are left unchanged. Missing or incompatible components are downloaded from their official HTTPS sources, verified against pinned SHA-256 hashes, and kept under `%LOCALAPPDATA%\Winbox-GLPI\clients`.
4. Wait for the completion message. Setup and launch information is written to `%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log`. Run the same installer again to repair handlers or retry a failed download.
5. In GLPI, open a device and choose **Open in Winbox**, **Open SSH in PuTTY**, **Open SSH in KiTTY**, or **Open WebFig**. Confirm the browser prompt to open an external application if it appears. Browser security prompts are not disabled.

WinBox launch codes expire after 90 seconds. SSH launch codes have a 5-minute lifetime to allow for the additional handoff step. Codes are single-use and contain no router credentials.

The managed client set includes WinBox 4.5, PuTTY/Plink 0.85, and KiTTY 0.76.1.13. The installer does not replace compatible programs found elsewhere. KiTTY's saved password is passed as a command-line option and may be visible to other local processes; prefer PuTTY for systems where that exposure is unacceptable. KiTTY 0.76.1.13 also has a maintainer-reported risk involving crafted terminal sequences from a connected host; see the [KiTTY issue](https://github.com/cyd01/KiTTY/issues/525).

## Credentials and permissions

Router credentials are stored per device in the GLPI database. In this release, the password is stored as plain text; GLPI profile and item permissions control who can edit or view it. Configure those permissions carefully and use HTTPS for GLPI. Do not use the HTTP test deployment to exchange production credentials.

Launch handoffs use single-use, time-limited codes. The browser URI contains the code rather than the password. SSH clients receive the saved credentials through the local handler. PuTTY uses a short-lived password file; KiTTY uses its `-pass` argument. Regular SSH launch events confirm only that the client process started; they do **not** prove RouterOS authenticated the user.

The global `mikrotik_access.log` is available under **Administration → Logs → Log files**. A per-device **Access log** button is shown only to users with the required GLPI log-read permission and access to that device. Credentials and launch codes are not written to the access log.

## Backup and restore actions

The device's **Winbox** tab groups actions into access, diagnostics, and backup sections. The four backup actions are:

- **Download masked .rsc export** — exports a RouterOS script with sensitive values masked through REST over HTTPS.
- **Import .rsc backup** — checks an uploaded script with a dry run and imports it only after explicit confirmation.
- **Download text backup with sensitive values (SSH)** — uses `/export show-sensitive terse` over verified SSH.
- **Restore text backup with sensitive values (SSH)** — uploads the selected `.rsc` over SFTP, performs a dry run where the RouterOS version supports it, and imports after explicit confirmation.

Sensitive text exports contain readable configuration secrets and must be protected as confidential files. RouterOS user passwords and SSH private keys are not recoverable from these text exports. A restore can change network settings or make the router unreachable; use a current backup prepared for that device and RouterOS version.

SSH actions require a pinned server fingerprint. Verify the displayed fingerprint against a trusted record before pinning it. The restore-upload diagnostic uploads a randomly named temporary probe and attempts to remove it; it does not import configuration. If cleanup cannot be confirmed, review RouterOS **Files** for the reported temporary filename.

## Diagnostics and configuration

The orange diagnostic actions report the `ssh`, `read`, `sensitive`, `ftp`, and `write` user policies where the active RouterOS group can be verified, and check configured FTP, API, API-SSL, WWW, WWW-SSL, and SSH service endpoints. These checks do not change RouterOS configuration. A transport check confirms reachability only; it does not verify API or WebFig credentials. The `write` policy is inspected without issuing a write command.

An optional SSH setup helper can generate RouterOS commands for an administrator to review and run manually. Those commands may change SSH service and firewall settings. They are never sent or executed automatically by the plugin; review them carefully and use RouterOS Safe Mode when applying remote firewall changes.

## Troubleshooting and security

- Windows setup and launch log: `%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-launch.log`.
- Temporary SSH diagnostic log: `%LOCALAPPDATA%\Winbox-GLPI\winbox-glpi-ssh-test.log`.
- For SFTP restore failures, use **Diagnose restore upload** in GLPI; no diagnostic file download is needed.
- If an SSH login is rejected, verify the device's saved username/password and RouterOS SSH authentication policy. The plugin does not bypass RouterOS authentication.
- The Windows executable is unsigned. Use it only when downloaded from a trusted GLPI server. Production deployments should sign client binaries with the publisher's code-signing certificate.
- Protect downloaded backups, use HTTPS, and grant the plugin's configuration and access-log rights only to appropriate GLPI profiles.

## Bug reports

Use the [GitHub bug report form](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/new?template=bug_report.yml). Never include passwords, launch codes, customer data, public router addresses, or unredacted configuration backups.
