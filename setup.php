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
        'version' => '1.0.0',
        'author' => '<a href="https://connectit.bg">Connect IT</a>',
        'homepage' => 'https://connectit.bg',
        'license' => 'GPL-3.0-or-later',
        'requirements' => [
            'glpi' => ['min' => '11.0.0', 'max' => '12.0.0'],
            'php' => ['min' => '8.2'],
        ],
    ];
}

function plugin_winbox_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, '11.0.0', '>=') && version_compare(GLPI_VERSION, '12.0.0', '<');
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
    $DB->doQueryOrDie("CREATE TABLE IF NOT EXISTS `$credentials` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation", 'Unable to create Winbox credentials table');
    $DB->doQueryOrDie("CREATE TABLE IF NOT EXISTS `$tokens` (
        `token_hash` CHAR(64) NOT NULL,
        `item_id` INT UNSIGNED NOT NULL,
        `expires_at` TIMESTAMP NOT NULL,
        `redeemed_at` TIMESTAMP NULL DEFAULT NULL,
        `reported_at` TIMESTAMP NULL DEFAULT NULL,
        `launch_result` VARCHAR(32) DEFAULT NULL,
        `launch_type` VARCHAR(16) NOT NULL DEFAULT 'winbox',
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`token_hash`), KEY `idx_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation", 'Unable to create Winbox launch token table');

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
        if (!$DB->fieldExists($credentials, $field)) {
            $DB->doQueryOrDie(
                "ALTER TABLE `$credentials` ADD COLUMN `$field` $definition",
                "Unable to add `$field` to Winbox credentials table"
            );
        }
    }

    foreach ([
        'redeemed_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'reported_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'launch_result' => 'VARCHAR(32) DEFAULT NULL',
        'launch_type' => "VARCHAR(16) NOT NULL DEFAULT 'winbox'",
    ] as $field => $definition) {
        if (!$DB->fieldExists($tokens, $field)) {
            $DB->doQueryOrDie(
                "ALTER TABLE `$tokens` ADD COLUMN `$field` $definition",
                "Unable to add `$field` to Winbox launch token table"
            );
        }
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
