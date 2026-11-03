<?php

/**
 * GLPI MCP — page de configuration (Configuration > Plugins > GLPI MCP)
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Mcp\AuditLog;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Permissions;
use GlpiPlugin\Glpimcp\Mcp\Server;

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    $mode = in_array($_POST['confirm_mode'] ?? '', ['all', 'sensitive'], true) ? $_POST['confirm_mode'] : 'all';
    $approval = in_array($_POST['approval_scope'] ?? '', \GlpiPlugin\Glpimcp\Mcp\Approval::SCOPES, true) ? $_POST['approval_scope'] : 'critical';
    $before = Config::getConfigurationValues('plugin:glpimcp', ['confirm_mode', 'approval_scope']);
    Config::setConfigurationValues('plugin:glpimcp', [
        'approval_scope'      => $approval,
        'confirm_mode'        => $mode,
        'pending_ttl_minutes' => max(1, min(1440, (int) ($_POST['pending_ttl_minutes'] ?? 30))),
        'max_search_results'  => max(1, min(200, (int) ($_POST['max_search_results'] ?? 50))),
        'oauth_enabled'       => (int) !empty($_POST['oauth_enabled']),
        'oauth_dcr_enabled'   => (int) !empty($_POST['oauth_dcr_enabled']),
        'oauth_access_ttl'    => max(300, min(86400, (int) ($_POST['oauth_access_ttl'] ?? 3600))),
        'oauth_trusted_resources' => json_encode(array_values(array_filter(array_map(
            fn($u) => rtrim(trim($u), '/'),
            preg_split('/\R/', (string) ($_POST['oauth_trusted_resources'] ?? ''))
        ), fn($u) => filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $u)))),
    ]);
    if (($before['confirm_mode'] ?? 'all') !== $mode || ($before['approval_scope'] ?? 'critical') !== $approval) {
        \GlpiPlugin\Glpimcp\Mcp\AuditLog::write(
            new \GlpiPlugin\Glpimcp\Mcp\Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']),
            'configuration', 'exécuté', 'Politique de confirmation modifiée',
            ['avant' => $before, 'après' => ['confirm_mode' => $mode, 'approval_scope' => $approval]]
        );
    }
    Session::addMessageAfterRedirect(__('Configuration updated', 'glpimcp'));
    Html::back();
}
if (isset($_POST['update_permissions'])) {
    $before = Permissions::all();
    $values = [];
    foreach (array_keys(Permissions::CATEGORIES) as $k) {
        $values['perm_' . $k] = (int) !empty($_POST['perm_' . $k]);
    }
    Config::setConfigurationValues('plugin:glpimcp', $values);
    $changes = [];
    foreach ($before as $k => $on) {
        if ((int) $on !== $values['perm_' . $k]) {
            $changes[] = Permissions::CATEGORIES[$k][0] . ($values['perm_' . $k] ? ' : activé' : ' : désactivé');
        }
    }
    if ($changes !== []) {
        // Traçabilité : toute modification des interrupteurs est inscrite au journal IA
        AuditLog::write(
            new Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']),
            'plugin_settings',
            'exécuté',
            'Types d\'actions IA modifiés : ' . implode(', ', $changes),
            ['avant' => $before, 'après' => array_map(fn($v) => (bool) $v, array_combine(array_keys(Permissions::CATEGORIES), array_values($values)))]
        );
    }
    Session::addMessageAfterRedirect(__('Configuration updated', 'glpimcp'));
    Html::back();
}
if (isset($_POST['client_toggle'])) {
    global $DB;
    $cid = (int) $_POST['id'];
    $active = (int) !empty($_POST['is_active']);
    $DB->update('glpi_plugin_glpimcp_oauthclients', ['is_active' => $active], ['id' => $cid]);
    if (!$active) {
        // Couper aussi tous les accès déjà accordés à ce client
        $DB->update('glpi_plugin_glpimcp_tokens', ['is_active' => 0], ['oauthclients_id' => $cid]);
    }
    Session::addMessageAfterRedirect(__('Configuration updated', 'glpimcp'));
    Html::back();
}
if (isset($_POST['revoke'])) {
    global $DB;
    $DB->update('glpi_plugin_glpimcp_tokens', ['is_active' => 0], ['id' => (int) $_POST['id']]);
    Session::addMessageAfterRedirect(__('Token revoked.', 'glpimcp'));
    Html::back();
}

global $DB, $CFG_GLPI;
$cfg = Config::getConfigurationValues('plugin:glpimcp');

Html::header('GLPI MCP', '', 'config', 'plugin');

$endpoint = rtrim($CFG_GLPI['url_base'] ?? '', '/') . '/plugins/glpimcp/mcp';
$csrf = Session::getNewCSRFToken();

echo '<div class="container-fluid">';

// --- Réglages ---------------------------------------------------------------
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-robot me-2"></i>GLPI MCP — ' . __('Settings') . '</h3></div><div class="card-body">';
echo '<p>' . __('MCP server URL', 'glpimcp') . ' : <code>' . htmlescape($endpoint) . '</code></p>';
echo '<form method="post" action="">';
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
echo '<div class="row g-3">';
echo '<div class="col-md-5"><label class="form-label">' . __('Confirmation of AI changes', 'glpimcp') . '</label>';
echo '<select name="confirm_mode" class="form-select">';
echo '<option value="all"' . (($cfg['confirm_mode'] ?? 'all') === 'all' ? ' selected' : '') . '>' . __('Every change is previewed and must be confirmed (recommended)', 'glpimcp') . '</option>';
echo '<option value="sensitive"' . (($cfg['confirm_mode'] ?? '') === 'sensitive' ? ' selected' : '') . '>' . __('Ordinary changes are applied immediately; only sensitive ones need a confirmation', 'glpimcp') . '</option>';
echo '</select><small class="text-muted">' . __('The confirmation is a second call made by the assistant: it does not prove that a human saw the preview.', 'glpimcp') . '</small></div>';
echo '<div class="col-md-4"><label class="form-label">' . __('Human approval in GLPI', 'glpimcp') . '</label>';
echo '<select name="approval_scope" class="form-select">';
echo '<option value="critical"' . (($cfg['approval_scope'] ?? 'critical') === 'critical' ? ' selected' : '') . '>' . __('Critical actions (permanent deletion, users and groups, profile rights, configuration, forced undo)', 'glpimcp') . '</option>';
echo '<option value="sensitive"' . (($cfg['approval_scope'] ?? '') === 'sensitive' ? ' selected' : '') . '>' . __('All sensitive actions (also trash, rules, entities, SLA, notifications, merges)', 'glpimcp') . '</option>';
echo '</select><small class="text-muted">' . __('The user approves in GLPI, logged in with their own account: the assistant cannot do it.', 'glpimcp') . '</small></div>';
echo '<div class="col-md-3"><label class="form-label">' . __('Preview validity (minutes)', 'glpimcp') . '</label>';
echo '<input type="number" min="1" max="1440" name="pending_ttl_minutes" class="form-control" value="' . (int) ($cfg['pending_ttl_minutes'] ?? 30) . '"></div>';
echo '<div class="col-md-3"><label class="form-label">' . __('Max results per search', 'glpimcp') . '</label>';
echo '<input type="number" min="1" max="200" name="max_search_results" class="form-control" value="' . (int) ($cfg['max_search_results'] ?? 50) . '"></div>';
echo '</div>';

$check = fn($name, $label, $default = 1) => '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . $name . '" value="1" id="' . $name . '"'
    . (((int) ($cfg[$name] ?? $default)) ? ' checked' : '') . '><label class="form-check-label" for="' . $name . '">' . $label . '</label></div>';
echo '<h4 class="mt-4">' . __('Remote AI connectors (OAuth): claude.ai, ChatGPT, Le Chat…', 'glpimcp') . '</h4>';
echo '<div class="row g-3">';
echo '<div class="col-md-6">' . $check('oauth_enabled', __('Allow connection through OAuth (user logs in to GLPI and approves)', 'glpimcp'));
echo $check('oauth_dcr_enabled', __('Allow AI clients to register automatically (needed by claude.ai and ChatGPT)', 'glpimcp')) . '</div>';
echo '<div class="col-md-3"><label class="form-label">' . __('Access token lifetime (seconds)', 'glpimcp') . '</label>';
echo '<input type="number" min="300" max="86400" name="oauth_access_ttl" class="form-control" value="' . (int) ($cfg['oauth_access_ttl'] ?? 3600) . '"></div>';
echo '<div class="col-md-12"><label class="form-label">' . __('Trusted relay servers (one MCP URL per line, e.g. Dokore)', 'glpimcp') . '</label>';
echo '<textarea name="oauth_trusted_resources" rows="2" class="form-control" placeholder="https://dokore.example.fr/mcp">' . htmlescape(implode("\n", \GlpiPlugin\Glpimcp\OAuth\OAuthServer::trustedResources())) . '</textarea>';
echo '<small class="text-muted">' . __('An AI client connected to such a relay can obtain a token here; the relay forwards it and GLPI rights still apply. Leave empty if you do not use one.', 'glpimcp') . '</small></div>';
echo '</div>';
if (!str_starts_with((string) ($CFG_GLPI['url_base'] ?? ''), 'https://')) {
    echo '<div class="alert alert-warning mt-3 mb-0">' . __('The application URL is not in HTTPS: claude.ai and ChatGPT will refuse to connect. Set a public HTTPS URL in Setup > General.', 'glpimcp') . '</div>';
}
echo '<button type="submit" name="update" class="btn btn-primary mt-3">' . _sx('button', 'Save') . '</button>';
echo '</form>';
echo '<p class="text-muted mt-3 mb-0">' . sprintf(__('%d tools exposed. Each user generates their own token in My settings > AI assistant (MCP); the assistant then acts with exactly that user\'s rights.', 'glpimcp'), count(Server::toolClasses())) . '</p>';
echo '</div></div>';

// --- Types d'actions autorisés ------------------------------------------------
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-shield-lock me-2"></i>' . __('Actions allowed for the AI assistant', 'glpimcp') . '</h3></div><div class="card-body">';
echo '<p class="text-muted">' . __('These switches come on top of GLPI rights: an action must be allowed by the user\'s profile AND enabled here. Risky actions are disabled by default. Every change is recorded in the AI log.', 'glpimcp') . '</p>';
echo '<form method="post" action="">' . Html::hidden('_glpi_csrf_token', ['value' => $csrf]) . '<div class="row g-2">';
$perms = Permissions::all();
foreach (Permissions::CATEGORIES as $k => [$label, $desc, $default]) {
    echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="perm_' . $k . '" value="1" id="perm_' . $k . '"' . ($perms[$k] ? ' checked' : '') . '>';
    echo '<label class="form-check-label" for="perm_' . $k . '"><strong>' . htmlescape($label) . '</strong>' . ($default ? '' : ' <span class="badge bg-orange-lt">' . __('risky', 'glpimcp') . '</span>') . '<br><small class="text-muted">' . htmlescape($desc) . '</small></label></div></div>';
}
echo '</div><button type="submit" name="update_permissions" class="btn btn-primary mt-3">' . _sx('button', 'Save') . '</button></form>';
echo '<p class="text-muted mt-2 mb-0"><small>' . __('Reading is always allowed (within GLPI rights). Remote access to files from URLs is never available.', 'glpimcp') . '</small></p>';
echo '</div></div>';

// --- Clients OAuth -----------------------------------------------------------
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __('Registered AI clients (OAuth)', 'glpimcp') . '</h3></div><div class="card-body p-0">';
echo '<table class="table table-sm table-hover mb-0"><thead><tr><th>' . __('Name') . '</th><th>' . __('Redirection', 'glpimcp') . '</th><th>' . __('Creation date') . '</th><th>' . __('Last use', 'glpimcp') . '</th><th>' . __('Active users', 'glpimcp') . '</th><th></th></tr></thead><tbody>';
$clients = $DB->request(['FROM' => 'glpi_plugin_glpimcp_oauthclients', 'ORDER' => 'id DESC', 'LIMIT' => 100]);
foreach ($clients as $c) {
    $hosts = array_unique(array_map(fn($u) => parse_url((string) $u, PHP_URL_HOST), json_decode((string) $c['redirect_uris'], true) ?: []));
    $nb = countElementsInTable('glpi_plugin_glpimcp_tokens', ['oauthclients_id' => $c['id'], 'is_active' => 1]);
    echo '<tr><td>' . htmlescape($c['name']) . ($c['is_active'] ? '' : ' <span class="badge bg-red-lt">' . __('Disabled', 'glpimcp') . '</span>') . '</td>';
    echo '<td>' . htmlescape(implode(', ', $hosts)) . '</td><td>' . Html::convDateTime($c['date_creation']) . '</td>';
    echo '<td>' . ($c['last_use'] ? Html::convDateTime($c['last_use']) : '—') . '</td><td>' . (int) $nb . '</td><td>';
    echo '<form method="post" action="" class="d-inline">' . Html::hidden('_glpi_csrf_token', ['value' => $csrf]) . Html::hidden('id', ['value' => $c['id']]) . Html::hidden('is_active', ['value' => $c['is_active'] ? 0 : 1]);
    echo '<button type="submit" name="client_toggle" class="btn btn-sm ' . ($c['is_active'] ? 'btn-outline-danger">' . __('Disable and cut access', 'glpimcp') : 'btn-outline-success">' . __('Enable', 'glpimcp')) . '</button></form></td></tr>';
}
if (count($clients) === 0) {
    echo '<tr><td colspan="6" class="text-muted p-3">' . __('No AI client connected through OAuth yet.', 'glpimcp') . '</td></tr>';
}
echo '</tbody></table></div></div>';

// --- Jetons actifs ------------------------------------------------------------
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __('Active tokens', 'glpimcp') . '</h3></div><div class="card-body p-0">';
echo '<table class="table table-sm table-hover mb-0"><thead><tr><th>' . User::getTypeName(1) . '</th><th>' . __('Name') . '</th><th>' . __('Token', 'glpimcp') . '</th><th>' . __('Expiration date') . '</th><th>' . __('Last use', 'glpimcp') . '</th><th></th></tr></thead><tbody>';
$tokens = $DB->request(['FROM' => 'glpi_plugin_glpimcp_tokens', 'WHERE' => ['is_active' => 1], 'ORDER' => 'last_use DESC']);
foreach ($tokens as $t) {
    echo '<tr><td>' . htmlescape(getUserName($t['users_id'])) . '</td><td>' . htmlescape($t['name']) . '</td><td><code>' . htmlescape($t['token_hint']) . '</code></td>';
    echo '<td>' . ($t['date_expiration'] ? Html::convDateTime($t['date_expiration']) : __('Never')) . '</td>';
    echo '<td>' . ($t['last_use'] ? Html::convDateTime($t['last_use']) : '—') . '</td><td>';
    echo '<form method="post" action="" class="d-inline">' . Html::hidden('_glpi_csrf_token', ['value' => $csrf]) . Html::hidden('id', ['value' => $t['id']]);
    echo '<button type="submit" name="revoke" class="btn btn-sm btn-outline-danger">' . __('Revoke', 'glpimcp') . '</button></form></td></tr>';
}
if (count($tokens) === 0) {
    echo '<tr><td colspan="6" class="text-muted p-3">' . __('No token yet.', 'glpimcp') . '</td></tr>';
}
echo '</tbody></table></div></div>';

// --- Journal ------------------------------------------------------------------
echo '<div class="card"><div class="card-header"><h3 class="card-title">' . __('Changes made through the AI assistant (last 200)', 'glpimcp') . '</h3></div><div class="card-body p-0">';
\GlpiPlugin\Glpimcp\LogView::renderTable([], 200);
echo '</div></div>';

echo '</div>';
Html::footer();
