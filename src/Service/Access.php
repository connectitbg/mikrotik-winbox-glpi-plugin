<?php

namespace GlpiPlugin\Winbox\Service;

final class Access
{
    public const PASSWORD_ENCRYPTION_PREFIX = 'glpi:v1:';
    private const PROFILE_PERMISSIONS_KEY = 'profile_permissions';

    /** @var array<string, mixed>|null */
    private static ?array $plugin_config = null;

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

    /** Users with device read access and the active profile's plugin permission may launch a client. */
    public static function canLaunchEquipment(int $item_id): bool
    {
        return self::hasEquipmentPermission($item_id, 'launch', READ, false);
    }

    /** Viewing credentials also reveals the saved router password. */
    public static function canViewCredentials(int $item_id): bool
    {
        return self::hasEquipmentPermission($item_id, 'view_credentials', READ, false);
    }

    /** Credential edits require both the configured profile permission and device update access. */
    public static function canManageCredentials(int $item_id): bool
    {
        return self::hasEquipmentPermission($item_id, 'edit_credentials', UPDATE, false);
    }

    /** Run read-only router diagnostics when the current profile is allowed. */
    public static function canRunDiagnostics(int $item_id): bool
    {
        return self::hasEquipmentPermission($item_id, 'diagnostics', UPDATE, false);
    }

    /** Backup and restore actions require device update access because restore changes router configuration. */
    public static function canUseBackupRestore(int $item_id): bool
    {
        return self::hasEquipmentPermission($item_id, 'backup_restore', UPDATE, false);
    }

    /** Access log visibility is independently configurable and retains GLPI's system-log permission. */
    public static function canViewAccessLog(int $item_id): bool
    {
        return \Session::haveRight('system_logs', READ)
            && self::hasEquipmentPermission($item_id, 'access_log', READ, false);
    }

    /** Return the persisted per-profile matrix, or null before an administrator saves it once. */
    public static function configuredProfilePermissions(): ?array
    {
        $config = self::pluginConfig();
        if (!array_key_exists(self::PROFILE_PERMISSIONS_KEY, $config)
            || $config[self::PROFILE_PERMISSIONS_KEY] === null
            || $config[self::PROFILE_PERMISSIONS_KEY] === '') {
            global $DB;
            if (!isset($DB)) {
                return null;
            }

            $defaults = self::defaultProfilePermissions($DB);
            if ($defaults === []) {
                return null;
            }
            $encoded = json_encode($defaults, JSON_THROW_ON_ERROR);
            \Config::setConfigurationValues('plugin:winbox', [self::PROFILE_PERMISSIONS_KEY => $encoded]);
            self::$plugin_config[self::PROFILE_PERMISSIONS_KEY] = $encoded;
            return $defaults;
        }

        $encoded = $config[self::PROFILE_PERMISSIONS_KEY];
        if (!is_string($encoded)) {
            return [];
        }

        $permissions = json_decode($encoded, true);
        return is_array($permissions) ? $permissions : [];
    }

    /** Seed the requested least-privilege defaults once, preserving any administrator-saved matrix. */
    private static function defaultProfilePermissions($db): array
    {
        $matrix = [];
        foreach ($db->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles']) as $profile) {
            $matrix[(string)(int)$profile['id']] = self::defaultPermissionsForProfile((string)$profile['name']);
        }

        return $matrix;
    }

    /** Return the default permission row for a newly created GLPI profile. */
    public static function defaultPermissionsForProfile(string $profile_name): array
    {
        $name = strtolower($profile_name);
        $name = preg_replace('/[^a-z0-9]/', '', $name) ?? '';
        $full = in_array($name, ['admin', 'superadmin'], true);
        $operator = in_array($name, ['supervisor', 'technician'], true);

        return [
            'launch' => $full || $operator,
            'access_log' => $full || $operator,
            'view_credentials' => $full,
            'edit_credentials' => $full,
            'diagnostics' => $full,
            'backup_restore' => $full,
        ];
    }

    /** Check a plugin permission together with the required per-device GLPI right. */
    private static function hasEquipmentPermission(int $item_id, string $permission, int $required_right, bool $default): bool
    {
        if ($item_id < 1 || !\Session::getLoginUserID()) {
            return false;
        }

        $equipment = new \NetworkEquipment();
        if (!$equipment->getFromDB($item_id) || !$equipment->can($item_id, $required_right)) {
            return false;
        }

        $profile_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $permissions = self::configuredProfilePermissions();
        if ($profile_id > 0 && isset($permissions[(string)$profile_id]) && array_key_exists($permission, $permissions[(string)$profile_id])) {
            return (bool)$permissions[(string)$profile_id][$permission];
        }

        return $default;
    }

    /** @return array<string, mixed> */
    private static function pluginConfig(): array
    {
        if (self::$plugin_config === null) {
            self::$plugin_config = \Config::getConfigurationValues('plugin:winbox');
        }

        return self::$plugin_config;
    }
}
