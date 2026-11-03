<?php

/**
 * GLPI MCP — politique de l'assistant IA pour un profil (onglet « Assistant IA » de la fiche profil)
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Mcp\Permissions;
use GlpiPlugin\Glpimcp\ProfilePolicy;

Session::checkRight('config', UPDATE);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Réglages de sécurité de l'assistant IA : même exigence que la configuration de GLPI 12
    \GlpiPlugin\Glpimcp\Compat::requireReauth();
}

$profiles_id = (int) ($_POST['profiles_id'] ?? 0);
$profile = new Profile();
if ($profiles_id <= 0 || !$profile->getFromDB($profiles_id)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

if (isset($_POST['clear'])) {
    ProfilePolicy::save($profiles_id, null);
} elseif (isset($_POST['save'])) {
    ProfilePolicy::save($profiles_id, Permissions::mergePolicy(
        Permissions::policy($profiles_id),
        Permissions::cleanTools($_POST['tools'] ?? []),
        (array) ($_POST['categories'] ?? []),
        Permissions::bannedTools(),
        ProfilePolicy::allToolNames(),
        [Permissions::class, 'globallyEnabled'],
        ProfilePolicy::availableFor($profiles_id)
    ));
}
Session::addMessageAfterRedirect(__('Configuration updated', 'glpimcp'));
Html::back();
