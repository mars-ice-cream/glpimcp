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

    // 1.5.0 : clients IA gérés par l'entreprise (profil imposé, consentement implicite après SSO, IP)
    $client_columns = [
        'managed'            => "tinyint NOT NULL DEFAULT '0' AFTER `is_active`",
        'forced_profiles_id' => "int {$sign} NOT NULL DEFAULT '0' AFTER `managed`",
        'skip_consent'       => "tinyint NOT NULL DEFAULT '0' AFTER `forced_profiles_id`",
        'token_days'         => "int NOT NULL DEFAULT '0' AFTER `skip_consent`",
        'allowed_ips'        => 'text AFTER `token_days`',
        'rl_requests_per_minute' => 'int DEFAULT NULL AFTER `allowed_ips`',
        'rl_writes_per_hour'     => 'int DEFAULT NULL AFTER `rl_requests_per_minute`',
        'rl_tickets_per_hour'    => 'int DEFAULT NULL AFTER `rl_writes_per_hour`',
        'rl_client_writes_per_hour' => 'int DEFAULT NULL AFTER `rl_tickets_per_hour`',
        'date_mod'           => 'timestamp NULL DEFAULT NULL AFTER `last_use`',
    ];
    foreach ($client_columns as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_oauthclients', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_oauthclients` ADD `{$col}` {$def}");
        }
    }
    // 1.5.0 : limites par jeton (surcharges) et suspension automatique
    $token_limits = [
        'rl_requests_per_minute' => 'int DEFAULT NULL',
        'rl_writes_per_hour'     => 'int DEFAULT NULL',
        'rl_tickets_per_hour'    => 'int DEFAULT NULL',
        'suspended_reason'       => 'text',
        'date_suspended'         => 'timestamp NULL DEFAULT NULL',
    ];
    foreach ($token_limits as $col => $def) {
        if (!$DB->fieldExists('glpi_plugin_glpimcp_tokens', $col)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_glpimcp_tokens` ADD `{$col}` {$def}");
        }
    }
    // Compteurs à fenêtre fixe (limitation de débit), incrémentés atomiquement
    if (!$DB->tableExists('glpi_plugin_glpimcp_counters')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_counters` (
            `k` varchar(100) NOT NULL DEFAULT '',
            `win` int {$sign} NOT NULL DEFAULT '0',
            `n` int {$sign} NOT NULL DEFAULT '0',
            `exp` int {$sign} NOT NULL DEFAULT '0',
            PRIMARY KEY (`k`, `win`),
            KEY `exp` (`exp`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // 1.6.0 : mode d'octroi des accès OAuth (consentement explicite ou implicite d'un client géré),
    // pour couper les accès implicites quand l'administrateur retire ce mode
    foreach (['glpi_plugin_glpimcp_tokens', 'glpi_plugin_glpimcp_oauthcodes'] as $t) {
        if (!$DB->fieldExists($t, 'grant_mode')) {
            $DB->doQuery("ALTER TABLE `{$t}` ADD `grant_mode` varchar(10) NOT NULL DEFAULT 'consent'");
            if ($t === 'glpi_plugin_glpimcp_tokens') {
                // Accès existants des clients actuellement gérés sans consentement : comptés comme implicites (prudent)
                $DB->doQuery("UPDATE `glpi_plugin_glpimcp_tokens` t JOIN `glpi_plugin_glpimcp_oauthclients` c ON c.id = t.oauthclients_id"
                    . " SET t.grant_mode = 'implicit' WHERE c.managed = 1 AND c.skip_consent = 1");
            }
        }
    }

    // 1.6.0 : politique de l'assistant IA par profil GLPI (outils autorisés, types d'action retirés)
    if (!$DB->tableExists('glpi_plugin_glpimcp_profilepolicies')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpimcp_profilepolicies` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `profiles_id` int {$sign} NOT NULL DEFAULT '0',
            `tools_mode` varchar(10) NOT NULL DEFAULT 'all',
            `tools` text,
            `denied_categories` text,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `profiles_id` (`profiles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    $keep = static fn(string $k, mixed $default) => Config::getConfigurationValues('plugin:glpimcp', [$k])[$k] ?? $default;
    Config::setConfigurationValues('plugin:glpimcp', [
        'rl_requests_per_minute' => $keep('rl_requests_per_minute', 600),
        'rl_writes_per_hour'     => $keep('rl_writes_per_hour', 200),
        'rl_tickets_per_hour'    => $keep('rl_tickets_per_hour', 20),
        'rl_suspend_after'       => $keep('rl_suspend_after', 50),
        'alert_emails'           => $keep('alert_emails', ''),
        'trusted_proxies'        => $keep('trusted_proxies', ''),
        'banned_tools'           => $keep('banned_tools', '[]'),
        'access_mode'            => $keep('access_mode', 'all'),
        'access_users'           => $keep('access_users', '[]'),
        'access_groups'          => $keep('access_groups', '[]'),
        'access_profiles'        => $keep('access_profiles', '[]'),
        'blocked_users'          => $keep('blocked_users', '[]'),
    ]);

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

    foreach (['glpi_plugin_glpimcp_tokens', 'glpi_plugin_glpimcp_pendingactions', 'glpi_plugin_glpimcp_logs', 'glpi_plugin_glpimcp_oauthclients', 'glpi_plugin_glpimcp_oauthcodes', 'glpi_plugin_glpimcp_counters', 'glpi_plugin_glpimcp_profilepolicies'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $config = new Config();
    $config->deleteConfigurationValues('plugin:glpimcp', array_merge(
        ['rl_requests_per_minute', 'rl_writes_per_hour', 'rl_tickets_per_hour', 'rl_suspend_after', 'alert_emails', 'trusted_proxies',
         'approval_scope', 'confirm_mode', 'pending_ttl_minutes', 'max_search_results', 'oauth_enabled', 'oauth_dcr_enabled', 'oauth_access_ttl', 'oauth_trusted_resources'],
        array_keys(\GlpiPlugin\Glpimcp\Mcp\Permissions::defaults())
    ));

    return true;
}
