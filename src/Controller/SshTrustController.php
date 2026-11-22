<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\RouterOsSshClient;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SshTrustController extends AbstractController
{
    #[Route('/SshTrust', name: 'winbox_ssh_trust', methods: ['GET', 'POST'])]
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
            return self::redirectWithMessage($equipment, __('Configure the Winbox connection before verifying SSH.'), ERROR);
        }

        $current_keys = [];
        $scan_error = '';
        try {
            $current_keys = (new RouterOsSshClient($credentials))->scanHostKeys();
        } catch (Throwable $exception) {
            $scan_error = $exception->getMessage();
        }

        if ($request->isMethod('POST')) {
            if (!$request->request->getBoolean('confirm_fingerprint') || $current_keys === []) {
                return self::redirectWithMessage($equipment, $scan_error !== '' ? $scan_error : __('Confirm the SSH host-key fingerprints before trusting them.'), ERROR);
            }

            $encoded = json_encode($current_keys, JSON_UNESCAPED_SLASHES);
            if ($encoded === false || !$DB->update('glpi_plugin_winbox_credentials', [
                'ssh_host_keys' => $encoded,
            ], ['item_id' => $id])) {
                return self::redirectWithMessage($equipment, __('Unable to save the verified SSH host keys.'), ERROR);
            }
            return self::redirectWithMessage($equipment, __('Router SSH server fingerprints were pinned for this device.'), INFO);
        }

        $trusted = json_decode((string)($credentials['ssh_host_keys'] ?? ''), true);
        $trusted_fingerprints = is_array($trusted) ? array_column($trusted, 'fingerprint') : [];
        $current_fingerprints = array_column($current_keys, 'fingerprint');
        sort($trusted_fingerprints);
        sort($current_fingerprints);
        $matches = $current_fingerprints !== [] && $trusted_fingerprints === $current_fingerprints;

        global $CFG_GLPI;
        $root = rtrim((string)($CFG_GLPI['root_doc'] ?? ''), '/');
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        \Html::header(__('Verify MikroTik SSH server fingerprint'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3"><div class="card"><div class="card-header"><h2>'
            . $escape(__('Verify router SSH fingerprint')) . ' — ' . $escape($equipment->fields['name'])
            . '</h2></div><div class="card-body">';
        echo '<p>' . $escape(__('SSH endpoint')) . ': <code>'
            . $escape(Access::sshHost($credentials) . ':' . $credentials['ssh_port']) . '</code></p>';

        if ($scan_error !== '') {
            echo '<div class="alert alert-danger">' . $escape($scan_error) . '</div>';
        }
        echo '<div class="alert alert-warning">' . $escape(__('This verifies the router server identity; it does not choose how GLPI logs in. Compare the fingerprint with a trusted record before pinning it. A changed fingerprint may indicate a router replacement or a security problem.')) . '</div>';

        if ($current_keys !== []) {
            echo '<h3>' . $escape(__('SSH server fingerprints offered by this endpoint')) . '</h3><ul>';
            foreach ($current_keys as $key) {
                echo '<li><code>' . $escape($key['type']) . '</code> — <code>' . $escape($key['fingerprint']) . '</code></li>';
            }
            echo '</ul>';
            if ($matches) {
                echo '<div class="alert alert-success">' . $escape(__('These router SSH server fingerprints match the pinned values.')) . '</div>';
            } elseif ($trusted_fingerprints !== []) {
                echo '<div class="alert alert-danger">' . $escape(__('The router SSH server fingerprints differ from the pinned values. Verify them out of band before replacing the pins.')) . '</div>';
            }

            echo '<form method="post" action="' . $escape($root . '/plugins/winbox/SshTrust') . '">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<input type="hidden" name="_glpi_csrf_token" value="' . $escape(Session::getNewCSRFToken()) . '">';
            echo '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="winbox_ssh_confirm" name="confirm_fingerprint" value="1" required>';
            echo '<label class="form-check-label" for="winbox_ssh_confirm">' . $escape(__('I verified these fingerprints against a trusted source and want to pin this router identity.')) . '</label></div>';
            echo '<button class="btn btn-warning me-2" type="submit"><i class="ti ti-shield-check me-1"></i>' . $escape(__('Pin router SSH fingerprint')) . '</button>';
        }

        echo '<a class="btn btn-outline-secondary" href="' . $escape($root . '/front/networkequipment.form.php?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241') . '">' . $escape(__('Back to Winbox')) . '</a>';
        if ($current_keys !== []) {
            echo '</form>';
        }
        echo '</div></div></div>';
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
