<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Session;

class WhoAmI extends Tool
{
    public function name(): string
    {
        return 'glpi_whoami';
    }

    public function title(): string
    {
        return 'Qui suis-je dans GLPI ?';
    }

    public function description(): string
    {
        return "Renvoie le compte GLPI utilisé, son profil actif, ses autres profils, ses entités actives et un résumé de ses droits. "
            . "À appeler en début de conversation : l'assistant agit EXACTEMENT avec ces droits, rien de plus.";
    }

    public function inputSchema(): array
    {
        return self::obj([]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $profiles = [];
        foreach ($_SESSION['glpiprofiles'] ?? [] as $pid => $p) {
            $profiles[] = ['id' => (int) $pid, 'nom' => $p['name']];
        }

        $entities = [];
        foreach (array_slice($_SESSION['glpiactiveentities'] ?? [], 0, 50) as $eid) {
            $entities[] = ['id' => (int) $eid, 'nom' => Dropdown::getDropdownName('glpi_entities', $eid)];
        }

        $rights = [];
        foreach ([
            'ticket'        => 'Tickets',
            'followup'      => 'Suivis',
            'task'          => 'Tâches',
            'problem'       => 'Problèmes',
            'change'        => 'Changements',
            'knowbase'      => 'Base de connaissances',
            'computer'      => 'Ordinateurs',
            'software'      => 'Logiciels',
            'contract'      => 'Contrats',
            'user'          => 'Utilisateurs',
            'group'         => 'Groupes',
            'entity'        => 'Entités',
            'profile'       => 'Profils',
            'itilcategory'  => 'Catégories ITIL',
            'location'      => 'Lieux',
            'slm'           => 'Niveaux de service',
            'calendar'      => 'Calendriers',
            'rule_ticket'   => 'Règles métier tickets',
            'notification'  => 'Notifications',
            'config'        => 'Configuration générale',
            'form'          => 'Formulaires',
        ] as $right => $label) {
            $value = (int) ($_SESSION['glpiactiveprofile'][$right] ?? 0);
            $rights[$label] = [
                'lire'      => ($value & READ) > 0,
                'créer'     => ($value & CREATE) > 0,
                'modifier'  => ($value & UPDATE) > 0,
                'supprimer' => ($value & (DELETE | PURGE)) > 0,
            ];
        }

        return [
            'utilisateur'     => [
                'id'     => $ctx->users_id,
                'login'  => $_SESSION['glpiname'] ?? '',
                'nom'    => getUserName($ctx->users_id),
            ],
            'profil_actif'    => [
                'id'        => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
                'nom'       => $ctx->profileName(),
                'interface' => Session::getCurrentInterface() === 'helpdesk' ? 'simplifiée (self-service)' : 'standard',
            ],
            'profils_du_compte' => $profiles,
            'périmètre_du_jeton' => [
                'profil'  => \Dropdown::getDropdownName('glpi_profiles', (int) $ctx->token['profiles_id']),
                'entité'  => $ctx->token['entities_id'] !== null ? \Dropdown::getDropdownName('glpi_entities', (int) $ctx->token['entities_id']) : 'toutes celles du profil',
                'sous_entités' => (bool) $ctx->token['is_recursive'],
                'note'    => 'Limite fixée à la création du jeton : seul ce profil est utilisable, et seulement ces entités.',
            ],
            'entité_active'   => [
                'id'        => (int) ($_SESSION['glpiactive_entity'] ?? 0),
                'nom'       => $_SESSION['glpiactive_entity_name'] ?? '',
                'récursive' => (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false),
            ],
            'entités_visibles' => $entities,
            'nb_entités_visibles' => count($_SESSION['glpiactiveentities'] ?? []),
            'droits'          => $rights,
            'jeton'           => ['nom' => $ctx->token['name'], 'expire' => $ctx->token['date_expiration'] ?? 'jamais'],
            'mode_confirmation' => $ctx->config('confirm_mode', 'all') === 'all'
                ? 'toutes les écritures demandent une confirmation (glpi_confirm_action)'
                : 'mode « sensibles » : les écritures ordinaires sont exécutées sans second appel ; les sensibles demandent glpi_confirm_action',
            'approbation_humaine' => \GlpiPlugin\Glpimcp\Mcp\Approval::describeScope($ctx),
            'types_d_actions_désactivés_par_l_admin' => array_values(array_map(
                fn($k) => \GlpiPlugin\Glpimcp\Mcp\Permissions::CATEGORIES[$k][0],
                array_keys(array_filter(\GlpiPlugin\Glpimcp\Mcp\Permissions::all(), fn($on) => !$on))
            )),
        ];
    }
}
