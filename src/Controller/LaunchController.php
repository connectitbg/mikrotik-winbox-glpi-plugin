<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LaunchController extends AbstractController
{
    #[Route('/Launch', name: 'winbox_launch', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        global $DB;
        $id = $request->request->getInt('id');
        if (!\GlpiPlugin\Winbox\Service\Access::canLaunchEquipment($id)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
        $credential = \GlpiPlugin\Winbox\Service\Access::credentials($DB, $id);
        if (!$credential || $credential['ip_address'] === '' || $credential['username'] === '') {
            \GlpiPlugin\Winbox\Service\AccessLog::record(
                (int)\Session::getLoginUserID(),
                $id,
                'launch_requested',
                'failed',
                'connection_not_configured'
            );
            return new Response('Winbox connection details are not configured for this device.', 404);
        }

        $token = bin2hex(random_bytes(32));
        $DB->delete('glpi_plugin_winbox_tokens', ['expires_at' => ['<', date('Y-m-d H:i:s')]]);
        $created_by = (int)\Session::getLoginUserID();
        $inserted = $DB->insert('glpi_plugin_winbox_tokens', [
            'token_hash' => hash('sha256', $token),
            'item_id' => $id,
            'launch_type' => 'winbox',
            'expires_at' => date('Y-m-d H:i:s', time() + 90),
            'created_by' => $created_by,
        ]);
        if (!$inserted) {
            \GlpiPlugin\Winbox\Service\AccessLog::record(
                $created_by,
                $id,
                'launch_requested',
                'failed',
                'token_creation_failed'
            );
            return new Response('Unable to create Winbox launch request.', 500);
        }

        $equipment = new \NetworkEquipment();
        $equipment->getFromDB($id);
        \GlpiPlugin\Winbox\Service\AccessLog::record(
            $created_by,
            $id,
            'launch_handoff_created',
            'pending'
        );
        $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        \Html::header(__('Open Winbox', 'winbox'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid"><div class="card m-3"><div class="card-body text-center"><h2>MikroTik Tools for GLPI</h2><p>' . $e($equipment->fields['name']) . ' — ' . $e($credential['ip_address'] . ':' . $credential['port']) . '</p><p>' . $e(__('This is an intermediate step. Click below to send the one-time code to the Windows handler.', 'winbox')) . '</p><p>' . $e(__('The code expires after 90 seconds and can only be used once.', 'winbox')) . '</p><a class="btn btn-primary btn-lg" href="mikrotik://' . $e($token) . '">' . $e(__('Start Winbox', 'winbox')) . '</a></div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/Launch', name: 'winbox_launch_get_rejected', methods: ['GET'])]
    public function rejectGet(): Response
    {
        return new Response('Launch requests must be started from the Winbox tab.', 405, [
            'Allow' => 'POST',
            'Cache-Control' => 'no-store',
        ]);
    }
}
