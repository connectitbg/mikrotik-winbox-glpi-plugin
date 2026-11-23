# MikroTik Tools for GLPI 1.0.2

Stable release, 23 November 2026. This release records the security and compatibility corrections made in response to the pre-publication review tracked in [GitHub issue #1](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/1).

## Security and access control

- Router passwords are encrypted at rest with GLPI's `GLPIKey`. Installation and upgrade migrate existing plaintext credential rows. The key must be backed up with the GLPI database; old database backups may still contain plaintext passwords.
- The plugin configuration page now has a per-profile permission matrix for client access, access logs, connection-field viewing, connection-field editing, diagnostics, and backup/restore. Existing administrator choices are preserved; new or unknown profiles start denied.
- Default permissions grant all six actions to Admin and Super-Admin, client access and access-log visibility to Supervisor and Technician, and no plugin actions to other profiles. Editing credentials also grants field visibility. Device-level GLPI read/update rights remain required.
- Launch-token creation uses CSRF-protected POST requests. Tokens are single-use and expire after 15 seconds. WinBox and SSH handoff pages display a live countdown and disable the start link when the code expires.
- Launch tokens contain no router password. Access checks run on the server before credentials are released. Access logs report client startup; they do not claim that RouterOS accepted the credentials.
- SSH host-key pins are saved only when the fresh fingerprint set exactly matches the set shown to and confirmed by the administrator.
- Schema updates use GLPI `Migration` helpers, and database access uses GLPI's query builder. Generated download and handler URLs use the configured GLPI base URL instead of the incoming HTTP `Host` header.

## Windows client and interface

- The per-user installer registers WinBox, PuTTY, and SSH diagnostic protocol handlers, installs only missing/incompatible managed clients, verifies downloads against pinned SHA-256 hashes, and leaves compatible applications found elsewhere unchanged.
- PuTTY/Plink receive the saved password through a per-launch, access-restricted Windows named pipe. The launcher does not create a local password file or store router passwords in the registry or Windows Credential Manager.
- KiTTY integration and installation were removed because its automatic-password option exposes the password in process arguments. The installer unregisters only the plugin-specific KiTTY protocol and does not remove independent KiTTY installations.
- Network-device records show a client setup/instructions link to profiles allowed to launch clients. It opens in a new tab and sits on its own row below the access buttons. The link remains available in plugin configuration.
- The countdown script is served through a GLPI route so it works with GLPI 12's public document root. Plugin interface strings use the `winbox` translation domain; the compiled Bulgarian catalog is included. Client installation instructions remain Bulgarian and English, while catalog metadata and release documentation are English.

## Existing features documented for this release

- WinBox, PuTTY, and WebFig access; WebFig prefers WWW-SSL and falls back to WWW.
- Per-device access logs, router SSH fingerprint verification, and read-only RouterOS policy/service diagnostics.
- Masked `.rsc` export/import through RouterOS REST over HTTPS.
- Sensitive text backup/restore through SSH/SFTP, restore-upload diagnostics, and RouterOS policy requirements.
- The global `mikrotik_access.log` is managed separately from plugin tables and remains in GLPI's log directory after plugin removal.

## Compatibility and validation

- The plugin declares support for GLPI `^11.0 || ^12.0` and requires PHP 8.2 or later.
- The 1.0.2 candidate was deployed to the GLPI 12.0.2 test instance. All 30 plugin PHP files passed `php -l`; GLPI cache clearing succeeded; Apache remained active; the installer page and countdown-script route returned HTTP 200. The served countdown script matched the packaged source.
- The project owner manually confirmed that the profile permission matrix works, the countdown runs, and the device-level installer link appears in the intended position on the GLPI 12.0.2 test instance.
- GLPI 11.0.9 was exercised with the earlier stable release; the current 1.0.2 review cycle was validated on GLPI 12.0.2. A full 1.0.2 regression on GLPI 11.0.9 is not claimed here.
- No router was contacted or changed during the release validation.

## Known limitations and remaining review

- WinBox's documented automatic-login interface requires the password as a process argument. Local processes or administrators may inspect it. WinBox's native **Keep Password** and **Autosave session** settings may retain session data, and the plugin does not control them. This is disclosed for Teclib's review; native-client login cannot guarantee zero workstation exposure or storage.
- PuTTY and WinBox are native clients on the user's workstation. A server-side remote-access solution would be needed to keep client-delivered credentials entirely off that workstation.
- The installer is unsigned. A Connect IT code-signing certificate is required to sign it.
- The authenticated HTTP-method check for GET requests to both launch-creation endpoints remains a follow-up verification item. The routes declare POST as the only allowed method.
- Teclib's catalog update and separate Marketplace decision remain pending. GitHub release publication does not itself approve or publish the plugin in either Teclib service.

## Upgrade notes

1. Back up the GLPI database and the existing `plugins/winbox` directory.
2. Back up and protect GLPI's encryption key with the database backup. Without the matching key, migrated router passwords cannot be decrypted.
3. Replace the plugin files while keeping the directory name `winbox`, then run the plugin upgrade from **Setup → Plugins**.
4. Verify the profile permission matrix and re-run the Windows installer for users whose protocol handlers need repair.

The GLPI plugin key and directory remain `winbox` for upgrade compatibility.
