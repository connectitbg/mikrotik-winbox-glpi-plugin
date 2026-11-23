<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RedeemController extends AbstractController
{
    #[Route('/Redeem', name: 'winbox_redeem', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request): Response
    {
        global $DB;
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? (string)($payload['token'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new JsonResponse(['error' => 'Invalid launch code'], 400, ['Cache-Control' => 'no-store']);
        }

        $hash = hash('sha256', $token);
        $token_row = null;
        foreach ($DB->request([
            'FROM' => 'glpi_plugin_winbox_tokens',
            'WHERE' => ['token_hash' => $hash, 'launch_type' => 'winbox'],
            'LIMIT' => 1,
        ]) as $row) {
            $token_row = $row;
            break;
        }
        if (!$token_row || strtotime($token_row['expires_at']) <= time()) {
            return new JsonResponse(['error' => 'Launch code expired or already used'], 410, ['Cache-Control' => 'no-store']);
        }

        // Atomically mark the code as redeemed so a retry cannot release credentials twice.
        $DB->update(
            'glpi_plugin_winbox_tokens',
            ['redeemed_at' => date('Y-m-d H:i:s')],
            ['token_hash' => $hash, 'launch_type' => 'winbox', 'redeemed_at' => null, 'expires_at' => ['>', date('Y-m-d H:i:s')]]
        );
        if ($DB->affectedRows() !== 1) {
            return new JsonResponse(['error' => 'Launch code expired or already used'], 410, ['Cache-Control' => 'no-store']);
        }

        $credential = \GlpiPlugin\Winbox\Service\Access::credentials($DB, (int)$token_row['item_id']);
        if (!$credential) {
            return new JsonResponse(['error' => 'Connection details unavailable'], 404, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse([
            'address' => $credential['ip_address'] . ':' . $credential['port'],
            'username' => $credential['username'],
            'password' => \GlpiPlugin\Winbox\Service\Access::decryptPassword((string)$credential['password']),
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
