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
    $checked = Permissions::cleanTools($_POST['tools'] ?? []);
    $choosable = array_values(array_diff(ProfilePolicy::allToolNames(), Permissions::ALWAYS_ON, Permissions::bannedTools()));
    // Tout coché = aucune restriction d'outil (les outils des versions futures restent alors disponibles)
    $mode = array_diff($choosable, $checked) === [] ? 'all' : 'list';
    $kept = array_values(array_intersect(array_keys(Permissions::CATEGORIES), (array) ($_POST['categories'] ?? [])));
    // Les types désactivés pour tout le monde ne sont pas « retirés au profil » : ils restent gérés globalement
    $denied = array_values(array_filter(
        array_keys(Permissions::CATEGORIES),
        fn($c) => Permissions::globallyEnabled($c) && !in_array($c, $kept, true)
    ));
    ProfilePolicy::save($profiles_id, $mode === 'all' && $denied === [] ? null : [
        'tools_mode'        => $mode,
        'tools'             => $mode === 'list' ? $checked : [],
        'denied_categories' => $denied,
    ]);
}
Session::addMessageAfterRedirect(__('Configuration updated', 'glpimcp'));
Html::back();
