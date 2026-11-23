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

final class SensitiveExportController extends AbstractController
{
    #[Route('/SensitiveExport', name: 'winbox_sensitive_export', methods: ['GET', 'POST'])]
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
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before creating a text backup.', 'winbox'), ERROR);
        }

        if ($request->isMethod('GET')) {
            return self::confirmationPage($request, $equipment);
        }
        if (!$request->request->getBoolean('confirm_sensitive_export')) {
            return self::redirectWithMessage($equipment, __('Confirm that you understand this backup contains plaintext sensitive values.', 'winbox'), ERROR);
        }

        try {
            $configuration = (new RouterOsSshClient($credentials))->exportSensitiveConfiguration();
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_text_backup_downloaded',
                'success',
                'plaintext_sensitive_values_included'
            );

            $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$equipment->fields['name']) ?: 'mikrotik';
            $filename = $safe_name . '-sensitive-' . date('Y-m-d-His') . '.rsc';
            return new Response($configuration, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (Throwable $exception) {
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_text_backup_downloaded',
                'failed',
                'ssh_export_failed'
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
        \Html::header(__('Sensitive MikroTik text backup', 'winbox'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Download text backup with sensitive values', 'winbox')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<div class="alert alert-danger"><strong>' . $escape(__('This .rsc file contains passwords and keys in readable text.', 'winbox'))
            . '</strong> ' . $escape(__('Store it securely and delete it when no longer needed. RouterOS user account passwords and SSH private keys are not included in text exports.', 'winbox'))
            . '</div>';
        if ($request->isSecure() === false) {
            echo '<div class="alert alert-warning">' . $escape(__('GLPI is using HTTP. The backup will travel from GLPI to your browser without HTTPS encryption. Continue only on the trusted test network; use HTTPS for production.', 'winbox')) . '</div>';
        }
        echo '<p>' . $escape(__('RouterOS export show-sensitive requires an account with the sensitive policy. The SSH connection uses a host key previously verified in GLPI.', 'winbox')) . '</p>';
        echo '<form method="post" action="' . $escape($root . '/plugins/winbox/SensitiveExport') . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="winbox_sensitive_export_confirm" name="confirm_sensitive_export" value="1" required>';
        echo '<label class="form-check-label" for="winbox_sensitive_export_confirm">' . $escape(__('I understand that the downloaded text file includes sensitive values in plaintext.', 'winbox')) . '</label></div>';
        echo '<button class="btn btn-danger me-2" type="submit"><i class="ti ti-download me-1"></i>' . $escape(__('Download sensitive text backup', 'winbox')) . '</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">' . $escape(__('Cancel', 'winbox')) . '</a>';
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
