<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Config;

/**
 * Ce que l'assistant IA a le droit de faire, en trois couches qui ne font que restreindre :
 *  1. outils interdits partout (Administration > Assistant IA) : masqués et refusés pour tout le monde ;
 *  2. types d'action globaux : une action doit être activée ici ;
 *  3. politique du profil GLPI utilisé par le jeton (onglet « Assistant IA » de la fiche profil) :
 *     liste d'outils autorisés et types d'action retirés pour ce profil.
 * Tout cela s'ajoute aux droits GLPI de l'utilisateur, sans jamais les étendre.
 */
final class Permissions
{
    /** clé → [libellé, description, valeur par défaut] */
    public const CATEGORIES = [
        'tickets'      => ['Assistance', "Créer des tickets, ajouter suivis, tâches, solutions, acteurs, répondre aux validations", 1],
        'form_submit'  => ['Remplir des formulaires', "Soumettre les formulaires du catalogue de services au nom de l'utilisateur", 1],
        'items'        => ['Créer et modifier des éléments', "Matériel, lieux, catégories, statuts, contrats, entités, calendriers, SLA…", 1],
        'security'     => ['Incidents de sécurité (SecIncident)', "Déclarer un incident, alimenter sa chronologie et ses IOC, mettre à jour les tâches de remédiation. Jamais de suppression ni de clôture", 0],
        'projects'     => ['Projets', "Créer et mettre à jour des tâches de projet, leur équipe et leurs liens avec les tickets", 1],
        'delete'       => ['Mettre à la corbeille', "Envoyer des éléments à la corbeille (restaurables)", 1],
        'purge'        => ['Supprimer définitivement', "Suppression irréversible (hors annulation d'une action IA)", 0],
        'forms_design' => ['Concevoir des formulaires', "Créer, fusionner, traduire des formulaires et leurs destinations", 1],
        'rules'        => ['Règles', "Règles métier des tickets, dictionnaire et catégories de logiciels", 1],
        'software'     => ['Fusion de logiciels', "Fusionner les logiciels en double du catalogue", 1],
        'users_groups' => ['Utilisateurs et groupes', "Créer ou modifier des comptes, des groupes et leurs habilitations", 0],
        'rights'       => ['Droits des profils', "Modifier les droits d'un profil GLPI", 0],
        'config'       => ['Configuration générale', "Réglages généraux de GLPI (e-mails, sécurité, inventaire…)", 0],
        'rollback'     => ['Annulation depuis le journal', "Annuler une action faite par l'IA (toujours avec aperçu et confirmation)", 1],
    ];

    /** Outils masqués quand leur type d'action est désactivé */
    public const TOOL_CATEGORIES = [
        'glpi_ticket_create' => 'tickets', 'glpi_ticket_add_followup' => 'tickets', 'glpi_ticket_add_task' => 'tickets',
        'glpi_ticket_add_solution' => 'tickets', 'glpi_ticket_add_actor' => 'tickets', 'glpi_validation_answer' => 'tickets',
        'glpi_form_submit' => 'form_submit',
        'glpi_form_create' => 'forms_design', 'glpi_form_consolidate' => 'forms_design', 'glpi_form_destinations' => 'forms_design',
        'glpi_rule_create' => 'rules',
        'glpi_software_normalize' => 'software',
        'glpi_config_set' => 'config',
        'glpi_rollback_action' => 'rollback',
        'glpi_delete_items' => 'delete',
        'glpi_project_task_add' => 'projects', 'glpi_project_task_update' => 'projects',
        'glpi_secincident_declare' => 'security', 'glpi_secincident_add_event' => 'security',
        'glpi_secincident_add_iocs' => 'security', 'glpi_secincident_task_update' => 'security',
    ];

    /** Outils toujours disponibles : savoir qui l'on est, voir et annuler ses propositions */
    public const ALWAYS_ON = ['glpi_whoami', 'glpi_list_pending_actions', 'glpi_cancel_action'];

    /** Profil GLPI de la requête MCP en cours (null hors requête MCP : interface GLPI, console) */
    private static ?int $profile = null;
    private static ?array $cfg = null;
    /** @var array<int, ?array> */
    private static array $policies = [];

    private const USERS_GROUPS = ['User', 'Group', 'Group_User', 'Profile_User', 'UserEmail', 'Entity_User'];
    private const RIGHTS = ['Profile', 'ProfileRight'];
    private const PROJECTS = ['Project', 'ProjectTask', 'ProjectTeam', 'ProjectTaskTeam', 'ProjectTask_Ticket', 'Itil_Project', 'Item_Project'];
    private const TICKETS = ['Ticket', 'Change', 'Problem', 'ITILFollowup', 'TicketTask', 'ChangeTask', 'ProblemTask', 'ITILSolution',
        'Ticket_User', 'Group_Ticket', 'Supplier_Ticket', 'TicketValidation', 'ChangeValidation', 'Change_User', 'Change_Group',
        'Problem_User', 'Group_Problem', 'Item_Ticket', 'Document_Item'];

    /** Appelé pour chaque requête MCP, une fois le profil du jeton appliqué */
    public static function useProfile(?int $profiles_id): void
    {
        self::$profile = $profiles_id !== null && $profiles_id > 0 ? $profiles_id : null;
        self::reset();
    }

    public static function currentProfile(): ?int
    {
        return self::$profile;
    }

    /** Oublie les réglages lus (après une modification) */
    public static function reset(): void
    {
        self::$cfg = null;
        self::$policies = [];
    }

    private static function cfg(): array
    {
        return self::$cfg ??= Config::getConfigurationValues('plugin:glpimcp');
    }

    /** Type d'action activé globalement (sans tenir compte du profil) */
    public static function globallyEnabled(string $category): bool
    {
        $default = self::CATEGORIES[$category][2] ?? 1;
        return (int) (self::cfg()['perm_' . $category] ?? $default) === 1;
    }

    /** Type d'action activé, globalement ET pour le profil de la requête MCP en cours */
    public static function enabled(string $category): bool
    {
        if (!self::globallyEnabled($category)) {
            return false;
        }
        $policy = self::$profile !== null ? self::policy(self::$profile) : null;
        return !in_array($category, $policy['denied_categories'] ?? [], true);
    }

    /** @return string[] outils interdits pour tout le monde */
    public static function bannedTools(): array
    {
        return self::cleanTools(json_decode((string) (self::cfg()['banned_tools'] ?? '[]'), true));
    }

    /**
     * Politique d'un profil : null = aucune restriction propre au profil.
     * @return array{tools_mode: string, tools: string[], denied_categories: string[]}|null
     */
    public static function policy(int $profiles_id): ?array
    {
        if (array_key_exists($profiles_id, self::$policies)) {
            return self::$policies[$profiles_id];
        }
        global $DB;
        $row = null;
        try {
            if ($DB->tableExists(self::POLICY_TABLE)) {
                $it = $DB->request(['FROM' => self::POLICY_TABLE, 'WHERE' => ['profiles_id' => $profiles_id], 'LIMIT' => 1]);
                $row = count($it) ? $it->current() : null;
            }
        } catch (\Throwable) {
            $row = null;
        }
        return self::$policies[$profiles_id] = $row === null ? null : self::normalizePolicy($row);
    }

    public const POLICY_TABLE = 'glpi_plugin_glpimcp_profilepolicies';

    public static function normalizePolicy(array $row): array
    {
        $cats = json_decode((string) ($row['denied_categories'] ?? '[]'), true);
        return [
            'tools_mode'        => ($row['tools_mode'] ?? 'all') === 'list' ? 'list' : 'all',
            'tools'             => self::cleanTools(json_decode((string) ($row['tools'] ?? '[]'), true)),
            'denied_categories' => array_values(array_intersect(array_keys(self::CATEGORIES), is_array($cats) ? $cats : [])),
        ];
    }

    /** @return string[] */
    public static function cleanTools(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_unique(array_filter($list, fn($t) => is_string($t) && preg_match('/^glpi_[a-z0-9_]{1,60}$/', $t))));
    }

    /**
     * Décision pure (testable) : l'outil est-il utilisable ?
     *
     * @param callable(string): bool $category_enabled
     */
    public static function decideTool(string $tool, array $banned, ?array $policy, callable $category_enabled): bool
    {
        if (in_array($tool, self::ALWAYS_ON, true)) {
            return true;
        }
        if (in_array($tool, $banned, true)) {
            return false;
        }
        $cat = self::TOOL_CATEGORIES[$tool] ?? null;
        if ($cat !== null && !$category_enabled($cat)) {
            return false;
        }
        if ($policy !== null && $policy['tools_mode'] === 'list' && !in_array($tool, $policy['tools'], true)) {
            return false;
        }
        return true;
    }

    /** Pourquoi l'outil est indisponible (null s'il l'est) — pour les messages et l'interface */
    public static function toolBlockReason(string $tool): ?string
    {
        if (self::toolAllowed($tool)) {
            return null;
        }
        if (in_array($tool, self::bannedTools(), true)) {
            return 'interdit pour tout le monde';
        }
        $cat = self::TOOL_CATEGORIES[$tool] ?? null;
        if ($cat !== null && !self::globallyEnabled($cat)) {
            return 'type d\'action « ' . self::CATEGORIES[$cat][0] . ' » désactivé';
        }
        return 'non autorisé pour ce profil';
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::CATEGORIES) as $k) {
            $out[$k] = self::enabled($k);
        }
        return $out;
    }

    public static function defaults(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $k => [, , $d]) {
            $out['perm_' . $k] = $d;
        }
        return $out;
    }

    public static function toolAllowed(string $tool): bool
    {
        $policy = self::$profile !== null ? self::policy(self::$profile) : null;
        return self::decideTool($tool, self::bannedTools(), $policy, [self::class, 'enabled']);
    }

    /** Types d'action nécessaires pour une opération */
    public static function categoriesOf(array $op): array
    {
        $kind = $op['op'] ?? '';
        return match ($kind) {
            'config'                => ['config'],
            'profile_rights'        => ['rights'],
            'form_build', 'form_destination_add', 'form_destination_update' => ['forms_design'],
            'form_submit'           => ['form_submit'],
            'software_merge', 'software_unmerge' => ['software'],
            'add', 'update', 'restore' => [self::typeCategory((string) ($op['itemtype'] ?? ''))],
            'delete'                => array_values(array_unique([
                self::isPurge($op) ? 'purge' : 'delete',
                self::typeCategory((string) ($op['itemtype'] ?? '')),
            ])),
            default                 => ['items'],
        };
    }

    /** Suppression effectivement définitive (purge demandée, ou type sans corbeille comme les intitulés) */
    private static function isPurge(array $op): bool
    {
        if (!empty($op['purge']) && !(class_exists((string) ($op['itemtype'] ?? '')) && is_subclass_of((string) $op['itemtype'], \CommonDBRelation::class))) {
            // Une destination de formulaire n'a pas de corbeille : sa suppression relève de la conception de formulaires
            return !str_ends_with((string) ($op['itemtype'] ?? ''), 'FormDestination');
        }
        $class = (string) ($op['itemtype'] ?? '');
        if ($class !== '' && class_exists($class) && is_subclass_of($class, \CommonDBRelation::class)) {
            // Retirer un lien (membre d'équipe, ticket lié…) : réversible depuis le journal, ce n'est pas une purge
            return false;
        }
        if ($class !== '' && class_exists($class) && is_subclass_of($class, \CommonDBTM::class)) {
            return !(new $class())->maybeDeleted();
        }
        return false;
    }

    private static function typeCategory(string $itemtype): string
    {
        $short = ltrim($itemtype, '\\');
        // Alias (« user », « Utilisateur »…) : même résolution que l'exécution
        if ($short !== '' && class_exists(\Toolbox::class, false)) {
            try {
                $short = Items::resolveType($short);
            } catch (ToolException) {
            }
        }
        return match (true) {
            in_array($short, self::USERS_GROUPS, true)   => 'users_groups',
            in_array($short, self::RIGHTS, true)         => 'rights',
            in_array($short, self::TICKETS, true)        => 'tickets',
            str_starts_with($short, 'Rule')              => 'rules',
            in_array($short, self::PROJECTS, true)       => 'projects',
            str_starts_with($short, 'GlpiPlugin\\Secincident\\') => 'security',
            str_starts_with($short, 'Glpi\\Form\\')      => 'forms_design',
            $short === 'Software'                        => 'items',
            default                                      => 'items',
        };
    }

    /**
     * Refuse le lot si un type d'action est désactivé. Une annulation depuis le journal ne dépend que
     * de l'interrupteur « rollback » : elle remet un état antérieur que l'IA avait le droit de modifier.
     */
    public static function assertOps(array $ops, bool $rollback = false): void
    {
        if ($rollback) {
            if (!self::enabled('rollback')) {
                throw new ToolException(self::message('rollback'));
            }
            return;
        }
        \GlpiPlugin\Glpimcp\Integrations\SecIncident::assertOps($ops);
        $blocked = [];
        foreach ($ops as $op) {
            foreach (self::categoriesOf($op) as $cat) {
                if (!self::enabled($cat)) {
                    $blocked[$cat] = true;
                }
            }
        }
        if ($blocked !== []) {
            $labels = array_map(fn($c) => '« ' . self::CATEGORIES[$c][0] . ' »', array_keys($blocked));
            $by_profile = array_values(array_filter(array_keys($blocked), fn($c) => self::globallyEnabled($c)));
            throw new ToolException(
                'Action désactivée par l\'administrateur GLPI pour l\'assistant IA : ' . implode(', ', $labels)
                . ($by_profile !== [] ? ' (retirée pour le profil GLPI utilisé)' : '')
                . '. Un administrateur peut l\'activer dans Administration > Assistant IA (MCP)'
                . ($by_profile !== [] ? ' ou dans l\'onglet « Assistant IA » de la fiche du profil' : '') . '. '
                . 'Rien n\'a été modifié.',
                ['types_désactivés' => array_keys($blocked)]
            );
        }
    }

    private static function message(string $cat): string
    {
        return 'Action désactivée par l\'administrateur GLPI pour l\'assistant IA : « ' . self::CATEGORIES[$cat][0]
            . ' ». Un administrateur peut l\'activer dans Administration > Assistant IA (MCP).';
    }
}
