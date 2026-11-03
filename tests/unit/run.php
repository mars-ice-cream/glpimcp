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

echo "Adresses IP des clients IA\n";
use GlpiPlugin\Glpimcp\OAuth\ClientPolicy as CP;
check('IPv4 dans le /24', CP::inCidr('203.0.113.42', '203.0.113.0/24'), true);
check('IPv4 hors du /24', CP::inCidr('203.0.114.1', '203.0.113.0/24'), false);
check('masque /20', CP::inCidr('10.0.15.255', '10.0.0.0/20') && !CP::inCidr('10.0.16.0', '10.0.0.0/20'), true);
check('IP seule', CP::inCidr('192.0.2.7', '192.0.2.7'), true);
check('IPv6', CP::inCidr('2001:db8::1', '2001:db8::/32'), true);
check('IPv4 contre IPv6', CP::inCidr('192.0.2.7', '2001:db8::/32'), false);
check('liste nettoyée', CP::cidrs("10.0.0.0/8, n'importe quoi; 192.0.2.1/33 ::1"), ['10.0.0.0/8', '::1']);
check('client sans liste : tout passe', CP::ipAllowed(['allowed_ips' => ''], '198.51.100.1'), true);
check('client avec liste : refus', CP::ipAllowed(['allowed_ips' => '203.0.113.0/24'], '198.51.100.1'), false);

echo "Comptage des tickets (quotas)\n";
use GlpiPlugin\Glpimcp\Mcp\RateLimiter;
check('ticket + suivi', RateLimiter::ticketsIn([['op' => 'add', 'itemtype' => 'Ticket'], ['op' => 'add', 'itemtype' => 'ITILFollowup']]), 1);
check('formulaire', RateLimiter::ticketsIn([['op' => 'form_submit']]), 1);
check('modification', RateLimiter::ticketsIn([['op' => 'update', 'itemtype' => 'Ticket']]), 0);

echo "Outils : interdits, par profil\n";
$on = fn($c) => true;
$no_tickets = fn($c) => $c !== 'tickets';
check('sans restriction', Permissions::decideTool('glpi_ticket_create', [], null, $on), true);
check('interdit partout', Permissions::decideTool('glpi_ticket_create', ['glpi_ticket_create'], null, $on), false);
check('toujours disponible malgré interdiction', Permissions::decideTool('glpi_whoami', ['glpi_whoami'], null, $on), true);
check('type d\'action retiré', Permissions::decideTool('glpi_ticket_create', [], null, $no_tickets), false);
$list = Permissions::normalizePolicy(['tools_mode' => 'list', 'tools' => '["glpi_search_items","glpi_ticket_create"]', 'denied_categories' => '["tickets","inconnu"]']);
check('politique nettoyée', $list['denied_categories'], ['tickets']);
check('liste : outil coché', Permissions::decideTool('glpi_search_items', [], $list, $on), true);
check('liste : outil non coché', Permissions::decideTool('glpi_get_item', [], $list, $on), false);
check('liste : toujours disponible', Permissions::decideTool('glpi_cancel_action', [], $list, $on), true);
check('liste n\'outrepasse pas l\'interdiction', Permissions::decideTool('glpi_search_items', ['glpi_search_items'], $list, $on), false);
check('noms d\'outils filtrés', Permissions::cleanTools(['glpi_ok', 'rm -rf', 3, 'glpi_ok']), ['glpi_ok']);

echo "\n$pass réussi(s), $fail échec(s)\n";
exit($fail ? 1 : 0);
