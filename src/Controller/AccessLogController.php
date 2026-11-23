<?php

namespace GlpiPlugin\Winbox\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Winbox\Service\Access;
use GlpiPlugin\Winbox\Service\AccessLog;
use NetworkEquipment;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccessLogController extends AbstractController
{
    #[Route('/AccessLog', name: 'winbox_device_access_log', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $id = $request->query->getInt('id');
        if (!Access::canViewAccessLog($id)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }

        $equipment = new NetworkEquipment();
        if (!$equipment->getFromDB($id)) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }

        $rows = AccessLog::readForDevice($id);
        $escape = static fn($value): string => htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        ob_start();
        \Html::header(__('MikroTik access log', 'winbox'), $_SERVER['PHP_SELF'], 'assets', 'networkequipment');
        echo '<div class="container-fluid p-3">';
        echo '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">';
        echo '<h2>' . $escape(__('MikroTik access log', 'winbox')) . ' — ' . $escape($equipment->fields['name']) . '</h2>';
        echo '<a class="btn btn-outline-secondary" href="' . $escape(\Toolbox::getItemTypeFormURL('NetworkEquipment')) . '?id=' . $id . '&forcetab=GlpiPlugin%5CWinbox%5CWinboxTab%241">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . $escape(__('Back to Winbox', 'winbox')) . '</a>';
        echo '</div>';
        echo '<p class="text-muted">' . $escape(__('Showing the latest 200 entries for this device. “Winbox process started” does not confirm authentication on the router.', 'winbox')) . '</p>';

        if ($rows === []) {
            echo '<div class="alert alert-info">' . $escape(__('No Winbox access events have been recorded for this device yet.', 'winbox')) . '</div>';
        } else {
            echo '<div class="table-responsive"><table class="table table-hover table-striped">';
            echo '<thead><tr><th>' . $escape(__('Date', 'winbox')) . '</th><th>' . $escape(__('GLPI user', 'winbox')) . '</th><th>' . $escape(__('Target', 'winbox')) . '</th><th>' . $escape(__('Event', 'winbox')) . '</th><th>' . $escape(__('Result', 'winbox')) . '</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $data = $row['data'];
                echo '<tr><td>' . $escape($row['timestamp']) . '</td>';
                echo '<td>' . $escape($data['glpi_user'] ?? '') . '</td>';
                echo '<td><code>' . $escape($data['target'] ?? '') . '</code></td>';
                echo '<td>' . $escape($data['event'] ?? '') . '</td>';
                echo '<td>' . self::renderResult((string)($data['result'] ?? ''), $escape) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '</div>';
        \Html::footer();
        return new Response((string)ob_get_clean(), 200, ['Cache-Control' => 'no-store']);
    }

    private static function renderResult(string $result, callable $escape): string
    {
        return match ($result) {
            'process_started' => '<span class="badge bg-success">' . $escape(__('Winbox process started', 'winbox')) . '</span><div class="small text-muted">' . $escape(__('Router authentication was not verified.', 'winbox')) . '</div>',
            'process_start_failed', 'failed' => '<span class="badge bg-danger">' . $escape(__('Failed', 'winbox')) . '</span>',
            'pending' => '<span class="badge bg-warning text-dark">' . $escape(__('Waiting for client result', 'winbox')) . '</span>',
            default => '<span class="badge bg-secondary">' . $escape($result) . '</span>',
        };
    }
}
