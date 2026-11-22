<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SshLaunchController extends AbstractController
{
    private const TOKEN_TTL_SECONDS = 300;

    #[Route('/SshLaunch', name: 'winbox_ssh_launch', methods: ['GET'])]
    public function __invoke(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        global $DB, $CFG_GLPI;

        $id = $request->query->getInt('id');
        $client = $request->query->getString('client');
        if (!in_array($client, ['putty', 'kitty', 'sshdiag'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if (!Access::canReadEquipment($id)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        $credentials = Access::credentials($DB, $id);
        if (!$credentials || Access::sshHost($credentials) === '' || trim((string)$credentials['username']) === '') {
            return new Response(__('SSH connection details are not configured for this device.'), 404);
        }

        $token = bin2hex(random_bytes(32));
        $DB->delete('glpi_plugin_winbox_tokens', ['expires_at' => ['<', date('Y-m-d H:i:s')]]);
        $created_by = (int)Session::getLoginUserID();
        if (!$DB->insert('glpi_plugin_winbox_tokens', [
            'token_hash' => hash('sha256', $token),
            'item_id' => $id,
            'launch_type' => $client,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS),
            'created_by' => $created_by,
        ])) {
            return new Response(__('Unable to create the SSH launch request.'), 500);
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
            'kitty' => 'KiTTY',
            default => 'SSH diagnostic',
        };
        $uri = 'mikrotik-' . $client . '://' . $token;
        ob_start();
        \Html::header(__('Open SSH client'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid"><div class="card m-3"><div class="card-body text-center"><h2>'
            . $escape(__('Open SSH session')) . ' — ' . $escape($client_name) . '</h2><p>'
            . $escape($equipment->fields['name']) . ' — ' . $escape($target) . '</p><p>'
            . $escape($client === 'sshdiag'
                ? __('The diagnostic makes one non-interactive SSH check with the saved GLPI credentials and a read-only RouterOS identity command. It records only a result category (accepted, rejected, host key, connection, or inconclusive); it does not log the password or router command output.')
                : __('The one-time code expires in 5 minutes. GLPI supplies the saved password to the SSH client for this launch. PuTTY 0.83 or newer uses a temporary password file; KiTTY uses its password option, which may be visible to other local processes. The first connection may ask you to confirm the router host key.'))
            . '</p><a class="btn btn-primary btn-lg" href="' . $escape($uri) . '">'
            . $escape(sprintf(__('Start %s'), $client_name)) . '</a></div></div></div>';
        echo '<p class="text-center"><a href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">'
            . $escape(__('Return to the network device')) . '</a></p>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
