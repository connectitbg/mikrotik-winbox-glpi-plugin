<?php

function plugin_init_winbox(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['winbox'] = true;
    \Plugin::registerClass(\GlpiPlugin\Winbox\WinboxTab::class, [
        'addtabon' => ['NetworkEquipment'],
    ]);
    $PLUGIN_HOOKS['config_page']['winbox'] = 'front/config.php';
    $PLUGIN_HOOKS['boot']['winbox'] = 'plugin_winbox_boot';
}

function plugin_winbox_boot(): void
{
    \Glpi\Http\SessionManager::registerPluginStatelessPath('winbox', '#^/(Redeem|ReportLaunch|RedeemSsh|ReportSshLaunch)$#');
}

function plugin_version_winbox(): array
{
    return [
        'name' => 'MikroTik Tools for GLPI',
        'version' => '1.0.2',
        'author' => '<a href="https://connectit.bg">Connect IT</a>',
        'homepage' => 'https://connectit.bg',
        'license' => 'GPL-3.0-or-later',
        'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '13.0.0'],
            'php' => ['min' => '8.2'],
        ],
    ];
}

function plugin_winbox_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.0', '>=') && version_compare(GLPI_VERSION, '13.0.0', '<');
}

function plugin_winbox_check_config($verbose = false): bool
{
    return true;
}

function plugin_winbox_install(): bool
{
    global $DB;
    $credentials = 'glpi_plugin_winbox_credentials';
    $tokens = 'glpi_plugin_winbox_tokens';
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $keySign = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists($credentials) && !$DB->doQuery("CREATE TABLE `$credentials` (
        `id` INT $keySign NOT NULL AUTO_INCREMENT,
        `item_id` INT $keySign NOT NULL,
        `ip_address` VARCHAR(255) NOT NULL,
        `port` SMALLINT UNSIGNED NOT NULL DEFAULT 8291,
        `api_host` VARCHAR(255) DEFAULT NULL,
        `api_port` SMALLINT UNSIGNED NOT NULL DEFAULT 443,
        `ftp_port` SMALLINT UNSIGNED NOT NULL DEFAULT 21,
        `routeros_api_port` SMALLINT UNSIGNED NOT NULL DEFAULT 8728,
        `routeros_api_ssl_port` SMALLINT UNSIGNED NOT NULL DEFAULT 8729,
        `www_port` SMALLINT UNSIGNED NOT NULL DEFAULT 80,
        `www_ssl_port` SMALLINT UNSIGNED NOT NULL DEFAULT 443,
        `ssh_host` VARCHAR(255) DEFAULT NULL,
        `ssh_port` SMALLINT UNSIGNED NOT NULL DEFAULT 22,
        `ssh_auth_method` VARCHAR(32) NOT NULL DEFAULT 'password',
        `ssh_host_keys` TEXT DEFAULT NULL,
        `username` VARCHAR(255) NOT NULL,
        `password` TEXT NOT NULL,
        `date_mod` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation")) {
        return false;
    }

    if (!$DB->tableExists($tokens) && !$DB->doQuery("CREATE TABLE `$tokens` (
        `token_hash` CHAR(64) NOT NULL,
        `item_id` INT UNSIGNED NOT NULL,
        `expires_at` TIMESTAMP NOT NULL,
        `redeemed_at` TIMESTAMP NULL DEFAULT NULL,
        `reported_at` TIMESTAMP NULL DEFAULT NULL,
        `launch_result` VARCHAR(32) DEFAULT NULL,
        `launch_type` VARCHAR(16) NOT NULL DEFAULT 'winbox',
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`token_hash`), KEY `idx_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation")) {
        return false;
    }

    $migration = new Migration(plugin_version_winbox()['version']);
    foreach ([
        'api_host' => 'VARCHAR(255) DEFAULT NULL',
        'api_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 443',
        'ftp_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 21',
        'routeros_api_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 8728',
        'routeros_api_ssl_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 8729',
        'www_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 80',
        'www_ssl_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 443',
        'ssh_host' => 'VARCHAR(255) DEFAULT NULL',
        'ssh_port' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 22',
        'ssh_auth_method' => "VARCHAR(32) NOT NULL DEFAULT 'password'",
        'ssh_host_keys' => 'TEXT DEFAULT NULL',
    ] as $field => $definition) {
        $migration->addField($credentials, $field, $definition);
    }

    foreach ([
        'redeemed_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'reported_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'launch_result' => 'VARCHAR(32) DEFAULT NULL',
        'launch_type' => "VARCHAR(16) NOT NULL DEFAULT 'winbox'",
    ] as $field => $definition) {
        $migration->addField($tokens, $field, $definition);
    }
    $migration->addKey($credentials, 'item_id', 'uniq_item', 'UNIQUE');
    $migration->addKey($tokens, 'expires_at', 'idx_expiry');
    $migration->migrationOneTable($credentials);
    $migration->migrationOneTable($tokens);

    require_once GLPI_ROOT . '/plugins/winbox/src/Service/Access.php';
    if (!\GlpiPlugin\Winbox\Service\Access::migrateStoredPasswords($DB)) {
        trigger_error('Unable to encrypt existing Winbox router passwords. Check the GLPI security key and database permissions.', E_USER_WARNING);
        return false;
    }

    if (defined('GLPI_LOG_DIR') && !is_file(GLPI_LOG_DIR . '/mikrotik_access.log')) {
        @touch(GLPI_LOG_DIR . '/mikrotik_access.log');
    }
    return true;
}

function plugin_winbox_uninstall(): bool
{
    global $DB;
    foreach (['glpi_plugin_winbox_tokens', 'glpi_plugin_winbox_credentials'] as $table) {
        if ($DB->tableExists($table)) $DB->dropTable($table);
    }
    return true;
}
