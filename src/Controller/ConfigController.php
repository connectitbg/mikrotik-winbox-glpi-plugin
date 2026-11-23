<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/Config', name: 'winbox_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        global $DB, $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');

        if ($request->isMethod('POST')) {
            if ($request->request->getBoolean('save_profile_permissions')) {
                \Session::checkRight('config', UPDATE);
                $all_parameters = $request->request->all();
                $submitted = is_array($all_parameters['permissions'] ?? null) ? $all_parameters['permissions'] : [];
                $permissions = [];
                foreach (self::profileRows($DB) as $profile) {
                    $profile_id = (string)$profile['id'];
                    $values = is_array($submitted[$profile_id] ?? null) ? $submitted[$profile_id] : [];
                    $edit = isset($values['edit_credentials']);
                    $permissions[$profile_id] = [
                        'launch' => isset($values['launch']),
                        'access_log' => isset($values['access_log']),
                        'view_credentials' => $edit || isset($values['view_credentials']),
                        'edit_credentials' => $edit,
                        'diagnostics' => isset($values['diagnostics']),
                        'backup_restore' => isset($values['backup_restore']),
                    ];
                }

                try {
                    \Config::setConfigurationValues('plugin:winbox', [
                        'profile_permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    ]);
                    \Session::addMessageAfterRedirect(
                        htmlspecialchars(__('Winbox profile permissions were saved.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        false,
                        INFO
                    );
                } catch (\Throwable) {
                    \Session::addMessageAfterRedirect(
                        htmlspecialchars(__('Unable to save Winbox profile permissions.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        false,
                        ERROR
                    );
                }

                return new RedirectResponse($root . '/plugins/winbox/Config');
            }

            // GLPI 11's controller listener validates and consumes the CSRF token before invoking this action.
            $id = $request->request->getInt('id');
            if (!Access::canManageCredentials($id)) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
            }

            $ip = trim($request->request->getString('ip_address'));
            $ssh_host = trim($request->request->getString('ssh_host'));
            $port = $request->request->getInt('port');
            $api_host = trim($request->request->getString('api_host'));
            $api_port = $request->request->getInt('api_port', 443);
            $ftp_port = $request->request->getInt('ftp_port', 21);
            $routeros_api_port = $request->request->getInt('routeros_api_port', 8728);
            $routeros_api_ssl_port = $request->request->getInt('routeros_api_ssl_port', 8729);
            $www_port = $request->request->getInt('www_port', 80);
            $www_ssl_port = $request->request->getInt('www_ssl_port', 443);
            $ssh_port = $request->request->getInt('ssh_port', 22);
            $ssh_auth_method = $request->request->getString('ssh_auth_method', 'password');
            $username = trim($request->request->getString('username'));
            $password = $request->request->getString('password');
            $valid_host = filter_var($ip, FILTER_VALIDATE_IP) !== false
                || filter_var($ip, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            $valid_ssh_host = $ssh_host === ''
                || filter_var($ssh_host, FILTER_VALIDATE_IP) !== false
                || filter_var($ssh_host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            $valid_api_host = $api_host === ''
                || filter_var($api_host, FILTER_VALIDATE_IP) !== false
                || filter_var($api_host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            $valid_service_ports = true;
            foreach ([$ftp_port, $routeros_api_port, $routeros_api_ssl_port, $www_port, $www_ssl_port] as $service_port) {
                if ($service_port < 1 || $service_port > 65535) {
                    $valid_service_ports = false;
                    break;
                }
            }

            $message = '';
            if (!$valid_host || $port < 1 || $port > 65535 || !$valid_api_host || $api_port < 1 || $api_port > 65535 || !$valid_service_ports || !$valid_ssh_host || $ssh_port < 1 || $ssh_port > 65535 || !in_array($ssh_auth_method, ['password', 'key_then_password'], true) || $username === '') {
                $message = __('Enter valid Winbox, REST API, SSH, and RouterOS service ports, valid hosts, and a username.', 'winbox');
            } else {
                $existing = \GlpiPlugin\Winbox\Service\Access::credentials($DB, $id);
                try {
                    if ($password === '' && $existing) {
                        $password = \GlpiPlugin\Winbox\Service\Access::decryptPassword((string)$existing['password']);
                    }
                    $stored_password = \GlpiPlugin\Winbox\Service\Access::encryptPassword($password);
                    $values = [
                        'item_id' => $id,
                        'ip_address' => $ip,
                        'port' => $port,
                        'api_host' => $api_host !== '' ? $api_host : null,
                        'api_port' => $api_port,
                        'ftp_port' => $ftp_port,
                        'routeros_api_port' => $routeros_api_port,
                        'routeros_api_ssl_port' => $routeros_api_ssl_port,
                        'www_port' => $www_port,
                        'www_ssl_port' => $www_ssl_port,
                        'ssh_host' => $ssh_host !== '' ? $ssh_host : null,
                        'ssh_port' => $ssh_port,
                        'ssh_auth_method' => $ssh_auth_method,
                        'username' => $username,
                        'password' => $stored_password,
                    ];
                    $saved = $existing
                        ? $DB->update('glpi_plugin_winbox_credentials', $values, ['item_id' => $id])
                        : $DB->insert('glpi_plugin_winbox_credentials', $values);
                    if (!$saved) {
                        $message = __('Unable to save Winbox connection settings.', 'winbox');
                    }
                } catch (\Throwable) {
                    $message = __('Unable to encrypt the router password. Check that the GLPI security key exists and is readable.', 'winbox');
                }
            }

            if ($message === '') {
                \Session::addMessageAfterRedirect(htmlspecialchars(__('Winbox connection settings were saved.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, INFO);
            } else {
                \Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, ERROR);
            }
            $query = ['id' => $id, 'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1'];
            return new RedirectResponse($root . '/front/networkequipment.form.php?' . http_build_query($query));
        }

        \Session::checkRight('config', UPDATE);
        $profile_rows = self::profileRows($DB);
        $html = '<div class="container-fluid"><div class="card m-3"><div class="card-header"><h2>MikroTik Tools for GLPI</h2></div><div class="card-body">';
        $html .= '<h3>' . htmlspecialchars(__('Delegated profile permissions', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        $html .= '<p>' . htmlspecialchars(__('Choose which existing GLPI profiles can use access actions, view or edit saved connection fields, run diagnostics, use backup/restore actions, and view the device access log. Device-level GLPI read or update rights are still required for each action. Editing credentials automatically includes permission to view them.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $html .= '<p class="text-muted">' . htmlspecialchars(__('Initial defaults: Admin and Super-Admin can use every action. Supervisor and Technician can use access buttons and view access logs. All other profiles start with every permission disabled. GLPI device-level rights still apply.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $html .= '<div class="alert alert-warning">' . htmlspecialchars(__('Router passwords remain encrypted in the GLPI database. The plugin does not save them to client files or the Windows credential store. PuTTY receives a password through a per-launch, access-restricted Windows pipe. WinBox requires the password as a process argument for automatic login; local processes or administrators may inspect it, and WinBox native settings Keep Password or Autosave session may retain session data on that workstation. The plugin does not change these WinBox settings; their behavior is an open item for Teclib review. Native-client auto-login cannot guarantee that credentials never reach or are stored on a workstation. Viewing fields shows the password in clear text; backup/restore may change router configuration.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        $html .= '<form method="post" action="' . htmlspecialchars($root . '/plugins/winbox/Config', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars(\Session::getNewCSRFToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="save_profile_permissions" value="1">';
        $html .= '<div class="table-responsive"><table class="table table-striped align-middle"><thead><tr>';
        $html .= '<th>' . htmlspecialchars(__('GLPI profile', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
        foreach ([
            'launch' => __('Access buttons', 'winbox'),
            'access_log' => __('Access log', 'winbox'),
            'view_credentials' => __('View connection fields', 'winbox'),
            'edit_credentials' => __('Edit connection fields', 'winbox'),
            'diagnostics' => __('Diagnostics', 'winbox'),
            'backup_restore' => __('Backup / restore', 'winbox'),
        ] as $label) {
            $html .= '<th>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($profile_rows as $profile) {
            $html .= '<tr><th scope="row">' . htmlspecialchars($profile['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
            foreach (['launch', 'access_log', 'view_credentials', 'edit_credentials', 'diagnostics', 'backup_restore'] as $permission) {
                $checked = $profile['permissions'][$permission] ? ' checked' : '';
                $html .= '<td><div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[' . (int)$profile['id'] . '][' . $permission . ']" value="1" aria-label="'
                    . htmlspecialchars($profile['name'] . ' — ' . $permission, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . $checked . '></div></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1"></i>'
            . htmlspecialchars(_x('button', 'Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button></form><hr>';
        $html .= '<p>' . htmlspecialchars(__('Connection settings are entered on each network device in its Winbox tab.', 'winbox'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $html .= '<p><a class="btn btn-primary" href="' . htmlspecialchars($root . '/front/networkequipment.php', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(__('Open network devices', 'winbox'), ENT_QUOTES, 'UTF-8') . '</a></p>';
        $html .= '<p><a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($root . '/plugins/winbox/InstallClient', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(__('Client setup files and instructions', 'winbox'), ENT_QUOTES, 'UTF-8') . '</a></p>';
        $html .= '</div></div></div>';
        ob_start();
        \Html::header(__('MikroTik Tools for GLPI', 'winbox'), $_SERVER['PHP_SELF'], 'config', 'plugins');
        echo $html;
        \Html::footer();
        return new Response((string)ob_get_clean());
    }

    /** @return list<array{id:int,name:string,permissions:array<string,bool>}> */
    private static function profileRows($db): array
    {
        $profiles = [];
        foreach ($db->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => ['name']]) as $row) {
            $profiles[(int)$row['id']] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        $configured = Access::configuredProfilePermissions();
        $rows = [];
        foreach ($profiles as $id => $profile) {
            $defaults = Access::defaultPermissionsForProfile($profile['name']);
            $saved = $configured[(string)$id] ?? [];
            $saved = is_array($saved) ? $saved : [];
            $permissions = [];
            foreach ($defaults as $permission => $default) {
                $permissions[$permission] = array_key_exists($permission, $saved) ? (bool)$saved[$permission] : $default;
            }
            if ($permissions['edit_credentials']) {
                $permissions['view_credentials'] = true;
            }
            $rows[] = $profile + ['permissions' => $permissions];
        }

        return $rows;
    }
}
