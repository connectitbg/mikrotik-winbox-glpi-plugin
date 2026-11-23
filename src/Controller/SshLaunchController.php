<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SshLaunchController extends AbstractController
{
    private const TOKEN_TTL_SECONDS = 15;

    #[Route('/SshLaunch', name: 'winbox_ssh_launch', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        global $DB, $CFG_GLPI;

        $id = $request->request->getInt('id');
        $client = $request->request->getString('client');
        if (!in_array($client, ['putty', 'sshdiag'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        $allowed = $client === 'sshdiag'
            ? Access::canRunDiagnostics($id)
            : Access::canLaunchEquipment($id);
        if (!$allowed) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        $credentials = Access::credentials($DB, $id);
        if (!$credentials || Access::sshHost($credentials) === '' || trim((string)$credentials['username']) === '') {
            return new Response(__('SSH connection details are not configured for this device.', 'winbox'), 404);
        }

        $token = bin2hex(random_bytes(32));
        $expires_at = time() + self::TOKEN_TTL_SECONDS;
        $DB->delete('glpi_plugin_winbox_tokens', ['expires_at' => ['<=', date('Y-m-d H:i:s')]]);
        $created_by = (int)Session::getLoginUserID();
        if (!$DB->insert('glpi_plugin_winbox_tokens', [
            'token_hash' => hash('sha256', $token),
            'item_id' => $id,
            'launch_type' => $client,
            'expires_at' => date('Y-m-d H:i:s', $expires_at),
            'created_by' => $created_by,
        ])) {
            return new Response(__('Unable to create the SSH launch request.', 'winbox'), 500);
        }

        $equipment = new NetworkEquipment();
        $equipment->getFromDB($id);
        $target = Access::sshHost($credentials) . ':' . $credentials['ssh_port'];
        AccessLog::record(
            $created_by,
            $id,
            'ssh_' . $client . '_handoff_created',
            'pending',
            'saved_credentials_handoff',
            $target
        );

        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $client_name = match ($client) {
            'putty' => 'PuTTY',
            default => 'SSH diagnostic',
        };
        $uri = 'mikrotik-' . $client . '://' . $token;
        $countdown_template = __('Time remaining: %d seconds.', 'winbox');
        $remaining = max(0, $expires_at - time());
        ob_start();
        \Html::header(__('Open SSH client', 'winbox'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid"><div class="card m-3"><div class="card-body text-center"><h2>'
            . $escape(__('Open SSH session', 'winbox')) . ' — ' . $escape($client_name) . '</h2><p>'
            . $escape($equipment->fields['name']) . ' — ' . $escape($target) . '</p><p>'
            . $escape($client === 'sshdiag'
                ? __('The diagnostic makes one non-interactive SSH check with the saved GLPI credentials and a read-only RouterOS identity command. It records only a result category (accepted, rejected, host key, connection, or inconclusive); it does not log the password or router command output.', 'winbox')
                : __('The one-time code expires in 15 seconds. GLPI supplies the saved password to PuTTY through a per-launch, access-restricted Windows pipe, without writing a local password file. The first connection may ask you to confirm the router host key.', 'winbox'))
            . '</p><p data-launch-countdown data-expires-at="' . $escape($expires_at * 1000) . '" data-template="' . $escape($countdown_template) . '" data-expired-text="' . $escape(__('Launch code expired. Return to the device and create a new request.', 'winbox')) . '">'
            . $escape(sprintf($countdown_template, $remaining)) . '</p><a data-launch-button class="btn btn-primary btn-lg" href="' . $escape($uri) . '">'
            . $escape(sprintf(__('Start %s', 'winbox'), $client_name)) . '</a></div></div></div>';
        echo '<p class="text-center"><a href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">'
            . $escape(__('Return to the network device', 'winbox')) . '</a></p>';
        echo \Html::script('plugins/winbox/LaunchCountdown.js');
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/SshLaunch', name: 'winbox_ssh_launch_get_rejected', methods: ['GET'])]
    public function rejectGet(): Response
    {
        return new Response(__('SSH launch requests must be started from the Winbox tab.', 'winbox'), 405, [
            'Allow' => 'POST',
            'Cache-Control' => 'no-store',
        ]);
    }
}
