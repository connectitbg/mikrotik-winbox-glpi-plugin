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

final class SensitiveRestoreDiagnosticController extends AbstractController
{
    #[Route('/SensitiveRestoreDiagnostic', name: 'winbox_sensitive_restore_diagnostic', methods: ['GET', 'POST'])]
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
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before running restore diagnostics.'), ERROR);
        }

        if ($request->isMethod('GET')) {
            return self::confirmationPage($request, $equipment);
        }
        if (!$request->request->getBoolean('confirm_sftp_diagnostic')) {
            return self::redirectWithMessage($equipment, __('Confirm the temporary SFTP write test before continuing.'), ERROR);
        }

        try {
            $result = (new RouterOsSshClient($credentials))->diagnoseSensitiveRestoreUpload();
            $success = $result['upload_status'] === 'success' && $result['cleanup_status'] === 'success';
            $note = 'sftp=' . $result['upload_status'] . '; cleanup=' . $result['cleanup_status'];
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_restore_diagnostic',
                $success ? 'success' : 'failed',
                $note
            );
            return self::resultPage($request, $equipment, $result);
        } catch (Throwable $exception) {
            AccessLog::record(
                (int)Session::getLoginUserID(),
                $id,
                'sensitive_restore_diagnostic',
                'failed',
                'ssh_endpoint_preflight_failed'
            );
            return self::resultPage($request, $equipment, [
                'fingerprint_status' => 'failed',
                'fingerprint_message' => $exception->getMessage(),
                'upload_status' => 'not_run',
                'upload_message' => __('The SFTP write test did not run because the SSH endpoint preflight failed.'),
                'cleanup_status' => 'not_needed',
                'cleanup_message' => '',
            ]);
        }
    }

    private static function confirmationPage(Request $request, NetworkEquipment $equipment): Response
    {
        global $CFG_GLPI;
        $id = (int)$equipment->getID();
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        \Html::header(__('Diagnose text restore upload'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Diagnose text restore upload')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<div class="alert alert-warning"><strong>' . $escape(__('This diagnostic opens an SSH/SFTP connection to the router.'))
            . '</strong> ' . $escape(__('It uploads a uniquely named small .tmp test file and then tries to remove it. It does not import or execute RouterOS configuration. If cleanup cannot be confirmed, the result will show the temporary filename.'))
            . '</div>';
        if ($request->isSecure() === false) {
            echo '<div class="alert alert-warning">' . $escape(__('GLPI is using HTTP. Use this test only on the trusted test network.')) . '</div>';
        }
        echo '<p>' . $escape(__('The check verifies that the pinned SSH fingerprint still matches and whether the GLPI server can upload a temporary file over SFTP. A failed upload commonly means the RouterOS account lacks the ftp policy; a later configuration restore also requires write policy.')) . '</p>';
        echo '<form method="post" action="' . $escape($root . '/plugins/winbox/SensitiveRestoreDiagnostic') . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
        echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="winbox_sftp_diagnostic_confirm" name="confirm_sftp_diagnostic" value="1" required>';
        echo '<label class="form-check-label" for="winbox_sftp_diagnostic_confirm">' . $escape(__('I understand this test writes and then removes a temporary file on the router.')) . '</label></div>';
        echo '<button class="btn btn-warning me-2" type="submit"><i class="ti ti-stethoscope me-1"></i>' . $escape(__('Run restore diagnostic')) . '</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape(self::equipmentUrl($root, $id)) . '">' . $escape(__('Cancel')) . '</a>';
        echo '</form></div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }

    private static function resultPage(Request $request, NetworkEquipment $equipment, array $result): Response
    {
        global $CFG_GLPI;
        $id = (int)$equipment->getID();
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        \Html::header(__('Restore upload diagnostic result'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Restore upload diagnostic result')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<p>' . $escape(__('No RouterOS configuration import was run by this diagnostic.')) . '</p><ul class="list-group mb-3">';
        self::renderResult($escape, __('OpenSSH and pinned SSH fingerprint'), (string)($result['fingerprint_status'] ?? 'failed'), (string)($result['fingerprint_message'] ?? ''));
        self::renderResult($escape, __('SFTP temporary upload'), (string)($result['upload_status'] ?? 'not_run'), (string)($result['upload_message'] ?? ''));
        if (($result['cleanup_status'] ?? 'not_needed') !== 'not_needed') {
            self::renderResult($escape, __('Temporary file cleanup'), (string)$result['cleanup_status'], (string)($result['cleanup_message'] ?? ''));
        }
        echo '</ul>';
        echo '<div class="alert alert-info">' . $escape(__('A successful upload test confirms SFTP file transfer only. It does not apply configuration. RouterOS configuration restore still requires the ftp and write policies.')) . '</div>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape(self::equipmentUrl($root, $id)) . '">' . $escape(__('Back to Winbox')) . '</a>';
        echo '</div></div></div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store, private']);
    }

    private static function renderResult(callable $escape, string $label, string $status, string $message): void
    {
        $class = match ($status) {
            'success' => 'list-group-item-success',
            'warning' => 'list-group-item-warning',
            'failed' => 'list-group-item-danger',
            default => 'list-group-item-secondary',
        };
        echo '<li class="list-group-item ' . $class . '"><strong>' . $escape($label) . ' — ' . $escape(ucfirst($status))
            . '</strong><div>' . $escape($message) . '</div></li>';
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
        return new RedirectResponse($root . '/front/networkequipment.form.php?' . http_build_query([
            'id' => (int)$equipment->getID(),
            'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1',
        ]));
    }

    private static function equipmentUrl(string $root, int $id): string
    {
        return $root . '/front/networkequipment.form.php?' . http_build_query([
            'id' => $id,
            'forcetab' => 'GlpiPlugin\\Winbox\\WinboxTab$1',
        ]);
    }
}
