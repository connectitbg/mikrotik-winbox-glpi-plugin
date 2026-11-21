# MikroTik Winbox - GLPI Plugin

GLPI 11 plugin for launching the locally installed Winbox client from a network equipment record.

Maintained by [Connect IT](https://connectit.bg).

## Beta status

Version 0.4.7 is a free public beta. There is no subscription, purchase, or formal support service at this stage. Connect IT will monitor the public issue tracker and consider fixes and improvements on a best-effort basis. The beta has been exercised on GLPI 11.0.9; broader compatibility and security review are still in progress. No paid stable release is currently offered. If a commercial stable edition is released later, its terms will be announced separately. The source remains licensed under GPL-3.0-or-later.

## Installation

Download the `glpi-winbox-plugin-0.4.7.zip` asset from the [0.4.7 GitHub release](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/releases/tag/v0.4.7), extract the `winbox` directory into GLPI's `plugins` directory, then install and enable **Winbox - GLPI** from **Setup → Plugins**. Back up the GLPI database before upgrading an existing installation.

## Support and bug reports

Report bugs with the [Bug report form](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/new?template=bug_report.yml). Do not include router passwords, configuration backups, launch codes, public IPs, or customer data in an issue.

## Windows client setup

1. Install the applications you plan to use. Winbox is required for Winbox access; PuTTY or KiTTY is required for its matching SSH button. Use the official links on the GLPI setup page for PuTTY and KiTTY.
2. Open `/plugins/winbox/InstallClient` on the GLPI server and download `Winbox-GLPI-Setup.exe`.
3. Double-click the executable. It copies itself to `%LOCALAPPDATA%\Winbox-GLPI`, registers the `mikrotik://`, `mikrotik-putty://`, and `mikrotik-kitty://` handlers for the signed-in user, verifies each effective Windows handler, checks whether PuTTY/KiTTY are detected, and writes a diagnostic log. Administrator rights and PowerShell commands are not required.
   Users who installed an earlier release should run the updated installer again once to register the PuTTY/KiTTY handlers.
4. In GLPI, open a network equipment record, select **Winbox**, press **Open in Winbox**, then press **Start Winbox** on the handoff page within 90 seconds. Chrome may ask once before opening the external application.
5. For SSH, press **Open SSH in PuTTY** or **Open SSH in KiTTY**, then the matching start button on the handoff page. The client uses the saved host, SSH port, username, and password. The handler checks for the selected client before redeeming the one-time code. PuTTY 0.83 or newer reads the password from a short-lived per-user file; older PuTTY versions prompt. KiTTY receives the password through its `-pass` command-line option, which can be visible to other local processes. The one-time browser code contains no router password, and the launch log never records it.

The client EXE is configured dynamically with the GLPI base URL when downloaded, so the same plugin package can serve it from each GLPI installation. The page keeps the old `.reg` and PowerShell files under a manual fallback section.

## Credential and launch flow

Connection details are stored per network device. The pilot build stores the password as plain text; GLPI controls who can edit or view it. A random one-time code expires after 90 seconds. The browser URI contains only that code. The installed Winbox - GLPI executable exchanges it with GLPI and starts the selected client with the target details. For SSH, the one-time exchange returns the saved password directly to the client; keep the test environment on a trusted network while GLPI uses HTTP.

## Access log

Launch requests for Winbox, PuTTY, and KiTTY, client process outcomes, configuration exports, and imports are written to `mikrotik_access.log` in GLPI's log directory. The file appears under **Administration → Logs → Log files** and can be viewed by profiles with the GLPI system log read right. The device's **Winbox** tab also has an **Access log** button for a per-device view, visible only to users with system log read permission and read access to that device. SSH entries use the configured SSH port and identify the client. They record only that the PuTTY/KiTTY process started; RouterOS authentication is not verified.

## Masked `.rsc` export/import

**Download masked .rsc export** and **Import .rsc backup** are available to users with GLPI configuration update permission and update access to the network device. They use RouterOS v7 REST over HTTPS, with strict certificate and hostname verification. Configure the REST API hostname and HTTPS port in the device's Winbox settings; the account must have the required RouterOS REST API read/write policy. The REST service must be enabled and reachable from the GLPI server.

Download creates a text `.rsc` export with RouterOS sensitive values masked. Import accepts `.rsc` files up to 60,000 bytes, runs RouterOS import dry-run, then imports the file after an explicit confirmation.

## Sensitive text backup/restore over SSH

**Download text backup with sensitive values (SSH)** and **Restore text backup with sensitive values (SSH)** are restricted to users with GLPI configuration update permission and update access to the device. The GLPI server needs OpenSSH (`ssh`, `sftp`, `ssh-keyscan`) and network access to the configured RouterOS SSH port (22 by default). **Username and password** is the default and uses the per-device credentials saved in GLPI. The optional **SSH key, then password fallback** mode also tries an OpenSSH identity available to the GLPI web service account (for example, `www-data` on Debian); if key authentication does not work, it falls back to the saved password. Install the key with filesystem permissions that allow only the GLPI service account to read it. Encrypted private keys must already be unlocked in an SSH agent available to the web service; GLPI does not ask for or store a key passphrase. This is client authentication; the separate **Verify router SSH fingerprint** action pins the router's server identity before any credentials are sent. Host fingerprint changes are rejected until an administrator verifies the new fingerprint.

The RouterOS account needs `ssh`, `read`, and `sensitive`; restore also needs `ftp` and `write`. RouterOS `sensitive` policy controls whether the export may reveal sensitive settings.

The export uses RouterOS `/export show-sensitive terse`. RouterOS sensitive configuration fields (such as Wi-Fi and service secrets) are included only when the account has the `sensitive` policy. Those values are readable text in the downloaded `.rsc`; SSH protects the connection, not the file. RouterOS system-user passwords and SSH private keys are not exported and cannot be recovered from a text export. The export does not use `show-sensitive` for the masked `.rsc` action above.

Restore uploads the `.rsc` using SFTP, runs RouterOS import dry-run, and imports it over SSH after explicit confirmation. It requires file-transfer and write policies and RouterOS v7.16 or later for dry-run. Import can change network settings or make the router unreachable; create a current backup and use a file prepared for that device and RouterOS version. GLPI's test site uses HTTP, so use these password-bearing files only on the trusted test network until HTTPS is configured.

### When SSH is unreachable

The **SSH setup commands** action checks whether the GLPI server can read the router SSH host key and generates a RouterOS script for an administrator to run in WinBox Terminal. It enables SSH on the configured port, sets password authentication, restricts the SSH service address list to the entered source CIDRs, and adds matching narrow input firewall rules. Enter the GLPI source address as the router sees it; if there is NAT, use the public NAT egress IP. Include any existing administrator networks that must retain SSH access because the service allow-list is replaced. The generator refuses IPv4 networks broader than `/24` and IPv6 networks broader than `/64`. The script does not change RouterOS users or groups. Use WinBox Safe Mode for remote firewall changes. After running it, verify the returned SSH fingerprint in GLPI against a trusted record, then pin it.

This action cannot run RouterOS commands remotely while both SSH and the RouterOS REST API are unavailable. RouterOS REST requires the `www-ssl` service or, since RouterOS 7.9, the `www` service; do not enable plain HTTP for credentials. The REST API account also needs the `rest-api` policy.

## Test limits

Use HTTPS for any deployment that exchanges router credentials. Restrict HTTP deployments to trusted test networks and test credentials. The client executable is currently unsigned; sign production releases with the project's code-signing certificate.
