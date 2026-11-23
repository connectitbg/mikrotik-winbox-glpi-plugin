<?php

namespace GlpiPlugin\Winbox;

use CommonGLPI;
use NetworkEquipment;
use Session;

final class WinboxTab extends \CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof NetworkEquipment || !$item->getID()) {
            return '';
        }

        return self::createTabEntry(__('Winbox', 'winbox'), 0, NetworkEquipment::class, 'ti ti-router');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof NetworkEquipment || !$item->getID() || !$item->can($item->getID(), READ)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }

        global $DB, $CFG_GLPI;
        $id = (int)$item->getID();
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $credentials = \GlpiPlugin\Winbox\Service\Access::credentials($DB, $id);
        $can_launch = \GlpiPlugin\Winbox\Service\Access::canLaunchEquipment($id);
        $can_view_credentials = \GlpiPlugin\Winbox\Service\Access::canViewCredentials($id);
        $can_edit_credentials = \GlpiPlugin\Winbox\Service\Access::canManageCredentials($id);
        $can_run_diagnostics = \GlpiPlugin\Winbox\Service\Access::canRunDiagnostics($id);
        $can_use_backup_restore = \GlpiPlugin\Winbox\Service\Access::canUseBackupRestore($id);
        $password_value = '';
        $password_unavailable = false;
        if ($credentials && ($can_view_credentials || $can_edit_credentials)) {
            try {
                $password_value = \GlpiPlugin\Winbox\Service\Access::decryptPassword((string)$credentials['password']);
            } catch (\Throwable) {
                $password_unavailable = true;
            }
        }
        $launch_url = $root . '/plugins/winbox/Launch';
        $webfig_url = $root . '/plugins/winbox/OpenWebFig?id=' . $id;
        $ssh_launch_url = $root . '/plugins/winbox/SshLaunch';
        $ssh_diagnostic_url = $root . '/plugins/winbox/SshLaunch';
        $access_log_url = $root . '/plugins/winbox/AccessLog?id=' . $id;
        $export_url = $root . '/plugins/winbox/ConfigExport?id=' . $id;
        $import_url = $root . '/plugins/winbox/ConfigImport?id=' . $id;
        $sensitive_export_url = $root . '/plugins/winbox/SensitiveExport?id=' . $id;
        $sensitive_import_url = $root . '/plugins/winbox/SensitiveImport?id=' . $id;
        $sensitive_restore_diagnostic_url = $root . '/plugins/winbox/SensitiveRestoreDiagnostic?id=' . $id;
        $router_diagnostics_url = $root . '/plugins/winbox/RouterDiagnostics?id=' . $id;
        $ssh_trust_url = $root . '/plugins/winbox/SshTrust?id=' . $id;
        $ssh_setup_url = $root . '/plugins/winbox/SshSetup?id=' . $id;
        $install_client_url = $root . '/plugins/winbox/InstallClient';
        $can_view_access_log = \GlpiPlugin\Winbox\Service\Access::canViewAccessLog($id);
        $save_url = $root . '/plugins/winbox/Config';
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>' . $escape(__('Winbox connection', 'winbox')) . ' — ' . $escape((string)$item->fields['name']) . '</h2></div><div class="card-body">';

        if ($credentials && $can_launch) {
            echo '<p>' . $escape(__('Configured target', 'winbox')) . ': <code>' . $escape($credentials['ip_address'] . ':' . $credentials['port']) . '</code></p>';
            echo '<p>' . $escape(__('SSH target', 'winbox')) . ': <code>' . $escape(\GlpiPlugin\Winbox\Service\Access::sshHost($credentials) . ':' . $credentials['ssh_port']) . '</code></p>';
        } elseif ($credentials) {
            echo '<p class="text-muted">' . $escape(__('You do not have permission to launch clients for this device.', 'winbox')) . '</p>';
        } else {
            echo '<p class="text-muted">' . $escape(__('No Winbox connection is configured for this device yet.', 'winbox')) . '</p>';
        }

        if ($can_launch || ($credentials && $can_view_access_log)) {
            echo '<section class="mb-4"><h3 class="h5">' . $escape(__('Access', 'winbox')) . '</h3>';
            echo '<div class="d-flex gap-2 flex-wrap">';
            if ($can_launch) {
                if ($credentials) {
                    self::launchButton($launch_url, $id, null, 'btn btn-primary', 'ti ti-login', __('Open in Winbox', 'winbox'));
                    echo '<a class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer" href="' . $escape($webfig_url) . '"><i class="ti ti-world me-1"></i>' . $escape(__('Open WebFig (HTTPS preferred)', 'winbox')) . '</a>';
                    self::launchButton($ssh_launch_url, $id, 'putty', 'btn btn-outline-primary', 'ti ti-terminal-2', __('Open SSH in PuTTY', 'winbox'));
                }
            }
            if ($credentials && $can_view_access_log) {
                echo '<a class="btn btn-outline-secondary" href="' . $escape($access_log_url) . '"><i class="ti ti-list-details me-1"></i>' . $escape(__('Access log', 'winbox')) . '</a>';
            }
            echo '</div>';
            if ($can_launch) {
                echo '<div class="mt-2"><a class="btn btn-success" target="_blank" rel="noopener noreferrer" href="' . $escape($install_client_url) . '"><i class="ti ti-download me-1"></i>' . $escape(__('Client setup files and instructions', 'winbox')) . '</a></div>';
            }
            echo '</section>';
        }
        if ($credentials && $can_run_diagnostics) {
            echo '<hr><section class="mb-4"><h3 class="h5">' . $escape(__('Diagnostics', 'winbox')) . '</h3>';
            echo '<div class="d-flex gap-2 flex-wrap">';
            $trusted_ssh_keys = json_decode((string)($credentials['ssh_host_keys'] ?? ''), true);
            $ssh_key_trusted = is_array($trusted_ssh_keys) && $trusted_ssh_keys !== [];
            echo '<div class="w-100 small mb-1"><span class="badge ' . ($ssh_key_trusted ? 'bg-success' : 'bg-warning text-dark') . '">'
                . $escape($ssh_key_trusted ? __('Router SSH fingerprint pinned', 'winbox') : __('Router SSH fingerprint not pinned', 'winbox'))
                . '</span></div>';
            self::launchButton($ssh_diagnostic_url, $id, 'sshdiag', 'btn btn-outline-warning', 'ti ti-stethoscope', __('Test SSH login', 'winbox'));
            if ($can_use_backup_restore) {
                echo '<a class="btn btn-outline-warning" href="' . $escape($sensitive_restore_diagnostic_url) . '"><i class="ti ti-stethoscope me-1"></i>' . $escape(__('Diagnose restore upload', 'winbox')) . '</a>';
            }
            echo '<a class="btn btn-outline-warning" href="' . $escape($router_diagnostics_url) . '"><i class="ti ti-activity me-1"></i>' . $escape(__('Check RouterOS functions', 'winbox')) . '</a>';
            echo '<a class="btn btn-outline-warning" href="' . $escape($ssh_setup_url) . '"><i class="ti ti-terminal-2 me-1"></i>' . $escape(__('SSH setup commands', 'winbox')) . '</a>';
            if ($can_edit_credentials) {
                echo '<a class="btn btn-outline-warning" href="' . $escape($ssh_trust_url) . '"><i class="ti ti-shield-check me-1"></i>' . $escape(__('Verify router SSH fingerprint', 'winbox')) . '</a>';
            }
            echo '</div></section>';
        }
        if ($credentials && $can_use_backup_restore) {
            echo '<hr><section class="mb-4"><h3 class="h5">' . $escape(__('Backups', 'winbox')) . '</h3>';
            echo '<div class="d-flex gap-2 flex-wrap">';
            echo '<a class="btn btn-outline-danger" href="' . $escape($export_url) . '"><i class="ti ti-download me-1"></i>' . $escape(__('Download masked .rsc export', 'winbox')) . '</a>';
            echo '<a class="btn btn-outline-danger" href="' . $escape($import_url) . '"><i class="ti ti-upload me-1"></i>' . $escape(__('Import .rsc backup', 'winbox')) . '</a>';
            echo '<a class="btn btn-outline-danger" href="' . $escape($sensitive_export_url) . '"><i class="ti ti-file-download me-1"></i>' . $escape(__('Download text backup with sensitive values (SSH)', 'winbox')) . '</a>';
            echo '<a class="btn btn-outline-danger" href="' . $escape($sensitive_import_url) . '"><i class="ti ti-file-upload me-1"></i>' . $escape(__('Restore text backup with sensitive values (SSH)', 'winbox')) . '</a>';
            echo '</div></section>';
        }

        if ($can_view_credentials || $can_edit_credentials) {
            $read_only = !$can_edit_credentials;
            echo '<hr><h3>' . $escape(__('Connection details', 'winbox')) . '</h3>';
            if (!$read_only) {
                echo '<form method="post" action="' . $escape($save_url) . '">';
                echo '<input type="hidden" name="id" value="' . $id . '">';
                echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
            } else {
                echo '<div class="winbox-connection-readonly">';
            }
            self::field('ip_address', __('IP address / hostname', 'winbox'), (string)($credentials['ip_address'] ?? ''), 'text', true, '', $read_only);
            self::field('port', __('Winbox port', 'winbox'), (string)($credentials['port'] ?? '8291'), 'number', true, 'min="1" max="65535"', $read_only);
            self::field('api_host', __('RouterOS HTTPS hostname (REST API / WebFig certificate)', 'winbox'), (string)($credentials['api_host'] ?? ''), 'text', false, 'placeholder="Leave blank to use the IP address / hostname above"', $read_only);
            self::field('api_port', __('RouterOS REST API HTTPS port (www-ssl)', 'winbox'), (string)($credentials['api_port'] ?? '443'), 'number', true, 'min="1" max="65535"', $read_only);
            self::field('ssh_host', __('SSH host / IP', 'winbox'), (string)($credentials['ssh_host'] ?? ''), 'text', false, 'placeholder="Leave blank to use the Winbox address"', $read_only);
            self::field('ssh_port', __('RouterOS SSH port', 'winbox'), (string)($credentials['ssh_port'] ?? '22'), 'number', true, 'min="1" max="65535"', $read_only);
            echo '<hr><h4>' . $escape(__('RouterOS service ports', 'winbox')) . '</h4>';
            echo '<p class="text-muted">' . $escape(__('These ports are checked from the GLPI server. The FTP service port is separate from SFTP: text restore transfers files over the SSH port and requires the RouterOS ftp policy.', 'winbox')) . '</p>';
            self::field('ftp_port', __('RouterOS FTP service port', 'winbox'), (string)($credentials['ftp_port'] ?? '21'), 'number', true, 'min="1" max="65535"', $read_only);
            echo '<div class="form-text mb-3">' . $escape(__('This is the FTP network service port. The ftp account policy is checked separately; SFTP backup and restore use the SSH port above.', 'winbox')) . '</div>';
            self::field('routeros_api_port', __('RouterOS API port', 'winbox'), (string)($credentials['routeros_api_port'] ?? '8728'), 'number', true, 'min="1" max="65535"', $read_only);
            self::field('routeros_api_ssl_port', __('RouterOS API-SSL port', 'winbox'), (string)($credentials['routeros_api_ssl_port'] ?? '8729'), 'number', true, 'min="1" max="65535"', $read_only);
            self::field('www_port', __('RouterOS WWW (HTTP) port', 'winbox'), (string)($credentials['www_port'] ?? '80'), 'number', true, 'min="1" max="65535"', $read_only);
            self::field('www_ssl_port', __('RouterOS WWW-SSL (HTTPS) port', 'winbox'), (string)($credentials['www_ssl_port'] ?? '443'), 'number', true, 'min="1" max="65535"', $read_only);
            self::sshAuthMethod((string)($credentials['ssh_auth_method'] ?? 'password'), $read_only);
            self::field('username', __('User', 'winbox'), (string)($credentials['username'] ?? ''), 'text', true, '', $read_only);
            if ($password_unavailable) {
                echo '<div class="alert alert-danger">' . $escape(__('The saved password could not be decrypted. Check that this GLPI instance has its original security key.', 'winbox')) . '</div>';
            }
            self::field('password', __('Password', 'winbox'), $password_value, 'text', false, 'autocomplete="off"', $read_only);
            echo '<div class="form-text mb-3">' . $escape(__('Router passwords are encrypted in GLPI. The launcher does not save them to client files, registry values, or Windows Credential Manager. PuTTY uses a per-launch Windows pipe. WinBox receives the password in its process arguments for automatic login; local processes or administrators may inspect it, and WinBox native settings Keep Password or Autosave session may retain session data on that workstation. The plugin does not change these WinBox settings; their behavior is an open item for Teclib review. Native-client auto-login cannot guarantee that credentials never reach or are stored on a workstation. Keep GLPI on HTTPS and grant launch rights only to trusted staff. SSH uses username/password by default; optional key mode tries an OpenSSH identity for the GLPI web service account, then falls back to the password. Use an unencrypted, permission-protected identity or an unlocked SSH agent. The router SSH fingerprint check verifies server identity, not the login method. Masked .rsc actions require RouterOS REST over verified HTTPS. SSH text backup requires SSH/SFTP reachable from GLPI and a pinned router fingerprint; export needs the RouterOS sensitive policy. Restore needs the read, write, and ftp policies. Sensitive values in the text backup are plaintext. Import dry-run requires RouterOS v7.16 or later.', 'winbox')) . '</div>';
            if (!$read_only) {
                echo '<button class="btn btn-success" type="submit"><i class="ti ti-device-floppy me-1"></i>' . $escape(__('Save Winbox settings', 'winbox')) . '</button>';
                echo '</form>';
            } else {
                echo '</div>';
            }
        } else {
            echo '<hr><p class="text-muted">' . $escape(__('Connection fields are hidden for your profile. Contact a GLPI administrator to grant view or edit permission.', 'winbox')) . '</p>';
        }

        echo '</div></div></div>';
        return true;
    }

    private static function field(string $name, string $label, string $value, string $type, bool $required, string $extra = '', bool $read_only = false): void
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="row mb-3"><label class="col-sm-3 col-form-label" for="winbox_' . $escape($name) . '">' . $escape($label) . '</label>';
        echo '<div class="col-sm-6"><input class="form-control" id="winbox_' . $escape($name) . '"' . ($read_only ? '' : ' name="' . $escape($name) . '"') . ' type="' . $escape($type) . '" value="' . $escape($value) . '"' . ($required && !$read_only ? ' required' : '') . ($read_only ? ' readonly' : '') . ' ' . $extra . '></div></div>';
    }

    private static function launchButton(
        string $action,
        int $id,
        ?string $client,
        string $class,
        string $icon,
        string $label
    ): void {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<form class="d-inline" method="post" target="_blank" action="' . $escape($action) . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        if ($client !== null) {
            echo '<input type="hidden" name="client" value="' . $escape($client) . '">';
        }
        echo '<button class="' . $escape($class) . '" type="submit"><i class="' . $escape($icon) . ' me-1"></i>' . $escape($label) . '</button>';
        echo '</form>';
    }

    private static function sshAuthMethod(string $value, bool $read_only = false): void
    {
        $escape = static fn($item): string => htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!in_array($value, ['password', 'key_then_password'], true)) {
            $value = 'password';
        }
        echo '<div class="row mb-3"><label class="col-sm-3 col-form-label" for="winbox_ssh_auth_method">'
            . $escape(__('SSH authentication', 'winbox')) . '</label><div class="col-sm-6">';
        echo '<select class="form-select" id="winbox_ssh_auth_method"' . ($read_only ? '' : ' name="ssh_auth_method"') . ($read_only ? ' disabled' : '') . '>';
        echo '<option value="password"' . ($value === 'password' ? ' selected' : '') . '>' . $escape(__('Username and password (default)', 'winbox')) . '</option>';
        echo '<option value="key_then_password"' . ($value === 'key_then_password' ? ' selected' : '') . '>' . $escape(__('SSH key, then password fallback', 'winbox')) . '</option>';
        echo '</select></div></div>';
    }
}
