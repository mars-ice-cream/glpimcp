<?php

/**
 * GLPI MCP — détail d'une action IA et annulation (rollback)
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\LogView;
use GlpiPlugin\Glpimcp\Mcp\AuditLog;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Operations;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Rollback;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

Session::checkLoginUser();

$id  = (int) ($_REQUEST['id'] ?? 0);
$log = AuditLog::get($id);
$me  = (int) Session::getLoginUserID();
$is_admin = Session::haveRight('config', UPDATE);

if ($log === null || ((int) $log['users_id'] !== $me && !$is_admin)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

// --- Exécution de l'annulation (le bouton n'apparaît qu'après affichage de l'aperçu) ---
if (isset($_POST['rollback'])) {
    $force = !empty($_POST['force']);
    try {
        $plan = Rollback::plan($id, $force);
        Operations::check($plan['ops']); // droits revérifiés avant exécution
        $ctx = new Context($me, ['id' => 0, 'name' => 'Interface GLPI']);
        $res = PendingActions::run(
            $ctx,
            'rollback (interface)',
            sprintf("Annulation de l'action IA #%d : %s", $id, $log['summary']),
            $plan['ops'],
            ['rollback_of' => $id]
        );
        Session::addMessageAfterRedirect(sprintf(__('AI action #%1$d undone (log #%2$d).', 'glpimcp'), $id, $res['journal_id']));
        Html::redirect(LogView::url((int) $res['journal_id']));
    } catch (ToolException $e) {
        Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
        Html::redirect(LogView::url($id));
    }
}

Html::header('GLPI MCP', '', 'config', 'plugin');

$details = json_decode((string) $log['details'], true) ?: [];
$undo    = json_decode((string) ($log['undo'] ?? ''), true) ?: [];
$irrev   = json_decode((string) ($log['irreversible'] ?? ''), true) ?: [];
$badge   = LogView::STATUS_BADGES[$log['status']] ?? 'bg-secondary-lt';

echo '<div class="container-fluid">';
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-robot me-2"></i>' . sprintf(__('AI action #%d', 'glpimcp'), $id) . '</h3></div><div class="card-body">';
echo '<dl class="row mb-0">';
$line = fn($k, $v) => print('<dt class="col-sm-3">' . $k . '</dt><dd class="col-sm-9">' . $v . '</dd>');
$line(__('Date'), Html::convDateTime($log['date_creation']));
$line(User::getTypeName(1), htmlescape(getUserName($log['users_id'])));
$line(__('Assistant', 'glpimcp'), htmlescape($log['client'] ?: '—'));
$line(Profile::getTypeName(1), $log['profiles_id'] ? htmlescape(Dropdown::getDropdownName('glpi_profiles', $log['profiles_id'])) : '—');
$line(__('Tool', 'glpimcp'), '<code>' . htmlescape($log['tool']) . '</code>');
$line(__('Status'), '<span class="badge ' . $badge . '">' . htmlescape(LogView::statusLabel((string) $log['status'])) . '</span>');
$line(__('Summary', 'glpimcp'), htmlescape($log['summary']));
if ((int) $log['rollback_of']) {
    $line(__('Undoes', 'glpimcp'), '<a href="' . htmlescape(LogView::url((int) $log['rollback_of'])) . '">#' . (int) $log['rollback_of'] . '</a>');
}
if ((int) $log['rolled_back_by']) {
    $line(__('Undone by', 'glpimcp'), '<a href="' . htmlescape(LogView::url((int) $log['rolled_back_by'])) . '">#' . (int) $log['rolled_back_by'] . '</a>');
}
echo '</dl></div></div>';

// Résultats / erreur
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . __('What was done', 'glpimcp') . '</h3></div><div class="card-body">';
if (!empty($details['résultats'])) {
    echo '<ul class="mb-0">';
    foreach ($details['résultats'] as $r) {
        $label = htmlescape(($r['résultat'] ?? '') . ' — ' . ($r['type'] ?? '') . ' ' . ($r['nom'] ?? ($r['formulaire'] ?? '')));
        echo '<li>' . (!empty($r['url']) ? '<a href="' . htmlescape($r['url']) . '">' . $label . '</a>' : $label);
        foreach ($r['objets_créés'] ?? [] as $c) {
            echo '<br><small>→ ' . htmlescape($c['type'] . ' ' . $c['nom']) . '</small>';
        }
        echo '</li>';
    }
    echo '</ul>';
} elseif (!empty($details['erreur'])) {
    echo '<div class="alert alert-danger mb-0">' . htmlescape($details['erreur']) . '</div>';
} else {
    echo '<p class="text-muted mb-0">' . __('Nothing was modified.', 'glpimcp') . '</p>';
}
echo '</div></div>';

// Annulation
if ($log['status'] === 'exécuté' && $undo !== [] && !(int) $log['rolled_back_by']) {
    $force = !empty($_GET['force']);
    echo '<div class="card mb-3 border-warning"><div class="card-header"><h3 class="card-title"><i class="ti ti-arrow-back-up me-2"></i>' . __('Undo this action', 'glpimcp') . '</h3></div><div class="card-body">';
    try {
        $plan = Rollback::plan($id, $force);
        Operations::check($plan['ops']);
        echo '<p>' . __('The following steps will be applied, in a single transaction (all or nothing), with your GLPI rights:', 'glpimcp') . '</p><ol>';
        foreach ($plan['étapes'] as $s) {
            echo '<li>' . htmlescape($s) . '</li>';
        }
        echo '</ol>';
        foreach ([['conflits', 'alert-warning', __('Changed since the AI action (will be overwritten):', 'glpimcp')], ['irréversible', 'alert-secondary', __('Cannot be undone:', 'glpimcp')], ['avertissements', 'alert-info', __('Notes:', 'glpimcp')]] as [$key, $cls, $title]) {
            if (!empty($plan[$key])) {
                echo '<div class="alert ' . $cls . '"><strong>' . $title . '</strong><ul class="mb-0">';
                foreach ($plan[$key] as $c) {
                    echo '<li>' . htmlescape($c) . '</li>';
                }
                echo '</ul></div>';
            }
        }
        echo '<form method="post" action="' . htmlescape(LogView::url($id)) . '">';
        echo \GlpiPlugin\Glpimcp\Compat::csrfField();
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('force', ['value' => $force ? 1 : 0]);
        echo '<button type="submit" name="rollback" value="1" class="btn btn-warning"><i class="ti ti-arrow-back-up me-1"></i>' . __('Confirm the undo', 'glpimcp') . '</button>';
        echo '</form>';
    } catch (ToolException $e) {
        echo '<div class="alert alert-warning">' . htmlescape($e->getMessage());
        $d = $e->getDetails();
        if (!empty($d['conflits'])) {
            echo '<ul class="mb-0 mt-2">';
            foreach ($d['conflits'] as $c) {
                echo '<li>' . htmlescape($c) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        if (!empty($d['conflits'])) {
            echo '<a class="btn btn-outline-warning" href="' . htmlescape(LogView::url($id) . '&force=1') . '">' . __('Show the undo anyway', 'glpimcp') . '</a>';
        }
    }
    echo '</div></div>';
} elseif ($irrev) {
    echo '<div class="alert alert-secondary"><strong>' . __('Cannot be undone:', 'glpimcp') . '</strong><ul class="mb-0">';
    foreach ($irrev as $c) {
        echo '<li>' . htmlescape($c) . '</li>';
    }
    echo '</ul></div>';
}

echo '<a href="javascript:history.back()" class="btn btn-ghost-secondary">' . __('Back') . '</a>';
echo '</div>';
Html::footer();
