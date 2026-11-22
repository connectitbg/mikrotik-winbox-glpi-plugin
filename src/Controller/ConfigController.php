<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/Config', name: 'winbox_config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        \Session::checkRight('config', UPDATE);
        global $DB, $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');

        if ($request->isMethod('POST')) {
            // GLPI 11's controller listener validates and consumes the CSRF token before invoking this action.
            $id = $request->request->getInt('id');
            $equipment = new \NetworkEquipment();
            if ($id < 1 || !$equipment->getFromDB($id) || !$equipment->can($id, UPDATE)) {
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
                $message = __('Enter valid Winbox, REST API, SSH, and RouterOS service ports, valid hosts, and a username.');
            } else {
                $existing = \GlpiPlugin\Winbox\Service\Access::credentials($DB, $id);
                if ($password === '' && $existing) {
                    $password = (string)$existing['password'];
                }
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
                    'password' => $password,
                ];
                $saved = $existing
                    ? $DB->update('glpi_plugin_winbox_credentials', $values, ['item_id' => $id])
                    : $DB->insert('glpi_plugin_winbox_credentials', $values);
                if (!$saved) {
                    $message = __('Unable to save Winbox connection settings.');
                }
            }

            if ($message === '') {
                \Session::addMessageAfterRedirect(htmlspecialchars(__('Winbox connection settings were saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, INFO);
            } else {
                \Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, ERROR);
            }
            $query = ['id' => $id, 'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1'];
            return new RedirectResponse($root . '/front/networkequipment.form.php?' . http_build_query($query));
        }

        $html = '<div class="container-fluid"><div class="card m-3"><div class="card-header"><h2>MikroTik Tools for GLPI</h2></div><div class="card-body">';
        $html .= '<p>' . htmlspecialchars(__('Connection settings are entered on each network device in its Winbox tab.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $html .= '<p><a class="btn btn-primary" href="' . htmlspecialchars($root . '/front/networkequipment.php', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(__('Open network devices'), ENT_QUOTES, 'UTF-8') . '</a></p>';
        $html .= '<p><a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($root . '/plugins/winbox/InstallClient', ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(__('Client setup files and instructions'), ENT_QUOTES, 'UTF-8') . '</a></p>';
        $html .= '</div></div></div>';
        ob_start();
        \Html::header(__('MikroTik Tools for GLPI'), $_SERVER['PHP_SELF'], 'config', 'plugins');
        echo $html;
        \Html::footer();
        return new Response((string)ob_get_clean());
    }
}
