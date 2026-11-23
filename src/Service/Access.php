<?php

namespace GlpiPlugin\Winbox\Service;

final class Access
{
    public const PASSWORD_ENCRYPTION_PREFIX = 'glpi:v1:';

    public static function credentials($db, int $item_id): ?array
    {
        foreach ($db->request(['FROM' => 'glpi_plugin_winbox_credentials', 'WHERE' => ['item_id' => $item_id], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    /** Return the clear password only to a caller that is already authorized to use it. */
    public static function decryptPassword(?string $stored_password): string
    {
        $stored_password ??= '';
        if (!self::isEncryptedPassword($stored_password)) {
            // Compatibility for rows awaiting the one-time install/upgrade migration.
            return $stored_password;
        }

        $key = new \GLPIKey();
        $secret = $key->get();
        if ($secret === null) {
            throw new \RuntimeException('The GLPI security key is unavailable; router credentials cannot be used.');
        }
        $plain = $key->decrypt(substr($stored_password, strlen(self::PASSWORD_ENCRYPTION_PREFIX)), $secret);
        if (!is_string($plain)) {
            throw new \RuntimeException('Unable to decrypt the saved router password with the GLPI security key.');
        }
        return $plain;
    }

    public static function encryptPassword(string $password): string
    {
        $key = new \GLPIKey();
        $secret = $key->get();
        if ($secret === null) {
            throw new \RuntimeException('The GLPI security key is unavailable; router credentials were not saved.');
        }

        $encrypted = $key->encrypt($password, $secret);
        if ($encrypted === '') {
            throw new \RuntimeException('The GLPI security key could not encrypt the router password.');
        }
        return self::PASSWORD_ENCRYPTION_PREFIX . $encrypted;
    }

    /** Encrypt legacy plaintext rows; safe to rerun after an interrupted migration. */
    public static function migrateStoredPasswords($db): bool
    {
        $table = 'glpi_plugin_winbox_credentials';
        if (!$db->tableExists($table) || !$db->fieldExists($table, 'password')) {
            return true;
        }

        $key = new \GLPIKey();
        $secret = $key->get();
        if ($secret === null) {
            return false;
        }

        foreach ($db->request(['SELECT' => ['id', 'password'], 'FROM' => $table]) as $row) {
            $stored = (string)($row['password'] ?? '');
            if (self::isEncryptedPassword($stored)) {
                continue;
            }
            $encrypted = $key->encrypt($stored, $secret);
            if ($encrypted === '') {
                return false;
            }
            if (!$db->update($table, [
                'password' => self::PASSWORD_ENCRYPTION_PREFIX . $encrypted,
            ], ['id' => (int)$row['id']])) {
                return false;
            }
        }

        return true;
    }

    private static function isEncryptedPassword(string $value): bool
    {
        if (!str_starts_with($value, self::PASSWORD_ENCRYPTION_PREFIX)) {
            return false;
        }

        $payload = substr($value, strlen(self::PASSWORD_ENCRYPTION_PREFIX));
        $decoded = base64_decode($payload, true);
        return is_string($decoded) && strlen($decoded) >= 40 && base64_encode($decoded) === $payload;
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

    /** Credential release requires both global configuration update and per-device update rights. */
    public static function canLaunchEquipment(int $item_id): bool
    {
        if ($item_id < 1 || !\Session::getLoginUserID() || !\Session::haveRight('config', UPDATE)) {
            return false;
        }
        $equipment = new \NetworkEquipment();
        return $equipment->getFromDB($item_id) && $equipment->can($item_id, UPDATE);
    }
}
