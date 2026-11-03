<?php

/**
 * GLPI MCP (socle) — tests unitaires des fonctions pures (sans GLPI ni base de données).
 *
 *     php tests/unit/run.php
 *
 * Code de sortie : 0 si tout passe, 1 sinon.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'GlpiPlugin\\Glpimcp\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
// Constantes GLPI utilisées par les classes testées
if (!class_exists('Rule')) {
    eval('class Rule { const PATTERN_IS = 0; const PATTERN_IS_NOT = 1; const PATTERN_CONTAIN = 2; const PATTERN_NOT_CONTAIN = 3; const PATTERN_BEGIN = 4; const PATTERN_END = 5; const REGEX_MATCH = 6; const AND_MATCHING = "AND"; const OR_MATCHING = "OR"; }');
}

use GlpiPlugin\Glpimcp\Mcp\Permissions;
use GlpiPlugin\Glpimcp\Tickets\LanguageCheck as TC;

$pass = 0;
$fail = 0;
function check(string $label, mixed $got, mixed $expected): void
{
    global $pass, $fail;
    if ($got === $expected) {
        $pass++;
        return;
    }
    $fail++;
    echo "  ÉCHEC $label\n    attendu : " . var_export($expected, true) . "\n    obtenu  : " . var_export($got, true) . "\n";
}

echo "Langue des textes\n";
if (function_exists('transliterator_transliterate')) {
    check('français', TC::guessLanguage("Bonjour, nous avons bien ajouté vos droits sur le dossier, merci de vérifier et de nous dire si tout est bon."), 'fr_FR');
    check('anglais', TC::guessLanguage("Hello, we have added your access to the folder, please check and let us know if it is working for you."), 'en_GB');
    check('italien', TC::guessLanguage("Buongiorno, abbiamo aggiunto il vostro accesso alla cartella, grazie per la pazienza e cordiali saluti."), 'it_IT');
    check('trop court', TC::guessLanguage('OK merci'), null);
} else {
    echo "  (extension intl absente : tests de langue ignorés)\n";
}

echo "Interrupteurs par type d'action\n";
check('création User', Permissions::categoriesOf(['op' => 'add', 'itemtype' => 'User']), ['users_groups']);
check('création Computer', Permissions::categoriesOf(['op' => 'add', 'itemtype' => 'Computer']), ['items']);
check('suivi', Permissions::categoriesOf(['op' => 'add', 'itemtype' => 'ITILFollowup']), ['tickets']);
check('purge', Permissions::categoriesOf(['op' => 'delete', 'itemtype' => 'Computer', 'purge' => true]), ['purge', 'items']);
check('corbeille groupe', Permissions::categoriesOf(['op' => 'delete', 'itemtype' => 'Group']), ['delete', 'users_groups']);
check('règle', Permissions::categoriesOf(['op' => 'add', 'itemtype' => 'RuleCriteria']), ['rules']);
check('formulaire', Permissions::categoriesOf(['op' => 'form_build']), ['forms_design']);
check('config', Permissions::categoriesOf(['op' => 'config']), ['config']);
check('fusion logiciels', Permissions::categoriesOf(['op' => 'software_merge']), ['software']);
check('défauts risqués désactivés', array_keys(array_filter(Permissions::defaults(), fn($v) => $v === 0)), ['perm_security', 'perm_purge', 'perm_users_groups', 'perm_rights', 'perm_config']);

echo "Approbation humaine (politique)\n";
use GlpiPlugin\Glpimcp\Mcp\Approval;
use GlpiPlugin\Glpimcp\Mcp\Operations;
check('création ordinaire', Approval::reasons([['op' => 'add', 'itemtype' => 'Computer']], [], false, 'critical'), []);
check('purge', count(Approval::reasons([['op' => 'delete', 'itemtype' => 'Computer', 'purge' => true]], [], true, 'critical')), 1);
check('droits de profil', Approval::reasons([['op' => 'profile_rights']], [], true, 'critical'), ['Droits des profils']);
check('habilitation', count(Approval::reasons([['op' => 'add', 'itemtype' => 'Profile_User']], [], true, 'critical')), 1);
check('configuration', count(Approval::reasons([['op' => 'config']], [], true, 'critical')), 1);
check('annulation simple d\'une création', Approval::reasons([['op' => 'delete', 'itemtype' => 'Computer', 'purge' => true]], ['rollback_of' => 3], true, 'critical'), []);
check('annulation forcée', count(Approval::reasons([['op' => 'delete', 'itemtype' => 'Computer', 'purge' => true]], ['rollback_of' => 3, 'force' => true], true, 'critical')), 2);
check('corbeille : portée critique', Approval::reasons([['op' => 'delete', 'itemtype' => 'Computer']], [], true, 'critical'), []);
check('corbeille : portée sensible', count(Approval::reasons([['op' => 'delete', 'itemtype' => 'Computer']], [], true, 'sensitive')), 1);
$row = ['uuid' => 'conf_x', 'users_id' => 2, 'tokens_id' => 5, 'profiles_id' => 4, 'context_hash' => 'c', 'payload_hash' => 'p', 'preview_hash' => 'v'];
check('empreinte stable', Approval::hash($row), Approval::hash($row));
check('empreinte liée au contexte', Approval::hash($row) === Approval::hash(['context_hash' => 'autre'] + $row), false);
check('empreinte liée à l\'aperçu', Approval::hash($row) === Approval::hash(['preview_hash' => 'autre'] + $row), false);

echo "Annulabilité\n";
check('totale', Operations::undoability(['undo' => [['op' => 'delete']], 'irréversible' => []])['niveau'], 'totale');
check('partielle', Operations::undoability(['undo' => [['op' => 'delete']], 'irréversible' => ['Notifications e-mail éventuellement générées pour X']])['niveau'], 'partielle');
check('aucune', Operations::undoability(['undo' => [], 'irréversible' => ['Suppression définitive de X']])['niveau'], 'aucune');
check('effets externes', count(Operations::undoability(['undo' => [1], 'irréversible' => ['Notifications e-mail éventuellement générées pour X', 'Champ spécial']])['effets_externes']), 1);
check('purge annoncée irréversible', str_starts_with(Operations::undoabilityOf(['op' => 'delete', 'itemtype' => 'Computer', 'purge' => true]), 'NON'), true);

echo "\n$pass réussi(s), $fail échec(s)\n";
exit($fail ? 1 : 0);
