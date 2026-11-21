<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ReportLaunchController extends AbstractController
{
    #[Route('/ReportLaunch', name: 'winbox_report_launch', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request): JsonResponse
    {
        global $DB;

        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? (string)($payload['token'] ?? '') : '';
        $status = is_array($payload) ? (string)($payload['status'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !in_array($status, ['started', 'failed'], true)) {
            return new JsonResponse(['error' => 'Invalid launch report'], 400, ['Cache-Control' => 'no-store']);
        }

        $token_hash = hash('sha256', $token);
        $token_row = null;
        foreach ($DB->request([
            'FROM' => 'glpi_plugin_winbox_tokens',
            'WHERE' => ['token_hash' => $token_hash, 'launch_type' => 'winbox'],
            'LIMIT' => 1,
        ]) as $row) {
            $token_row = $row;
            break;
        }

        if (
            !$token_row
            || empty($token_row['redeemed_at'])
            || !empty($token_row['reported_at'])
            || strtotime($token_row['expires_at']) < time()
        ) {
            return new JsonResponse(['error' => 'Launch report is expired or already recorded'], 410, ['Cache-Control' => 'no-store']);
        }

        $result = $status === 'started' ? 'process_started' : 'process_start_failed';
        $DB->update(
            'glpi_plugin_winbox_tokens',
            [
                'launch_result' => $result,
                'reported_at' => date('Y-m-d H:i:s'),
            ],
            [
                'token_hash' => $token_hash,
                'launch_type' => 'winbox',
                'reported_at' => null,
                'expires_at' => ['>=', date('Y-m-d H:i:s')],
            ]
        );
        if ($DB->affectedRows() !== 1) {
            return new JsonResponse(['error' => 'Launch report is expired or already recorded'], 410, ['Cache-Control' => 'no-store']);
        }

        \GlpiPlugin\Winbox\Service\AccessLog::record(
            (int)$token_row['created_by'],
            (int)$token_row['item_id'],
            $result,
            $result,
            $status === 'started' ? 'router_authentication_not_verified' : 'winbox_process_did_not_start'
        );

        return new JsonResponse(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
