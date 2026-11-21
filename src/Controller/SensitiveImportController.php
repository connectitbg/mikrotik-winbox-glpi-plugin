<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use GlpiPlugin\Winbox\Service\RouterOsSshClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SensitiveImportController extends AbstractController
{
    #[Route('/SensitiveImport', name: 'winbox_sensitive_import', methods: ['GET', 'POST'])]
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
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before restoring a text backup.'), ERROR);
        }

        if ($request->isMethod('GET')) {
            return self::confirmationPage($request, $equipment);
        }

        $upload = $request->files->get('backup_file');
        if (
            !$request->request->getBoolean('confirm_sensitive_import')
            || !$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
            || !$upload->isValid()
            || strtolower($upload->getClientOriginalExtension()) !== 'rsc'
            || $upload->getSize() === null
            || $upload->getSize() < 1
            || $upload->getSize() > 5 * 1024 * 1024
        ) {
            return self::redirectWithMessage($equipment, __('Select a valid .rsc text backup under 5 MiB and confirm the restore.'), ERROR);
        }

        $configuration = file_get_contents($upload->getPathname());
        if ($configuration === false || str_contains($configuration, "\0") || preg_match('//u', $configuration) !== 1) {
            return self::redirectWithMessage($equipment, __('The text backup must be readable UTF-8 without binary data.'), ERROR);
        }

        AccessLog::record(
            (int)Session::getLoginUserID(),
            $id,
            'sensitive_text_restore_started',
            'pending',
            'file_size=' . strlen($configuration)
        );

        try {
            (new RouterOsSshClient($credentials))->importSensitiveConfiguration($configuration);
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_text_restore_completed',
                'success',
                'ssh_sftp_dry_run_passed'
            );
            return self::redirectWithMessage($equipment, __('Sensitive text backup restored successfully after RouterOS dry-run validation.'), INFO);
        } catch (Throwable $exception) {
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_text_restore_completed',
                'failed',
                'ssh_sftp_import_failed'
            );
            return self::redirectWithMessage($equipment, $exception->getMessage(), ERROR);
        }
    }

    private static function confirmationPage(Request $request, NetworkEquipment $equipment): Response
    {
        global $CFG_GLPI;
        $id = (int)$equipment->getID();
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        \Html::header(__('Restore MikroTik text backup'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Restore text backup with sensitive values')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<div class="alert alert-danger"><strong>' . $escape(__('This import applies RouterOS commands and can change or disconnect the router.'))
            . '</strong> ' . $escape(__('Use only a backup prepared for this device and RouterOS version. Create a current backup first. Passwords in the file are readable text.'))
            . '</div>';
        if ($request->isSecure() === false) {
            echo '<div class="alert alert-warning">' . $escape(__('GLPI is using HTTP. The uploaded backup travels to GLPI without HTTPS encryption. Continue only on the trusted test network; use HTTPS for production.')) . '</div>';
        }
        echo '<p>' . $escape(__('The router must allow SSH and SFTP from the GLPI server. Its SSH host key must be verified first. RouterOS import dry-run requires v7.16 or later.')) . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . $escape($root . '/plugins/winbox/SensitiveImport') . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="MAX_FILE_SIZE" value="5242880">';
        echo '<div class="mb-3"><label class="form-label" for="winbox_sensitive_backup">' . $escape(__('Text backup (.rsc)')) . '</label>';
        echo '<input class="form-control" type="file" id="winbox_sensitive_backup" name="backup_file" accept=".rsc,text/plain" required></div>';
        echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="winbox_sensitive_import_confirm" name="confirm_sensitive_import" value="1" required>';
        echo '<label class="form-check-label" for="winbox_sensitive_import_confirm">' . $escape(__('I understand that this file contains readable passwords and its commands will be applied to the router.')) . '</label></div>';
        echo '<button class="btn btn-danger me-2" type="submit"><i class="ti ti-upload me-1"></i>' . $escape(__('Restore text backup')) . '</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">' . $escape(__('Cancel')) . '</a>';
        echo '</form></div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store']);
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
