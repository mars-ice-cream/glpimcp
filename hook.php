<?php

/**
 * GLPI MCP — installation / désinstallation
 *
 * @license GPL-3.0-or-later
 */


function plugin_glpimcp_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // Jetons personnels : un utilisateur = un ou plusieurs jetons, stockés hachés.
    if (!$DB->tableExists('glpi_plugin_glpimcp_tokens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_tokens` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `name` varchar(255) NOT NULL DEFAULT '',
            `token_hash` char(64) NOT NULL DEFAULT '',
            `token_hint` varchar(16) NOT NULL DEFAULT '',
            `profiles_id` int {$sign} NOT NULL DEFAULT '0',
            `entities_id` int {$sign} DEFAULT NULL,
            `is_recursive` tinyint NOT NULL DEFAULT '1',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_expiration` timestamp NULL DEFAULT NULL,
            `last_use` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_hash` (`token_hash`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // Actions d'écriture en attente de confirmation (aperçu → confirmation).
    if (!$DB->tableExists('glpi_plugin_glpimcp_pendingactions')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_pendingactions` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `uuid` varchar(64) NOT NULL DEFAULT '',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `tokens_id` int {$sign} NOT NULL DEFAULT '0',
            `action` varchar(100) NOT NULL DEFAULT '',
            `payload` longtext,
            `summary` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_expiration` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uuid` (`uuid`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // Journal d'audit : toutes les écritures faites via l'IA.
    if (!$DB->tableExists('glpi_plugin_glpimcp_logs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_logs` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `tokens_id` int {$sign} NOT NULL DEFAULT '0',
            `tool` varchar(100) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT '',
            `summary` text,
            `details` longtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // 0.3.0 : OAuth 2.1 (claude.ai, ChatGPT, Mistral… — connecteurs MCP distants)
    if (!$DB->tableExists('glpi_plugin_glpimcp_oauthclients')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_oauthclients` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `client_id` varchar(255) NOT NULL DEFAULT '',
            `secret_hash` char(64) DEFAULT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `redirect_uris` text,
            `origin` varchar(20) NOT NULL DEFAULT 'dcr',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `date_creation` timestamp NULL DEFAULT NULL,
            `last_use` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }
    if (!$DB->tableExists('glpi_plugin_glpimcp_oauthcodes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_oauthcodes` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `code_hash` char(64) NOT NULL DEFAULT '',
            `oauthclients_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `profiles_id` int {$sign} NOT NULL DEFAULT '0',
            `redirect_uri` text,
            `code_challenge` varchar(128) NOT NULL DEFAULT '',
            `days` int NOT NULL DEFAULT '90',
            `date_expiration` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code_hash` (`code_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }
    $token_columns = [
        'oauthclients_id'     => "int {$sign} NOT NULL DEFAULT '0' AFTER `users_id`",
        'refresh_hash'        => 'char(64) DEFAULT NULL AFTER `token_hash`',
        'refresh_expiration'  => 'timestamp NULL DEFAULT NULL AFTER `date_expiration`',
    ];
    foreach ($token_columns as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_tokens', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_tokens` ADD `{$col}` {$def}");
        }
    }
    if (count($DB->request(['FROM' => 'information_schema.STATISTICS', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => 'glpi_plugin_glpimcp_tokens', 'INDEX_NAME' => 'refresh_hash']])) === 0) {
        $DB->doQuery('ALTER TABLE `glpi_plugin_glpimcp_tokens` ADD UNIQUE KEY `refresh_hash` (`refresh_hash`)');
    }

    // 0.2.0 : traçabilité étendue + annulation (rollback)
    $new_columns = [
        'client'         => "varchar(255) NOT NULL DEFAULT '' AFTER `tokens_id`",
        'profiles_id'    => "int {$sign} NOT NULL DEFAULT '0' AFTER `client`",
        'undo'           => 'longtext AFTER `details`',
        'state'          => 'longtext AFTER `undo`',
        'irreversible'   => 'text AFTER `state`',
        'rollback_of'    => "int {$sign} NOT NULL DEFAULT '0' AFTER `irreversible`",
        'rolled_back_by' => "int {$sign} NOT NULL DEFAULT '0' AFTER `rollback_of`",
    ];
    foreach ($new_columns as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_logs', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_logs` ADD `{$col}` {$def}");
        }
    }

    // 1.4.0 : confirmation à usage unique atomique + approbation humaine vérifiée dans GLPI
    $pending_columns = [
        'status'            => "varchar(20) NOT NULL DEFAULT 'pending' AFTER `summary`",
        'profiles_id'       => "int {$sign} NOT NULL DEFAULT '0' AFTER `tokens_id`",
        'context_hash'      => "char(64) NOT NULL DEFAULT '' AFTER `status`",
        'payload_hash'      => "char(64) NOT NULL DEFAULT '' AFTER `context_hash`",
        'preview'           => 'longtext AFTER `payload_hash`',
        'preview_hash'      => "char(64) NOT NULL DEFAULT '' AFTER `preview`",
        'requires_approval' => "tinyint NOT NULL DEFAULT '0' AFTER `preview_hash`",
        'approval_reasons'  => 'text AFTER `requires_approval`',
        'approved_by'       => "int {$sign} NOT NULL DEFAULT '0' AFTER `approval_reasons`",
        'date_approval'     => 'timestamp NULL DEFAULT NULL AFTER `approved_by`',
        'approval_hash'     => "char(64) NOT NULL DEFAULT '' AFTER `date_approval`",
        'claim_token'       => "char(32) NOT NULL DEFAULT '' AFTER `approval_hash`",
        'date_claim'        => 'timestamp NULL DEFAULT NULL AFTER `claim_token`',
        'logs_id'           => "int {$sign} NOT NULL DEFAULT '0' AFTER `date_claim`",
        'error'             => 'text AFTER `logs_id`',
        'date_mod'          => 'timestamp NULL DEFAULT NULL AFTER `date_expiration`',
    ];
    foreach ($pending_columns as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_pendingactions', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_pendingactions` ADD `{$col}` {$def}");
        }
    }
    if (count($DB->request(['FROM' => 'information_schema.STATISTICS', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => 'glpi_plugin_glpimcp_pendingactions', 'INDEX_NAME' => 'status']])) === 0) {
        $DB->doQuery('ALTER TABLE `glpi_plugin_glpimcp_pendingactions` ADD KEY `status` (`status`)');
    }
    // Propositions antérieures à 1.4.0 : sans contexte ni empreinte, on ne peut pas les lier → retirées
    $DB->delete('glpi_plugin_glpimcp_pendingactions', ['context_hash' => '']);

    // 1.4.0 : périmètre du jeton = limite. Contexte choisi par glpi_switch_context séparé de l'autorisation.
    $scope_columns = [
        'context_entities_id'  => "int {$sign} DEFAULT NULL AFTER `is_recursive`",
        'context_is_recursive' => 'tinyint DEFAULT NULL AFTER `context_entities_id`',
    ];
    foreach ($scope_columns as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_tokens', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_tokens` ADD `{$col}` {$def}");
        }
    }
    // Jetons « profil par défaut » (profiles_id = 0) : fixés au profil par défaut actuel du compte,
    // ou à son profil unique ; sinon désactivés (jamais d'élargissement silencieux).
    \GlpiPlugin\Glpimcp\Mcp\TokenScope::migrateLegacyTokens();

    Config::setConfigurationValues('plugin:glpimcp', [
        'approval_scope'       => Config::getConfigurationValues('plugin:glpimcp', ['approval_scope'])['approval_scope'] ?? 'critical',
        'confirm_mode'         => Config::getConfigurationValues('plugin:glpimcp', ['confirm_mode'])['confirm_mode'] ?? 'all',
        'pending_ttl_minutes'  => Config::getConfigurationValues('plugin:glpimcp', ['pending_ttl_minutes'])['pending_ttl_minutes'] ?? 30,
        'max_search_results'   => Config::getConfigurationValues('plugin:glpimcp', ['max_search_results'])['max_search_results'] ?? 50,
        'oauth_enabled'        => Config::getConfigurationValues('plugin:glpimcp', ['oauth_enabled'])['oauth_enabled'] ?? 1,
        'oauth_dcr_enabled'    => Config::getConfigurationValues('plugin:glpimcp', ['oauth_dcr_enabled'])['oauth_dcr_enabled'] ?? 1,
        'oauth_access_ttl'     => Config::getConfigurationValues('plugin:glpimcp', ['oauth_access_ttl'])['oauth_access_ttl'] ?? 3600,
        'oauth_trusted_resources' => Config::getConfigurationValues('plugin:glpimcp', ['oauth_trusted_resources'])['oauth_trusted_resources'] ?? '[]',
    ]);

    // 0.7.0 : interrupteurs par type d'action (valeurs existantes conservées lors d'une mise à jour)
    $existing = Config::getConfigurationValues('plugin:glpimcp');
    $perms = [];
    foreach (\GlpiPlugin\Glpimcp\Mcp\Permissions::defaults() as $k => $v) {
        if (!array_key_exists($k, $existing)) {
            $perms[$k] = $v;
        }
    }
    if ($perms !== []) {
        Config::setConfigurationValues('plugin:glpimcp', $perms);
    }

    return true;
}

function plugin_glpimcp_uninstall(): bool
{
    global $DB;

    foreach (['glpi_plugin_glpimcp_tokens', 'glpi_plugin_glpimcp_pendingactions', 'glpi_plugin_glpimcp_logs', 'glpi_plugin_glpimcp_oauthclients', 'glpi_plugin_glpimcp_oauthcodes'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $config = new Config();
    $config->deleteConfigurationValues('plugin:glpimcp', array_merge(
        ['approval_scope', 'confirm_mode', 'pending_ttl_minutes', 'max_search_results', 'oauth_enabled', 'oauth_dcr_enabled', 'oauth_access_ttl', 'oauth_trusted_resources'],
        array_keys(\GlpiPlugin\Glpimcp\Mcp\Permissions::defaults())
    ));

    return true;
}
