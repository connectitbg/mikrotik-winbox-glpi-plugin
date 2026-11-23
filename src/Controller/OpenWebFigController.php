<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\RouterOsServiceProbe;
use NetworkEquipment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenWebFigController extends AbstractController
{
    #[Route('/OpenWebFig', name: 'winbox_open_webfig', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $id = $request->query->getInt('id');
        $equipment = new NetworkEquipment();
        if ($id < 1 || !$equipment->getFromDB($id) || !$equipment->can($id, READ)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }

        global $DB, $CFG_GLPI;
        $credentials = Access::credentials($DB, $id);
        if (!$credentials) {
            return self::returnToEquipment($equipment, __('Configure the RouterOS host before opening WebFig.', 'winbox'), ERROR);
        }

        // Reuse the configured HTTPS name so the browser can validate a
        // certificate issued to a DNS name instead of the router's IP address.
        $host = trim((string)($credentials['api_host'] ?? ''));
        if ($host === '') {
            $host = trim((string)($credentials['ip_address'] ?? ''));
        }
        $www_port = (int)($credentials['www_port'] ?? 80);
        $www_ssl_port = (int)($credentials['www_ssl_port'] ?? 443);
        if (!RouterOsServiceProbe::validTarget($host, $www_port) || !RouterOsServiceProbe::validTarget($host, $www_ssl_port)) {
            return self::returnToEquipment($equipment, __('The configured WebFig host or service ports are invalid.', 'winbox'), ERROR);
        }

        if (RouterOsServiceProbe::tls($host, $www_ssl_port)) {
            return new RedirectResponse(self::url('https', $host, $www_ssl_port));
        }
        if (RouterOsServiceProbe::tcp($host, $www_port)) {
            return new RedirectResponse(self::url('http', $host, $www_port));
        }

        return self::returnToEquipment(
            $equipment,
            __('WebFig is not reachable from the GLPI server on the configured WWW-SSL or WWW port.', 'winbox'),
            ERROR
        );
    }

    private static function url(string $scheme, string $host, int $port): string
    {
        $host_part = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
        $default_port = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host_part . ($port === $default_port ? '' : ':' . $port) . '/';
    }

    private static function returnToEquipment(NetworkEquipment $equipment, string $message, int $type): Response
    {
        \Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, $type);
        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        return new RedirectResponse($root . '/front/networkequipment.form.php?' . http_build_query([
            'id' => (int)$equipment->getID(),
            'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1',
        ]));
    }
}
