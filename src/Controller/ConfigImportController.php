<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use GlpiPlugin\Winbox\Service\RouterOsRestClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ConfigImportController extends AbstractController
{
    #[Route('/ConfigImport', name: 'winbox_config_import', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        Session::checkRight('config', UPDATE);
        global $DB;

        $id = $request->isMethod('POST')
            ? $request->request->getInt('id')
            : $request->query->getInt('id');
        $equipment = self::getEditableEquipment($id);
        $credentials = Access::credentials($DB, $id);
        if (!$credentials) {
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before importing configuration.'), ERROR);
        }

        if ($request->isMethod('POST')) {
            return $this->importUploadedConfiguration($request, $equipment, $credentials);
        }

        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        \Html::header(__('Upload MikroTik configuration'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Upload configuration')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<div class="alert alert-warning"><strong>' . $escape(__('This imports commands and can change or disconnect the router.'))
            . '</strong> ' . $escape(__('Back up the router first. Use only a configuration file prepared for this device and RouterOS version. RouterOS REST over verified HTTPS must be enabled and reachable from the GLPI server.'))
            . '</div>';
        echo '<p>' . $escape(__('Upload a RouterOS text export (.rsc), up to 60,000 bytes. The plugin runs RouterOS import dry-run first, then imports the configuration if validation succeeds.')) . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . $escape($root . '/plugins/winbox/ConfigImport') . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="MAX_FILE_SIZE" value="60000">';
        echo '<div class="mb-3"><label class="form-label" for="winbox_config_file">' . $escape(__('Configuration file (.rsc)')) . '</label>';
        echo '<input class="form-control" type="file" id="winbox_config_file" name="config_file" accept=".rsc,text/plain" required></div>';
        echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="winbox_import_confirm" name="confirm_import" value="1" required>';
        echo '<label class="form-check-label" for="winbox_import_confirm">' . $escape(__('I understand that importing this file will apply its commands to the router.')) . '</label></div>';
        echo '<button class="btn btn-danger me-2" type="submit"><i class="ti ti-upload me-1"></i>' . $escape(__('Import configuration')) . '</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">' . $escape(__('Cancel')) . '</a>';
        echo '</form></div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store']);
    }

    private function importUploadedConfiguration(Request $request, NetworkEquipment $equipment, array $credentials): Response
    {
        $id = (int)$equipment->getID();
        $upload = $request->files->get('config_file');
        if (
            !$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            || !$upload->isValid()
            || strtolower($upload->getClientOriginalExtension()) !== 'rsc'
            || $upload->getSize() === null
            || $upload->getSize() < 1
            || $upload->getSize() > 60000
            || !$request->request->getBoolean('confirm_import')
        ) {
            return self::redirectWithMessage($equipment, __('Select a valid .rsc file under 60,000 bytes and confirm the import.'), ERROR);
        }

        $configuration = file_get_contents($upload->getPathname());
        if ($configuration === false || str_contains($configuration, "\0") || preg_match('//u', $configuration) !== 1) {
            return self::redirectWithMessage($equipment, __('The uploaded file must be readable UTF-8 text without binary data.'), ERROR);
        }

        AccessLog::record(
            (int)Session::getLoginUserID(),
            $id,
            'configuration_import_started',
            'pending',
            'file_size=' . strlen($configuration)
        );

        try {
            (new RouterOsRestClient($credentials))->importConfiguration($configuration);
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'configuration_imported',
                'success',
                'dry_run_passed'
            );
            return self::redirectWithMessage($equipment, __('Configuration import completed after RouterOS dry-run validation.'), INFO);
        } catch (Throwable $exception) {
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'configuration_imported',
                'failed',
                'routeros_rest_import_failed'
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
        return new RedirectResponse($root . '/front/networkequipment.form.php?' . http_build_query($query));
    }
}
