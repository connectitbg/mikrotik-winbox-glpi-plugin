# MikroTik Tools for GLPI 1.0.2 — security review candidate

This candidate addresses the findings in Teclib's pre-publication review on [GitHub issue #1](https://github.com/connectitbg/mikrotik-winbox-glpi-plugin/issues/1). It is prepared for test-environment verification and has not yet been published as a GitHub release or resubmitted to the GLPI catalogue.

## Security changes

- Router passwords are encrypted at rest with GLPI's `GLPIKey`. The plugin upgrade migrates existing plaintext rows. The password field is a blank password input and does not print the stored value back into HTML.
- WinBox and SSH launch actions require both GLPI configuration-update permission and update access to the network equipment. The same checks run in the UI and server endpoints.
- Launch-code creation now uses CSRF-protected POST requests instead of GET requests, so cross-site page loads cannot create audit events or tokens.
- SSH host keys are pinned only if the freshly scanned fingerprint set matches the set displayed and confirmed in the form.
- Existing table changes use GLPI `Migration` helpers and query-builder updates.
- Client configuration files use GLPI's configured base URL instead of the incoming HTTP `Host` header.
- SSH setup JavaScript is emitted through `Html::script()`.
- Plugin UI messages use the `winbox` gettext domain. The Winbox handoff page uses English source strings with Bulgarian translations; the compiled catalog is included in the package. The installer guide remains deliberately bilingual.
- Remove KiTTY launch support, protocol registration, and installation from client setup. KiTTY automatic password delivery requires a command-line password argument; PuTTY remains the supported SSH GUI client. The new installer unregisters the plugin-specific KiTTY protocol but leaves separately installed KiTTY applications untouched.

## Compatibility and validation

- GLPI compatibility remains `^11.0 || ^12.0`.
- Validated on the Connect IT GLPI 12.0.2 test server: plugin 1.0.2 is enabled, all 29 plugin PHP files pass `php -l`, the cache clears successfully, the existing credential rows were migrated to encrypted values and decrypt successfully with the configured GLPI key, and the client installation page returns HTTP 200. The Bulgarian gettext catalog was checked for valid MO tables and translations before packaging.
- Launch endpoints now accept POST for token creation and explicitly reject GET with HTTP 405. Unauthenticated test requests are redirected to GLPI login (HTTP 302); an authenticated request-method check remains to be completed in the browser.
- No router was contacted or changed during this review.
- GitHub publication and Teclib's follow-up review remain pending; the 1.0.2 release asset URL will resolve after the release is published.

## Remaining before public resubmission

- Publish the matching `v1.0.2` GitHub tag and `glpi-winbox-plugin-1.0.2.zip` release asset, then verify the catalog URL downloads that exact archive. The version-specific URL in `winbox.xml` is intentionally a release target and will return 404 until the release exists.
- Run the authenticated HTTP-method check for both launch endpoints from a signed-in GLPI session; unauthenticated requests stop at GLPI's login redirect before reaching the route method check.
- Send the updated XML and release link to Teclib after the GitHub asset is live.

## Quality notes

- A full Twig-template refactor remains a maintainability improvement; the existing controller pages still render escaped HTML directly.
- The Windows installer is unsigned. Signing requires a Connect IT code-signing certificate; no certificate was available in the test environment.
- KiTTY is no longer integrated. The plugin-managed setup does not install it or delete independent KiTTY installations.

## Operational note

GLPI's `glpicrypt.key` must be included in secure GLPI backups. Restoring the database without its matching key prevents the plugin from decrypting saved router passwords.

Database backups made before this upgrade still contain plaintext router passwords; the migration only changes live rows.
