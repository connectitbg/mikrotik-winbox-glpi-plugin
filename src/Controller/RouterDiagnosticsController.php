<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use GlpiPlugin\Winbox\Service\RouterOsServiceProbe;
use GlpiPlugin\Winbox\Service\RouterOsSshClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class RouterDiagnosticsController extends AbstractController
{
    #[Route('/RouterDiagnostics', name: 'winbox_router_diagnostics', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        global $DB;

        $id = $request->isMethod('POST')
            ? $request->request->getInt('id')
            : $request->query->getInt('id');
        $equipment = new NetworkEquipment();
        if ($id < 1 || !$equipment->getFromDB($id) || !$equipment->can($id, UPDATE)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
        $credentials = Access::credentials($DB, $id);
        if (!$credentials) {
            return self::page($request, $equipment, null);
        }

        if ($request->isMethod('GET')) {
            return self::page($request, $equipment, null);
        }

        $policy_result = [
            'statuses' => array_fill_keys(['ssh', 'read', 'sensitive', 'ftp', 'write'], 'unknown'),
            'ssh_login' => 'unknown',
            'message' => 'The SSH check did not complete.',
        ];
        try {
            $policy_result = (new RouterOsSshClient($credentials))->inspectUserPolicies();
        } catch (Throwable) {
            // Keep diagnostics secret-safe; raw OpenSSH output can include user data.
        }

        $host = trim((string)($credentials['ip_address'] ?? ''));
        $ssh_host = Access::sshHost($credentials);
        $https_host = trim((string)($credentials['api_host'] ?? '')) ?: $host;
        $services = [
            'ftp' => self::ftpStatus($host, (int)($credentials['ftp_port'] ?? 21)),
            'api' => self::tcpStatus($host, (int)($credentials['routeros_api_port'] ?? 8728)),
            'api_ssl' => self::tlsStatus($host, (int)($credentials['routeros_api_ssl_port'] ?? 8729)),
            'www' => self::tcpStatus($host, (int)($credentials['www_port'] ?? 80)),
            'www_ssl' => self::tlsStatus($https_host, (int)($credentials['www_ssl_port'] ?? 443)),
            'ssh' => self::tcpStatus($ssh_host, (int)($credentials['ssh_port'] ?? 22)),
        ];

        $summary = [];
        foreach ($policy_result['statuses'] as $name => $status) {
            $summary[] = 'policy_' . $name . '=' . $status;
        }
        foreach ($services as $name => $service) {
            $summary[] = 'service_' . $name . '=' . $service['status'];
        }
        AccessLog::record(
            (int)Session::getLoginUserID(),
            $id,
            'routeros_readonly_diagnostic',
            'completed',
            implode('; ', $summary),
            $host
        );

        return self::page($request, $equipment, [
            'policy' => $policy_result,
            'services' => $services,
            'host' => $host,
            'ports' => [
                'ftp' => (int)($credentials['ftp_port'] ?? 21),
                'api' => (int)($credentials['routeros_api_port'] ?? 8728),
                'api_ssl' => (int)($credentials['routeros_api_ssl_port'] ?? 8729),
                'www' => (int)($credentials['www_port'] ?? 80),
                'www_ssl' => (int)($credentials['www_ssl_port'] ?? 443),
                'ssh' => (int)($credentials['ssh_port'] ?? 22),
                'ssh_host' => $ssh_host,
            ],
        ]);
    }

    private static function page(Request $request, NetworkEquipment $equipment, ?array $result): Response
    {
        global $CFG_GLPI;
        $id = (int)$equipment->getID();
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        \Html::header(__('RouterOS diagnostics'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('RouterOS function check')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';

        if ($result === null) {
            echo '<div class="alert alert-info">' . $escape(__('This read-only check connects from the GLPI server. It reads the saved account group policies over SSH and checks the configured service ports. It does not change router configuration, upload files, or send credentials to FTP, API, API-SSL, WWW, or WWW-SSL.')) . '</div>';
            if ($request->isSecure() === false) {
                echo '<div class="alert alert-warning">' . $escape(__('GLPI is using HTTP. Use this test only on the trusted test network.')) . '</div>';
            }
            echo '<form method="post" action="' . $escape($root . '/plugins/winbox/RouterDiagnostics') . '">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
            echo '<button class="btn btn-primary me-2" type="submit"><i class="ti ti-activity me-1"></i>' . $escape(__('Run read-only checks')) . '</button>';
            echo '<a class="btn btn-outline-secondary" href="' . $escape(self::equipmentUrl($root, $id)) . '">' . $escape(__('Cancel')) . '</a>';
            echo '</form>';
        } else {
            echo '<p>' . $escape(__('Checks run from the GLPI server to')) . ' <code>' . $escape($result['host']) . '</code>.</p>';
            echo '<h3>' . $escape(__('RouterOS account policies')) . '</h3>';
            echo '<p>' . $escape($result['policy']['message']) . '</p>';
            echo '<ul class="list-group mb-4">';
            self::renderStatus($escape, __('SSH account policy'), (string)($result['policy']['statuses']['ssh'] ?? 'unknown'), __('SSH policy and the saved account login check.'), true);
            self::renderStatus($escape, __('Read'), (string)($result['policy']['statuses']['read'] ?? 'unknown'), __('RouterOS configuration read permission.'), true);
            self::renderStatus($escape, __('Sensitive'), (string)($result['policy']['statuses']['sensitive'] ?? 'unknown'), __('Permission to view sensitive configuration values.'), true);
            self::renderStatus($escape, __('FTP account policy'), (string)($result['policy']['statuses']['ftp'] ?? 'unknown'), __('RouterOS account policy required for SFTP file operations; this is separate from the FTP service port.'), true);
            self::renderStatus($escape, __('Write'), (string)($result['policy']['statuses']['write'] ?? 'unknown'), __('RouterOS configuration write permission. This is inspected from the user group; no write command is sent.'), true);
            echo '</ul>';

            echo '<h3>' . $escape(__('RouterOS service reachability')) . '</h3>';
            echo '<p>' . $escape(__('A reachable port confirms network access from GLPI only; it does not verify service permissions or authenticate to the service.')) . '</p>';
            echo '<ul class="list-group mb-4">';
            foreach ([
                'ftp' => __('FTP service'),
                'api' => __('API service'),
                'api_ssl' => __('API-SSL service'),
                'www' => __('WWW (HTTP)'),
                'www_ssl' => __('WWW-SSL (HTTPS)'),
                'ssh' => __('SSH service'),
            ] as $key => $label) {
                $service = $result['services'][$key];
                self::renderStatus(
                    $escape,
                    $label . ' — ' . ($key === 'ssh' ? $result['ports']['ssh_host'] . ':' : '') . $result['ports'][$key],
                    (string)$service['status'],
                    (string)$service['message'],
                    false
                );
            }
            echo '</ul>';
            echo '<div class="alert alert-warning">' . $escape(__('Green means the policy was confirmed or the service protocol was reachable. Red means it was explicitly absent or unreachable from GLPI. Amber means the check could not determine the state. An inactive write policy prevents configuration restore; an inactive ftp policy prevents SFTP file transfer.')) . '</div>';
            echo '<form method="post" action="' . $escape($root . '/plugins/winbox/RouterDiagnostics') . '">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
            echo '<button class="btn btn-outline-primary me-2" type="submit"><i class="ti ti-refresh me-1"></i>' . $escape(__('Run checks again')) . '</button>';
            echo '<a class="btn btn-outline-secondary" href="' . $escape(self::equipmentUrl($root, $id)) . '">' . $escape(__('Back to Winbox')) . '</a>';
            echo '</form>';
        }

        echo '</div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }

    private static function renderStatus(callable $escape, string $label, string $status, string $detail, bool $policy): void
    {
        $normalized = match ($status) {
            'active', 'reachable', 'success', 'ftp' => 'active',
            'inactive', 'unreachable', 'failed' => 'inactive',
            default => 'unknown',
        };
        $class = match ($normalized) {
            'active' => 'list-group-item-success',
            'inactive' => 'list-group-item-danger',
            default => 'list-group-item-warning',
        };
        $icon = match ($normalized) {
            'active' => '<i class="ti ti-circle-check text-success me-1" aria-hidden="true"></i>',
            'inactive' => '<i class="ti ti-circle-x text-danger me-1" aria-hidden="true"></i>',
            default => '<i class="ti ti-help-circle text-warning me-1" aria-hidden="true"></i>',
        };
        $status_text = match ($normalized) {
            'active' => __('Active / reachable'),
            'inactive' => __('Inactive / unreachable'),
            default => __('Unknown'),
        };
        if ($policy && $status === 'inactive') {
            $status_text = __('Inactive');
        } elseif ($policy && $status === 'active') {
            $status_text = __('Active');
        }
        echo '<li class="list-group-item ' . $class . '"><strong>' . $icon . $escape($label) . ' — '
            . $escape($status_text) . '</strong><div>' . $escape($detail) . '</div></li>';
    }

    private static function ftpStatus(string $host, int $port): array
    {
        return match (RouterOsServiceProbe::ftpGreeting($host, $port)) {
            'ftp' => ['status' => 'active', 'message' => 'FTP service greeting received; no login was attempted.'],
            'unreachable' => ['status' => 'inactive', 'message' => 'No FTP service connection could be made from GLPI.'],
            default => ['status' => 'unknown', 'message' => 'The port accepted a connection but did not return a recognizable FTP greeting.'],
        };
    }

    private static function tcpStatus(string $host, int $port): array
    {
        $reachable = RouterOsServiceProbe::tcp($host, $port);
        return [
            'status' => $reachable ? 'reachable' : 'unreachable',
            'message' => $reachable
                ? 'TCP connection succeeded from GLPI; no credentials were sent.'
                : 'TCP connection failed from GLPI.',
        ];
    }

    private static function tlsStatus(string $host, int $port): array
    {
        if (RouterOsServiceProbe::tls($host, $port)) {
            return ['status' => 'reachable', 'message' => 'TLS handshake succeeded from GLPI; no credentials were sent. The browser still validates the certificate when opened.'];
        }
        if (RouterOsServiceProbe::tcp($host, $port)) {
            return ['status' => 'unknown', 'message' => 'TCP port is open, but a TLS handshake could not be confirmed.'];
        }
        return ['status' => 'unreachable', 'message' => 'TCP connection failed from GLPI.'];
    }

    private static function equipmentUrl(string $root, int $id): string
    {
        return $root . '/front/networkequipment.form.php?' . http_build_query([
            'id' => $id,
            'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1',
        ]);
    }
}
