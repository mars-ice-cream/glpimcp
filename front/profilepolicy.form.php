<?php

/**
 * GLPI MCP — politique de l'assistant IA pour un profil (onglet « Assistant IA » de la fiche profil)
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Glpimcp\Mcp\Permissions;
use GlpiPlugin\Glpimcp\ProfilePolicy;

Session::checkRight('config', UPDATE);

$profiles_id = (int) ($_POST['profiles_id'] ?? 0);
$profile = new Profile();
if ($profiles_id <= 0 || !$profile->getFromDB($profiles_id)) {
    Html::displayErrorAndDie(__('Item not found'));
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
