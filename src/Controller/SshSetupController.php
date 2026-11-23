<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\RouterOsSshClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SshSetupController extends AbstractController
{
    #[Route('/SshSetup', name: 'winbox_ssh_setup', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        global $DB, $CFG_GLPI;

        $id = $request->isMethod('POST')
            ? $request->request->getInt('id')
            : $request->query->getInt('id');
        $equipment = new NetworkEquipment();
        if ($id < 1 || !$equipment->getFromDB($id) || !$equipment->can($id, UPDATE)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        $credentials = Access::credentials($DB, $id);
        if (!$credentials) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $allowed_sources = $request->request->getString('allowed_sources');
        $commands = '';
        $form_error = '';
        $download = $request->request->getBoolean('download_commands');

        if ($request->isMethod('POST')) {
            try {
                $sources = self::parseAllowedSources($allowed_sources);
                $commands = self::buildCommands($sources, (int)$credentials['ssh_port']);
                if ($download) {
                    $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$equipment->fields['name']) ?: 'mikrotik';
                    return new Response($commands, 200, [
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename="' . $safe_name . '-winbox-glpi-ssh-setup.rsc"',
                        'X-Content-Type-Options' => 'nosniff',
                        'Cache-Control' => 'no-store, private',
                    ]);
                }
            } catch (\InvalidArgumentException $exception) {
                $form_error = $exception->getMessage();
            }
        }

        $fingerprints = [];
        $probe_error = '';
        try {
            $fingerprints = (new RouterOsSshClient($credentials))->scanHostKeys();
        } catch (Throwable $exception) {
            $probe_error = $exception->getMessage();
        }

        ob_start();
        \Html::header(__('MikroTik SSH setup', 'winbox'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Prepare MikroTik SSH access', 'winbox')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<p>' . $escape(__('SSH endpoint', 'winbox')) . ': <code>'
            . $escape(Access::sshHost($credentials) . ':' . $credentials['ssh_port']) . '</code></p>';

        if ($fingerprints !== []) {
            echo '<div class="alert alert-success">' . $escape(__('The router SSH endpoint responded. You can now verify and pin its fingerprint.', 'winbox')) . '</div>';
            echo '<ul>';
            foreach ($fingerprints as $key) {
                echo '<li><code>' . $escape($key['type']) . '</code> — <code>' . $escape($key['fingerprint']) . '</code></li>';
            }
            echo '</ul>';
        } else {
            echo '<div class="alert alert-warning"><strong>' . $escape(__('GLPI cannot read an SSH host key from this endpoint.', 'winbox')) . '</strong> '
                . $escape(__('No SSH password is sent during this check. The port may be blocked, the SSH service may be disabled, or its port may differ from the configured value.', 'winbox'))
                . '</div>';
            if ($probe_error !== '') {
                echo '<p class="text-muted"><code>' . $escape($probe_error) . '</code></p>';
            }
        }

        echo '<div class="alert alert-info">' . $escape(__('GLPI cannot configure a router while both SSH and RouterOS REST are unavailable. The commands below must be run once in the router WinBox Terminal by an administrator. They enable SSH on the configured port, restrict the SSH service to the source networks you enter, enable password authentication, and add narrow firewall allow rules for those same sources.', 'winbox')) . '</div>';
        echo '<div class="alert alert-warning">' . $escape(__('Enter the source IP address or CIDR that the router sees for GLPI. If GLPI connects through NAT, enter its public NAT egress address. Include every existing administrator network you still need for SSH; this script replaces the SSH service address allow-list. It will not open SSH to all addresses. Use WinBox Safe Mode when applying router firewall changes remotely.', 'winbox')) . '</div>';

        if ($form_error !== '') {
            echo '<div class="alert alert-danger">' . $escape($form_error) . '</div>';
        }

        echo '<form method="post" action="' . $escape($root . '/plugins/winbox/SshSetup') . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        echo '<div class="mb-3"><label class="form-label" for="winbox_allowed_sources">' . $escape(__('Allowed SSH source IPs/CIDRs (comma separated)', 'winbox')) . '</label>';
        echo '<input class="form-control" id="winbox_allowed_sources" name="allowed_sources" type="text" value="' . $escape($allowed_sources) . '" required placeholder="203.0.113.25/32, 192.168.10.0/24">';
        echo '<div class="form-text">' . $escape(__('The GLPI source address is not assumed from its private server address because NAT may change what the router sees. Do not enter 0.0.0.0/0 or ::/0.', 'winbox')) . '</div></div>';
        echo '<div class="d-flex gap-2 flex-wrap mb-3">';
        echo '<button class="btn btn-primary" type="submit"><i class="ti ti-terminal-2 me-1"></i>' . $escape(__('Generate RouterOS commands', 'winbox')) . '</button>';
        echo '<button class="btn btn-outline-primary" type="submit" name="download_commands" value="1"><i class="ti ti-download me-1"></i>' . $escape(__('Download .rsc setup script', 'winbox')) . '</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape($root . '/plugins/winbox/SshTrust?id=' . $id) . '"><i class="ti ti-shield-check me-1"></i>' . $escape(__('Verify router SSH fingerprint', 'winbox')) . '</a>';
        echo '</div></form>';

        if ($commands !== '') {
            echo '<div class="mb-3"><label class="form-label" for="winbox_ssh_commands">' . $escape(__('RouterOS commands — paste into WinBox Terminal as an administrator', 'winbox')) . '</label>';
            echo '<textarea class="form-control font-monospace" id="winbox_ssh_commands" rows="16" readonly>' . $escape($commands) . '</textarea>';
            echo '<button class="btn btn-outline-secondary mt-2" type="button" data-copy-target="winbox_ssh_commands" data-copied-label="' . $escape(__('Copied', 'winbox')) . '"><i class="ti ti-copy me-1"></i>' . $escape(__('Copy commands', 'winbox')) . '</button></div>';
            echo '<h3>' . $escape(__('Required RouterOS account policies', 'winbox')) . '</h3><ul>';
            echo '<li>' . $escape(__('Text export: ssh, read, and sensitive.', 'winbox')) . '</li>';
            echo '<li>' . $escape(__('Text restore: ssh, read, sensitive, ftp, and write. The write policy permits configuration changes.', 'winbox')) . '</li>';
            echo '</ul><p class="text-muted">' . $escape(__('The script does not change users or groups. Check the router account policy in WinBox before using backup or restore.', 'winbox')) . '</p>';
        }

        echo '<hr><a class="btn btn-outline-secondary" href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">' . $escape(__('Back to Winbox', 'winbox')) . '</a>';
        echo '</div></div></div>';
        echo \Html::script('plugins/winbox/js/ssh-setup.js');
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }

    /** @return list<string> Canonical IP/CIDR values */
    private static function parseAllowedSources(string $raw): array
    {
        $parts = preg_split('/[\\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === [] || count($parts) > 32) {
            throw new \InvalidArgumentException(__('Enter between 1 and 32 allowed source IP addresses or CIDRs.', 'winbox'));
        }

        $sources = [];
        foreach ($parts as $part) {
            $segments = explode('/', $part, 2);
            $address = $segments[0];
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException(__('Each source must be a valid IPv4/IPv6 address, optionally followed by a CIDR prefix.', 'winbox'));
            }

            $is_ipv6 = str_contains($address, ':');
            $max_prefix = $is_ipv6 ? 128 : 32;
            $min_prefix = $is_ipv6 ? 64 : 24;
            $prefix = $segments[1] ?? (string)$max_prefix;
            if (!ctype_digit($prefix) || (int)$prefix < $min_prefix || (int)$prefix > $max_prefix) {
                throw new \InvalidArgumentException(__('Use a host address (/32 IPv4 or /128 IPv6), or a network no broader than /24 IPv4 or /64 IPv6.', 'winbox'));
            }

            $source = $address . '/' . (int)$prefix;
            $sources[$source] = $source;
        }

        return array_values($sources);
    }

    /** @param list<string> $sources */
    private static function buildCommands(array $sources, int $port): string
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(__('The configured SSH port is invalid.', 'winbox'));
        }

        $lines = [
            '# MikroTik Tools for GLPI: restrict RouterOS SSH to the listed source networks.',
            '# Review this script and keep all current administrator source networks in the list.',
            '# Run in WinBox Terminal as a router administrator; use Safe Mode for remote changes.',
            '/ip service set [find where name="ssh"] disabled=no port=' . $port . ' address=' . implode(',', $sources),
            '/ip ssh set password-authentication=yes',
        ];

        foreach ($sources as $index => $source) {
            $number = $index + 1;
            $menu = str_contains($source, ':') ? '/ipv6' : '/ip';
            $comment = 'Winbox-GLPI SSH ' . $number;
            // RouterOS Terminal gives each pasted line a separate local scope.
            // Use independent commands so the generated script works as pasted.
            $lines[] = $menu . ' firewall filter remove [find where comment="' . $comment . '"]';
            $lines[] = $menu . ' firewall filter add chain=input action=accept protocol=tcp dst-port=' . $port . ' src-address=' . $source . ' comment="' . $comment . '" place-before=0';
        }

        $lines[] = '/ip service print detail where name="ssh"';
        $lines[] = '/ip ssh print';
        $lines[] = '/ip firewall filter print where comment~"Winbox-GLPI SSH"';
        $lines[] = '/ipv6 firewall filter print where comment~"Winbox-GLPI SSH"';
        return implode("\n", $lines) . "\n";
    }
}
