<?php

namespace GlpiPlugin\Winbox\Service;

final class Access
{
    public static function credentials($db, int $item_id): ?array
    {
        foreach ($db->request(['FROM' => 'glpi_plugin_winbox_credentials', 'WHERE' => ['item_id' => $item_id], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    public static function sshHost(array $credentials): string
    {
        $ssh_host = trim((string)($credentials['ssh_host'] ?? ''));
        return $ssh_host !== '' ? $ssh_host : trim((string)($credentials['ip_address'] ?? ''));
    }

    public static function canReadEquipment(int $item_id): bool
    {
        if ($item_id < 1 || !\Session::getLoginUserID()) return false;
        $equipment = new \NetworkEquipment();
        return $equipment->can($item_id, READ);
    }
}
