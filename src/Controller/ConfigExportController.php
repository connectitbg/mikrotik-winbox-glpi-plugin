<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use GlpiPlugin\Winbox\Service\RouterOsRestClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ConfigExportController extends AbstractController
{
    #[Route('/ConfigExport', name: 'winbox_config_export', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        global $DB;

        $id = $request->query->getInt('id');
        $equipment = self::getEditableEquipment($id);
        $credentials = Access::credentials($DB, $id);
        if (!$credentials) {
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before exporting configuration.'), ERROR);
        }

        try {
            $configuration = (new RouterOsRestClient($credentials))->exportConfiguration();
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'configuration_exported',
                'success',
                'routeros_sensitive_values_masked'
            );

            $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$equipment->fields['name']) ?: 'mikrotik';
            $filename = $safe_name . '-' . date('Y-m-d-His') . '.rsc';
            return new Response($configuration, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ]);
        } catch (Throwable $exception) {
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'configuration_exported',
                'failed',
                'routeros_rest_request_failed'
            );
            return self::redirectWithMessage($equipment, $exception->getMessage(), ERROR);
        }
    }

    private static function getEditableEquipment(int $id): NetworkEquipment
    {
        $equipment = new NetworkEquipment();
        if ($id < 1 || !$equipment->getFromDB($id) || !$equipment->can($id, UPDATE)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
        return $equipment;
    }

    private static function redirectWithMessage(NetworkEquipment $equipment, string $message, int $type): Response
    {
        \Session::addMessageAfterRedirect(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, $type);
        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $query = ['id' => (int)$equipment->getID(), 'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1'];
        return new \Symfony\Component\HttpFoundation\RedirectResponse(
            $root . '/front/networkequipment.form.php?' . http_build_query($query)
        );
    }
}
