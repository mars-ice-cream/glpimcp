<?php

/**
 * Approbation humaine d'une action critique proposée par l'assistant IA.
 *
 * Exige une session GLPI authentifiée (Session::checkLoginUser) ; les POST sont protégés contre le CSRF par
 * le noyau GLPI avant ce script (jeton avec GLPI 11, en-têtes Sec-Fetch-Site / Origin avec GLPI 12). Avec
 * GLPI 12, approuver exige en plus une ré-authentification récente, comme les objets sensibles de GLPI. Seul l'utilisateur pour qui l'assistant agit peut
 * décider. L'approbation est liée par empreinte à la proposition exacte et à son contexte.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

$me   = (int) Session::getLoginUserID();
$self = $CFG_GLPI['root_doc'] . '/plugins/glpimcp/front/approval.php';
$uuid = (string) ($_POST['uuid'] ?? $_GET['uuid'] ?? '');
$row  = preg_match('/^conf_[0-9a-f]{32}$/', $uuid) ? PendingActions::getByUuid($uuid) : null;

// Une proposition d'un autre utilisateur est traitée comme inexistante (pas de fuite d'information)
if ($row !== null && (int) $row['users_id'] !== $me) {
    $row = null;
}

if ($row !== null && isset($_POST['decision'])) {
    if ($_POST['decision'] === 'approve') {
        // GLPI 12 : ré-authentification exigée (mot de passe, LDAP, CAS ou TOTP), puis la demande est rejouée
        \GlpiPlugin\Glpimcp\Compat::requireReauth();
    }
    try {
        $res = PendingActions::decide($row, $me, $_POST['decision'] === 'approve');
        Session::addMessageAfterRedirect(
            $res['statut'] === 'approuvée'
                ? __('Action approved. You can tell the assistant to go ahead: it will now be able to confirm it.', 'glpimcp')
                : __('Action refused. Nothing has been changed.', 'glpimcp'),
            false,
            $res['statut'] === 'approuvée' ? INFO : WARNING
        );
    } catch (ToolException $e) {
        Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
    }
    Html::redirect($self . '?uuid=' . rawurlencode($uuid));
}

Html::header(__('AI assistant: approval', 'glpimcp'), '', 'admin', 'config');
echo '<div class="container-lg py-3">';

if ($row === null) {
    echo '<div class="alert alert-warning">' . __('This proposal does not exist, has expired, or does not concern your account.', 'glpimcp') . '</div>';
    echo '</div>';
    Html::footer();
    return;
}

$data    = json_decode((string) $row['preview'], true) ?: [];
$reasons = json_decode((string) $row['approval_reasons'], true) ?: [];
$ctx     = $data['contexte'] ?? [];
$expired = strtotime((string) $row['date_expiration']) < time();
$status_labels = [
    'pending'  => (int) $row['approved_by'] > 0 ? __('Approved, waiting for the assistant to execute it', 'glpimcp') : __('Waiting for your decision', 'glpimcp'),
    'claimed'  => __('Being executed', 'glpimcp'),
    'executed' => __('Executed', 'glpimcp'),
    'failed'   => __('Failed (nothing was applied)', 'glpimcp'),
    'stale'    => __('Obsolete: data changed since the preview', 'glpimcp'),
    'cancelled' => __('Cancelled', 'glpimcp'),
    'refused'  => __('Refused', 'glpimcp'),
];

$fmt = static function (mixed $v): string {
    if ($v === null || $v === '') {
        return '<span class="text-muted">—</span>';
    }
    if (is_bool($v)) {
        return $v ? __('Yes') : __('No');
    }
    if (is_array($v)) {
        return '<code>' . htmlescape(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
    }
    return htmlescape((string) $v);
};

echo '<div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-shield-check me-2"></i>'
    . __('An AI assistant asks to perform a critical action on your behalf', 'glpimcp') . '</h3></div><div class="card-body">';
echo '<p class="fs-3 mb-2"><strong>' . htmlescape((string) $row['summary']) . '</strong></p>';
echo '<dl class="row mb-3">';
echo '<dt class="col-sm-3">' . __('Assistant', 'glpimcp') . '</dt><dd class="col-sm-9">' . htmlescape((string) ($ctx['assistant'] ?? '')) . '</dd>';
echo '<dt class="col-sm-3">' . __('Profile') . '</dt><dd class="col-sm-9">' . htmlescape((string) ($ctx['profil'] ?? '')) . '</dd>';
echo '<dt class="col-sm-3">' . __('Entity') . '</dt><dd class="col-sm-9">' . htmlescape((string) ($ctx['entité'] ?? '')) . (!empty($ctx['sous_entités']) ? ' ' . __('(with sub-entities)', 'glpimcp') : '') . '</dd>';
echo '<dt class="col-sm-3">' . __('Proposed on', 'glpimcp') . '</dt><dd class="col-sm-9">' . Html::convDateTime($row['date_creation']) . '</dd>';
echo '<dt class="col-sm-3">' . __('Expires on', 'glpimcp') . '</dt><dd class="col-sm-9">' . Html::convDateTime($row['date_expiration']) . '</dd>';
echo '<dt class="col-sm-3">' . __('Status') . '</dt><dd class="col-sm-9">' . htmlescape($status_labels[$row['status']] ?? $row['status']) . ($expired && $row['status'] === 'pending' ? ' — ' . __('expired', 'glpimcp') : '') . '</dd>';
if ((int) $row['approved_by'] > 0) {
    echo '<dt class="col-sm-3">' . __('Approved by', 'glpimcp') . '</dt><dd class="col-sm-9">' . htmlescape(getUserName((int) $row['approved_by'])) . ', ' . Html::convDateTime($row['date_approval']) . '</dd>';
}
echo '</dl>';

echo '<div class="alert alert-warning"><strong>' . __('Why your approval is required:', 'glpimcp') . '</strong><ul class="mb-0">';
foreach ($reasons as $r) {
    echo '<li>' . htmlescape((string) $r) . '</li>';
}
echo '</ul></div>';

if (!empty($data['annulation'])) {
    echo '<div class="alert alert-info">' . sprintf(__('This is the undo of AI action #%d.', 'glpimcp'), (int) $data['annulation']['de_l_action']);
    if (!empty($data['annulation']['forcée'])) {
        echo ' <strong>' . __('Forced: these later changes will be overwritten:', 'glpimcp') . '</strong><ul class="mb-0">';
        foreach ($data['annulation']['conflits_acceptés'] ?? [] as $c) {
            echo '<li>' . htmlescape((string) $c) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

echo '<h4>' . __('Exact changes', 'glpimcp') . '</h4>';
echo '<table class="table table-sm table-bordered align-middle"><thead><tr><th>#</th><th>' . __('Action') . '</th><th>' . __('Target', 'glpimcp') . '</th><th>' . __('Before → after / values', 'glpimcp') . '</th><th>' . __('Can be undone', 'glpimcp') . '</th></tr></thead><tbody>';
foreach ($data['aperçu'] ?? [] as $op) {
    $target = trim(($op['type'] ?? '') . ' ' . ($op['objet'] ?? $op['contexte'] ?? $op['formulaire'] ?? $op['logiciel'] ?? ''));
    $changes = '';
    if (!empty($op['changements']) && is_array($op['changements'])) {
        foreach ($op['changements'] as $field => $c) {
            $changes .= '<div><strong>' . htmlescape((string) $field) . '</strong> : ' . $fmt($c['avant'] ?? null) . ' → ' . $fmt($c['après'] ?? null) . '</div>';
        }
    } elseif (!empty($op['valeurs']) && is_array($op['valeurs'])) {
        foreach ($op['valeurs'] as $field => $v) {
            $changes .= '<div><strong>' . htmlescape((string) $field) . '</strong> : ' . $fmt($v) . '</div>';
        }
    } else {
        $rest = array_diff_key($op, array_flip(['n', 'action', 'type', 'objet', 'annulable', 'itemtype', 'ref']));
        $changes = $rest !== [] ? $fmt($rest) : '';
    }
    $undo = (string) ($op['annulable'] ?? '');
    $undo_class = str_starts_with($undo, 'NON') ? 'text-danger fw-bold' : (str_starts_with($undo, 'partielle') ? 'text-warning' : '');
    echo '<tr><td>' . (int) ($op['n'] ?? 0) . '</td><td>' . htmlescape((string) ($op['action'] ?? '')) . '</td><td>' . htmlescape($target) . '</td><td>' . $changes . '</td>'
        . '<td class="' . $undo_class . '">' . htmlescape($undo) . '</td></tr>';
}
echo '</tbody></table>';

if (!empty($data['avertissements'])) {
    echo '<div class="alert alert-secondary"><strong>' . __('Warnings', 'glpimcp') . '</strong><ul class="mb-0">';
    foreach ($data['avertissements'] as $w) {
        echo '<li>' . htmlescape((string) $w) . '</li>';
    }
    echo '</ul></div>';
}
echo '<p class="text-muted small">' . __('Effects outside the GLPI database (e-mails already sent, calls to external services by other plugins) cannot be undone by the AI journal.', 'glpimcp') . '</p>';

if ($row['status'] === 'pending' && !$expired && (int) $row['approved_by'] === 0) {
    echo '<form method="post" action="' . htmlescape($self) . '" class="d-flex gap-2 justify-content-end">';
    echo Html::hidden('uuid', ['value' => $uuid]);
    echo \GlpiPlugin\Glpimcp\Compat::csrfField();
    echo '<button type="submit" name="decision" value="refuse" class="btn btn-outline-secondary"><i class="ti ti-x me-1"></i>' . __('Refuse', 'glpimcp') . '</button>';
    echo '<button type="submit" name="decision" value="approve" class="btn btn-danger"><i class="ti ti-check me-1"></i>' . __('I have checked and I approve', 'glpimcp') . '</button>';
    echo '</form>';
}
echo '</div></div></div>';
Html::footer();
