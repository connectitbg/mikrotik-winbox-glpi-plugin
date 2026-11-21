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

        return self::createTabEntry(__('Winbox'), 0, NetworkEquipment::class, 'ti ti-router');
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
        $can_configure = Session::haveRight('config', UPDATE) && $item->can($id, UPDATE);
        $launch_url = $root . '/plugins/winbox/Launch?id=' . $id;
        $putty_url = $root . '/plugins/winbox/SshLaunch?id=' . $id . '&client=putty';
        $kitty_url = $root . '/plugins/winbox/SshLaunch?id=' . $id . '&client=kitty';
        $access_log_url = $root . '/plugins/winbox/AccessLog?id=' . $id;
        $export_url = $root . '/plugins/winbox/ConfigExport?id=' . $id;
        $import_url = $root . '/plugins/winbox/ConfigImport?id=' . $id;
        $sensitive_export_url = $root . '/plugins/winbox/SensitiveExport?id=' . $id;
        $sensitive_import_url = $root . '/plugins/winbox/SensitiveImport?id=' . $id;
        $ssh_trust_url = $root . '/plugins/winbox/SshTrust?id=' . $id;
        $ssh_setup_url = $root . '/plugins/winbox/SshSetup?id=' . $id;
        $can_view_access_log = Session::haveRight('system_logs', READ);
        $save_url = $root . '/plugins/winbox/Config';
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>' . $escape(__('Winbox connection')) . ' — ' . $escape((string)$item->fields['name']) . '</h2></div><div class="card-body">';

        if ($credentials) {
            echo '<p>' . $escape(__('Configured target')) . ': <code>' . $escape($credentials['ip_address'] . ':' . $credentials['port']) . '</code></p>';
        } else {
            echo '<p class="text-muted">' . $escape(__('No Winbox connection is configured for this device yet.')) . '</p>';
        }

        echo '<div class="d-flex gap-2 flex-wrap mb-3">';
        if ($credentials) {
            echo '<a class="btn btn-primary" href="' . $escape($launch_url) . '"><i class="ti ti-login me-1"></i>' . $escape(__('Open in Winbox')) . '</a>';
            echo '<a class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer" href="' . $escape($putty_url) . '"><i class="ti ti-terminal-2 me-1"></i>' . $escape(__('Open SSH in PuTTY')) . '</a>';
            echo '<a class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer" href="' . $escape($kitty_url) . '"><i class="ti ti-terminal-2 me-1"></i>' . $escape(__('Open SSH in KiTTY')) . '</a>';
        }
        if ($can_view_access_log) {
            echo '<a class="btn btn-outline-secondary" href="' . $escape($access_log_url) . '"><i class="ti ti-list-details me-1"></i>' . $escape(__('Access log')) . '</a>';
        }
        if ($credentials && $can_configure) {
            $trusted_ssh_keys = json_decode((string)($credentials['ssh_host_keys'] ?? ''), true);
            $ssh_key_trusted = is_array($trusted_ssh_keys) && $trusted_ssh_keys !== [];
            echo '<div class="w-100 small mb-1"><span class="badge ' . ($ssh_key_trusted ? 'bg-success' : 'bg-warning text-dark') . '">'
                . $escape($ssh_key_trusted ? __('Router SSH fingerprint pinned') : __('Router SSH fingerprint not pinned'))
                . '</span></div>';
            echo '<a class="btn btn-outline-primary" href="' . $escape($export_url) . '"><i class="ti ti-download me-1"></i>' . $escape(__('Download masked .rsc export')) . '</a>';
            echo '<a class="btn btn-outline-primary" href="' . $escape($import_url) . '"><i class="ti ti-upload me-1"></i>' . $escape(__('Import .rsc backup')) . '</a>';
            echo '<a class="btn btn-outline-danger" href="' . $escape($sensitive_export_url) . '"><i class="ti ti-file-download me-1"></i>' . $escape(__('Download text backup with sensitive values (SSH)')) . '</a>';
            echo '<a class="btn btn-outline-danger" href="' . $escape($sensitive_import_url) . '"><i class="ti ti-file-upload me-1"></i>' . $escape(__('Restore text backup with sensitive values (SSH)')) . '</a>';
            echo '<a class="btn btn-outline-warning" href="' . $escape($ssh_setup_url) . '"><i class="ti ti-terminal-2 me-1"></i>' . $escape(__('SSH setup commands')) . '</a>';
            echo '<a class="btn btn-outline-secondary" href="' . $escape($ssh_trust_url) . '"><i class="ti ti-shield-check me-1"></i>' . $escape(__('Verify router SSH fingerprint')) . '</a>';
        }
        echo '</div>';

        if ($can_configure) {
            echo '<hr><h3>' . $escape(__('Connection details')) . '</h3>';
            echo '<form method="post" action="' . $escape($save_url) . '">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
            self::field('ip_address', __('IP address / hostname'), (string)($credentials['ip_address'] ?? ''), 'text', true);
            self::field('port', __('Winbox port'), (string)($credentials['port'] ?? '8291'), 'number', true, 'min="1" max="65535"');
            self::field('api_host', __('RouterOS REST API hostname (for TLS certificate)'), (string)($credentials['api_host'] ?? ''), 'text', false, 'placeholder="Leave blank to use the IP address / hostname above"');
            self::field('api_port', __('RouterOS REST API HTTPS port'), (string)($credentials['api_port'] ?? '443'), 'number', true, 'min="1" max="65535"');
            self::field('ssh_port', __('RouterOS SSH port'), (string)($credentials['ssh_port'] ?? '22'), 'number', true, 'min="1" max="65535"');
            self::sshAuthMethod((string)($credentials['ssh_auth_method'] ?? 'password'));
            self::field('username', __('User'), (string)($credentials['username'] ?? ''), 'text', true);
            self::field('password', __('Password'), (string)($credentials['password'] ?? ''), 'text', false, 'autocomplete="off"');
            echo '<div class="form-text mb-3">' . $escape(__('Leave the password unchanged to keep the saved value. Only users with configuration rights and update access to this device can see or change it. SSH uses username/password by default; optional key mode tries an OpenSSH identity for the GLPI web service account, then falls back to the password. Use an unencrypted, permission-protected identity or an unlocked SSH agent. The router SSH fingerprint check verifies server identity, not the login method. Masked .rsc actions require RouterOS REST over verified HTTPS. SSH text backup requires SSH/SFTP reachable from GLPI and a pinned router fingerprint; export needs the RouterOS sensitive policy. Restore needs the read, write, and ftp policies. Sensitive values in the text backup are plaintext. Import dry-run requires RouterOS v7.16 or later.')) . '</div>';
            echo '<button class="btn btn-success" type="submit"><i class="ti ti-device-floppy me-1"></i>' . $escape(__('Save Winbox settings')) . '</button>';
            echo '</form>';
        } else {
            echo '<hr><p class="text-muted">' . $escape(__('Connection credentials are hidden. Ask a GLPI administrator to configure them.')) . '</p>';
        }

        echo '</div></div></div>';
        return true;
    }

    private static function field(string $name, string $label, string $value, string $type, bool $required, string $extra = ''): void
    {
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<div class="row mb-3"><label class="col-sm-3 col-form-label" for="winbox_' . $escape($name) . '">' . $escape($label) . '</label>';
        echo '<div class="col-sm-6"><input class="form-control" id="winbox_' . $escape($name) . '" name="' . $escape($name) . '" type="' . $escape($type) . '" value="' . $escape($value) . '"' . ($required ? ' required' : '') . ' ' . $extra . '></div></div>';
    }

    private static function sshAuthMethod(string $value): void
    {
        $escape = static fn($item): string => htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!in_array($value, ['password', 'key_then_password'], true)) {
            $value = 'password';
        }
        echo '<div class="row mb-3"><label class="col-sm-3 col-form-label" for="winbox_ssh_auth_method">'
            . $escape(__('SSH authentication')) . '</label><div class="col-sm-6">';
        echo '<select class="form-select" id="winbox_ssh_auth_method" name="ssh_auth_method">';
        echo '<option value="password"' . ($value === 'password' ? ' selected' : '') . '>' . $escape(__('Username and password (default)')) . '</option>';
        echo '<option value="key_then_password"' . ($value === 'key_then_password' ? ' selected' : '') . '>' . $escape(__('SSH key, then password fallback')) . '</option>';
        echo '</select></div></div>';
    }
}
