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
    /** clé → [libellé, description, valeur par défaut] (textes sources en anglais, traduits par label() et description()) */
    public const CATEGORIES = [
        'tickets'      => ['Assistance', 'Create tickets, add follow-ups, tasks, solutions, actors, answer approval requests', 1],
        'form_submit'  => ['Fill in forms', "Submit the forms of the service catalog on the user's behalf", 1],
        'items'        => ['Create and update items', 'Hardware, locations, categories, statuses, contracts, entities, calendars, SLA…', 1],
        'security'     => ['Security incidents (SecIncident)', 'Declare an incident, add to its timeline and IOCs, update remediation tasks. Never deletion or closing', 0],
        'vulnwatch'    => ['Vulnerability watch (VulnWatch)', 'Create or complete campaign tickets from alerts, set the criticality and exposure of an asset', 1],
        'projects'     => ['Projects', 'Create and update project tasks, their team and their links to tickets', 1],
        'delete'       => ['Move to trash', 'Move items to the trash (can be restored)', 1],
        'purge'        => ['Delete permanently', 'Irreversible deletion (except undoing an AI action)', 0],
        'forms_design' => ['Design forms', 'Create, merge, translate forms and their destinations', 1],
        'rules'        => ['Rules', 'Ticket business rules, dictionary and software categories', 1],
        'software'     => ['Software merge', 'Merge duplicate software in the catalog', 1],
        'users_groups' => ['Users and groups', 'Create or update accounts, groups and their authorizations', 0],
        'rights'       => ['Profile rights', 'Change the rights of a GLPI profile', 0],
        'config'       => ['General setup', 'General GLPI settings (e-mails, security, inventory…)', 0],
        'rollback'     => ['Undo from the journal', 'Undo an action made by the AI (always with preview and confirmation)', 1],
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
        'glpi_vulnwatch_campaign' => 'vulnwatch', 'glpi_vulnwatch_asset_context' => 'vulnwatch',
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

    /** Libellé d'un type d'action, dans la langue de l'utilisateur */
    public static function label(string $category): string
    {
        return isset(self::CATEGORIES[$category]) ? __(self::CATEGORIES[$category][0], 'glpimcp') : $category;
    }

    public static function description(string $category): string
    {
        return isset(self::CATEGORIES[$category]) ? __(self::CATEGORIES[$category][1], 'glpimcp') : '';
    }

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

    /**
     * Nouvelle politique d'un profil à partir du formulaire (fonction pure, testable).
     *
     * Les cases des outils interdits partout et des types désactivés partout sont grisées, donc jamais
     * envoyées : leur état propre au profil est conservé tel quel, pour qu'une restriction du profil ne
     * disparaisse pas en silence quand l'interdiction ou la désactivation globale est levée plus tard.
     *
     * Il en va de même des outils non affichés parce que les droits GLPI du profil n'y donnent pas accès.
     *
     * @param string[] $checked  outils cochés
     * @param string[] $kept     types d'action cochés
     * @param string[] $banned   outils interdits partout
     * @param string[] $all      tous les outils connus
     * @param callable(string): bool $globally_enabled
     * @param string[]|null $shown outils affichés dans le formulaire (null = tous)
     * @return array|null null = aucune restriction propre au profil
     */
    public static function mergePolicy(?array $old, array $checked, array $kept, array $banned, array $all, callable $globally_enabled, ?array $shown = null): ?array
    {
        $prev_allowed = fn(string $t) => $old === null || $old['tools_mode'] === 'all' || in_array($t, $old['tools'], true);
        $editable = array_diff($shown ?? $all, $banned);
        $allowed = array_values(array_unique(array_merge(
            array_intersect($checked, $editable, $all),
            array_filter(array_diff($all, $editable), fn($t) => $prev_allowed($t))
        )));
        $choosable = array_diff($all, self::ALWAYS_ON);
        // Tout autorisé = aucune restriction d'outil (les outils des versions futures restent alors disponibles)
        $mode = array_diff($choosable, $allowed) === [] ? 'all' : 'list';

        $denied = [];
        foreach (array_keys(self::CATEGORIES) as $c) {
            $is_denied = $globally_enabled($c)
                ? !in_array($c, $kept, true)
                : in_array($c, $old['denied_categories'] ?? [], true);
            if ($is_denied) {
                $denied[] = $c;
            }
        }
        if ($mode === 'all' && $denied === []) {
            return null;
        }
        return ['tools_mode' => $mode, 'tools' => $mode === 'list' ? array_values(array_diff($allowed, self::ALWAYS_ON)) : [], 'denied_categories' => $denied];
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
            return 'type d\'action « ' . self::label($cat) . ' » désactivé';
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
            'vulnwatch_campaign'    => ['vulnwatch', 'tickets'],
            'vulnwatch_context', 'vulnwatch_campaign_restore' => ['vulnwatch'],
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
            stripos($short, 'GlpiPlugin\\Secincident\\') === 0 => 'security',
            str_starts_with($short, 'Glpi\\Form\\')      => 'forms_design',
            $short === 'Software'                        => 'items',
            default                                      => 'items',
        };
    }

    /** Outils génériques : leurs opérations sont aussi soumises aux outils métier retirés (voir equivalentTools) */
    public const GENERIC_WRITE_TOOLS = ['glpi_create_items', 'glpi_update_items', 'glpi_apply_operations'];

    /**
     * Outils métier qui réalisent cette opération. Retirer tous ces outils (au profil, ou partout) interdit
     * aussi l'opération par les outils génériques : décocher « Ouvrir un ticket » empêche de créer un ticket
     * avec glpi_create_items. Vide = opération sans outil métier dédié (seul le type d'action la gouverne).
     *
     * @return string[]
     */
    public static function equivalentTools(array $op): array
    {
        $kind = (string) ($op['op'] ?? '');
        $type = ltrim((string) ($op['itemtype'] ?? ''), '\\');
        if (class_exists(\Toolbox::class, false) && $type !== '') {
            try {
                $type = Items::resolveType($type);
            } catch (ToolException) {
            }
        }
        return match (true) {
            $kind === 'add' && $type === 'Ticket'                                     => ['glpi_ticket_create'],
            $kind === 'add' && $type === 'ITILFollowup'                               => ['glpi_ticket_add_followup'],
            $kind === 'add' && in_array($type, ['TicketTask', 'ChangeTask', 'ProblemTask'], true) => ['glpi_ticket_add_task'],
            $kind === 'add' && $type === 'ITILSolution'                               => ['glpi_ticket_add_solution'],
            $kind === 'add' && in_array($type, ['Ticket_User', 'Group_Ticket', 'Supplier_Ticket', 'Change_User', 'Change_Group', 'Problem_User', 'Group_Problem'], true)
                                                                                      => ['glpi_ticket_assign'],
            $kind === 'update' && in_array($type, ['TicketValidation', 'ChangeValidation'], true) => ['glpi_validation_answer'],
            in_array($kind, ['add', 'update'], true) && in_array($type, ['ProjectTask', 'ProjectTaskTeam', 'ProjectTask_Ticket'], true)
                                                                                      => ['glpi_project_task_add', 'glpi_project_task_update'],
            $kind === 'form_submit'                                                   => ['glpi_form_submit'],
            $kind === 'vulnwatch_campaign'                                            => ['glpi_vulnwatch_campaign'],
            $kind === 'vulnwatch_context'                                             => ['glpi_vulnwatch_asset_context'],
            $kind === 'config'                                                        => ['glpi_config_set'],
            $kind === 'profile_rights'                                                => ['glpi_profile_rights'],
            $kind === 'delete'                                                        => ['glpi_delete_items'],
            default                                                                   => [],
        };
    }

    /**
     * Refuse le lot si un type d'action est désactivé. Une annulation depuis le journal ne dépend que
     * de l'interrupteur « rollback » : elle remet un état antérieur que l'IA avait le droit de modifier.
     */
    public static function assertOps(array $ops, bool $rollback = false, string $tool = ''): void
    {
        if ($rollback) {
            if (!self::enabled('rollback')) {
                throw new ToolException(self::message('rollback'));
            }
            return;
        }
        \GlpiPlugin\Glpimcp\Integrations\SecIncident::assertOps($ops);
        \GlpiPlugin\Glpimcp\Integrations\VulnWatch::assertOps($ops);
        $blocked = [];
        foreach ($ops as $op) {
            foreach (self::categoriesOf($op) as $cat) {
                if (!self::enabled($cat)) {
                    $blocked[$cat] = true;
                }
            }
        }
        if ($blocked === [] && in_array($tool, self::GENERIC_WRITE_TOOLS, true)) {
            // Un outil générique ne doit pas rouvrir ce qu'un outil métier retiré interdit
            foreach ($ops as $op) {
                $equiv = self::equivalentTools($op);
                if ($equiv !== [] && array_filter($equiv, [self::class, 'toolAllowed']) === []) {
                    throw new ToolException(
                        'Opération refusée : l\'administrateur GLPI a retiré l\'outil « ' . implode(' » / « ', $equiv)
                        . ' » pour ce profil ou pour tout le monde, et la même opération est interdite par les outils génériques. '
                        . 'Rien n\'a été modifié.',
                        ['outils_retirés' => $equiv]
                    );
                }
            }
        }
        if ($blocked !== []) {
            $labels = array_map(fn($c) => '« ' . self::label($c) . ' »', array_keys($blocked));
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
        return 'Action désactivée par l\'administrateur GLPI pour l\'assistant IA : « ' . self::label($cat)
            . ' ». Un administrateur peut l\'activer dans Administration > Assistant IA (MCP).';
    }
}
