<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LaunchCountdownAssetController extends AbstractController
{
    #[Route('/LaunchCountdown.js', name: 'winbox_launch_countdown_asset', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(): Response
    {
        $script_path = dirname(__DIR__, 2) . '/js/launch-countdown.js';
        $script = @file_get_contents($script_path);
        if (!is_string($script)) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }

        return new Response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
