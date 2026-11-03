<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp;

use CommonDBTM;
use CommonGLPI;
use GlpiPlugin\Glpimcp\Mcp\AuditLog;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Permissions;
use GlpiPlugin\Glpimcp\Mcp\Server;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Html;
use Profile;
use Session;

/**
 * Ce que l'assistant IA peut faire avec un profil GLPI donné (onglet « Assistant IA » de la fiche profil).
 *
 * Une politique ne fait que restreindre : elle retire des outils ou des types d'action pour les jetons
 * qui utilisent ce profil. Les droits GLPI du profil et les réglages globaux s'appliquent toujours.
 */
class ProfilePolicy extends CommonDBTM
{
    // Pas de redéclaration de $rightname : son type diffère entre GLPI 11 (non typé) et 12 (string)
    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function getTable($classname = null)
    {
        return Permissions::POLICY_TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return __('AI assistant (MCP)', 'glpimcp');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && Session::haveRight('config', UPDATE)) {
            return self::createTabEntry(__('AI assistant (MCP)', 'glpimcp'), 0, null, 'ti ti-robot');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile && Session::haveRight('config', UPDATE)) {
            self::showForProfile((int) $item->getID());
        }
        return true;
    }

    /**
     * Outils groupés pour l'affichage : libellé du groupe → [nom → [titre, écriture ?]]
     * @return array<string, array<string, array{0: string, 1: bool}>>
     */
    public static function toolGroups(): array
    {
        $groups = [];
        foreach (Server::toolClasses() as $class) {
            if (!class_exists($class)) {
                continue;
            }
            /** @var Tool $t */
            $t = new $class();
            $cat = Permissions::TOOL_CATEGORIES[$t->name()] ?? null;
            $group = match (true) {
                in_array($t->name(), Permissions::ALWAYS_ON, true) => __('Always available', 'glpimcp'),
                $cat !== null => Permissions::label($cat),
                $t->isWrite() => __('Generic changes (subject to the action types)', 'glpimcp'),
                default => __('Reading and discovery', 'glpimcp'),
            };
            $groups[$group][$t->name()] = [$t->title(), $t->isWrite()];
        }
        return $groups;
    }

    /**
     * Outils auxquels les droits GLPI du profil donnent accès (même règle que la liste envoyée à l'IA),
     * évalués en prenant ce profil le temps du calcul, puis en rétablissant la session.
     * @return string[]
     */
    public static function availableFor(int $profiles_id): array
    {
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return [];
        }
        $profile->cleanProfile();
        $saved = $_SESSION['glpiactiveprofile'] ?? null;
        $names = [];
        try {
            // Les seuls droits de ce profil (pas de fusion avec le profil de l'administrateur : un droit absent
            // du profil évalué doit rester absent), avec les entités de la session courante
            $_SESSION['glpiactiveprofile'] = $profile->fields + ['entities' => $saved['entities'] ?? []];
            $ctx = new Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']);
            foreach (Server::toolClasses() as $class) {
                if (!class_exists($class)) {
                    continue;
                }
                /** @var Tool $t */
                $t = new $class();
                try {
                    if (in_array($t->name(), Permissions::ALWAYS_ON, true) || $t->isAvailable($ctx)) {
                        $names[] = $t->name();
                    }
                } catch (\Throwable) {
                }
            }
        } finally {
            if ($saved === null) {
                unset($_SESSION['glpiactiveprofile']);
            } else {
                $_SESSION['glpiactiveprofile'] = $saved;
            }
        }
        return $names;
    }

    /** @return string[] */
    public static function allToolNames(): array
    {
        $names = [];
        foreach (self::toolGroups() as $tools) {
            $names = array_merge($names, array_keys($tools));
        }
        return $names;
    }

    /** Enregistre la politique d'un profil (null = supprimer : aucune restriction propre) */
    public static function save(int $profiles_id, ?array $policy): void
    {
        global $DB;
        $before = Permissions::policy($profiles_id);
        if ($policy === null) {
            $DB->delete(Permissions::POLICY_TABLE, ['profiles_id' => $profiles_id]);
        } else {
            $known = self::allToolNames();
            $values = [
                'tools_mode'        => $policy['tools_mode'] === 'list' ? 'list' : 'all',
                'tools'             => json_encode(array_values(array_intersect($known, Permissions::cleanTools($policy['tools'] ?? [])))),
                'denied_categories' => json_encode(array_values(array_intersect(array_keys(Permissions::CATEGORIES), (array) ($policy['denied_categories'] ?? [])))),
                'date_mod'          => date('Y-m-d H:i:s'),
            ];
            $DB->updateOrInsert(Permissions::POLICY_TABLE, $values, ['profiles_id' => $profiles_id]);
        }
        Permissions::reset();
        $after = Permissions::policy($profiles_id);
        if ($before !== $after) {
            AuditLog::write(
                new Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']),
                'plugin_settings', 'exécuté',
                'Assistant IA : politique du profil « ' . \Dropdown::getDropdownName('glpi_profiles', $profiles_id) . ' » modifiée',
                ['avant' => $before, 'après' => $after]
            );
        }
    }

    public static function showForProfile(int $profiles_id): void
    {
        global $CFG_GLPI;
        $policy = Permissions::policy($profiles_id);
        $mode = $policy['tools_mode'] ?? 'all';
        $allowed = $policy['tools'] ?? [];
        $denied = $policy['denied_categories'] ?? [];
        $banned = Permissions::bannedTools();
        $action = $CFG_GLPI['root_doc'] . '/plugins/glpimcp/front/profilepolicy.form.php';

        echo '<div class="card"><div class="card-body">';
        echo '<h3 class="card-title"><i class="ti ti-robot me-2"></i>' . __('What the AI assistant can do with this profile', 'glpimcp') . '</h3>';
        echo '<p class="text-muted">' . __('Applies to every AI access (token or OAuth) that uses this profile. It can only restrict: the GLPI rights of the profile and the global settings (Administration > AI assistant (MCP)) always apply.', 'glpimcp') . '</p>';
        echo '<form method="post" action="' . htmlescape($action) . '">';
        echo \GlpiPlugin\Glpimcp\Compat::csrfField();
        echo Html::hidden('profiles_id', ['value' => $profiles_id]);

        echo '<h4>' . __('Tools', 'glpimcp') . '</h4>';
        echo '<p class="text-muted">' . __('Uncheck the tools this profile must not use. A tool added by a future version is allowed only if every tool is checked here.', 'glpimcp') . '</p>';
        // Seuls les outils auxquels les droits GLPI du profil donnent accès sont proposés
        $available = self::availableFor($profiles_id);
        $hidden = [];
        echo '<div class="row g-3 mt-1">';
        foreach (self::toolGroups() as $group => $tools) {
            foreach (array_keys($tools) as $name) {
                if (!in_array($name, $available, true)) {
                    $hidden[] = $tools[$name][0];
                    unset($tools[$name]);
                }
            }
            if ($tools === []) {
                continue;
            }
            echo '<div class="col-md-4"><div class="border rounded p-2 h-100"><strong>' . htmlescape($group) . '</strong>';
            foreach ($tools as $name => [$title, $write]) {
                $always = in_array($name, Permissions::ALWAYS_ON, true);
                $is_banned = in_array($name, $banned, true);
                $checked = $always || $mode === 'all' || in_array($name, $allowed, true);
                echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="tools[]" value="' . htmlescape($name) . '" id="glpimcp_t_' . htmlescape($name) . '"'
                    . ($checked && !$is_banned ? ' checked' : '') . ($always || $is_banned ? ' disabled' : '') . '>'
                    . '<label class="form-check-label" for="glpimcp_t_' . htmlescape($name) . '">' . htmlescape($title)
                    . ($write ? ' <span class="badge bg-blue-lt">' . __('change', 'glpimcp') . '</span>' : '')
                    . ($is_banned ? ' <span class="badge bg-red-lt">' . __('forbidden for everyone', 'glpimcp') . '</span>' : '')
                    . '<br><small class="text-muted"><code>' . htmlescape($name) . '</code></small></label></div>';
            }
            echo '</div></div>';
        }
        echo '</div>';
        if ($hidden !== []) {
            echo '<details class="mt-2"><summary class="text-muted">' . sprintf(
                __('%d tools not shown: the GLPI rights of this profile do not give access to them', 'glpimcp'),
                count($hidden)
            ) . '</summary><small class="text-muted">' . htmlescape(implode(', ', $hidden)) . '</small></details>';
        }

        echo '<h4 class="mt-4">' . __('Action types', 'glpimcp') . '</h4>';
        echo '<p class="text-muted">' . __('Uncheck an action type to withdraw it from this profile, including through generic tools (create, update, apply operations).', 'glpimcp') . '</p>';
        echo '<div class="row g-2">';
        foreach (Permissions::CATEGORIES as $k => [$label, $desc]) {
            $label = Permissions::label($k);
            $desc = Permissions::description($k);
            $global = Permissions::globallyEnabled($k);
            echo '<div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="categories[]" value="' . $k . '" id="glpimcp_c_' . $k . '"'
                . ($global && !in_array($k, $denied, true) ? ' checked' : '') . ($global ? '' : ' disabled') . '>'
                . '<label class="form-check-label" for="glpimcp_c_' . $k . '"><strong>' . htmlescape($label) . '</strong>'
                . ($global ? '' : ' <span class="badge bg-secondary-lt">' . __('disabled for everyone', 'glpimcp') . '</span>')
                . '<br><small class="text-muted">' . htmlescape($desc) . '</small></label></div></div>';
        }
        echo '</div>';

        echo '<div class="mt-3"><button type="submit" name="save" class="btn btn-primary">' . _sx('button', 'Save') . '</button>';
        if ($policy !== null) {
            echo ' <button type="submit" name="clear" class="btn btn-outline-secondary">' . __('Remove the restrictions of this profile', 'glpimcp') . '</button>';
        }
        echo '</div></form></div></div>';
    }

    /** Résumé lisible d'une politique, pour la page d'administration */
    public static function summary(?array $policy): string
    {
        if ($policy === null) {
            return __('No restriction', 'glpimcp');
        }
        $parts = [];
        $parts[] = $policy['tools_mode'] === 'list'
            ? sprintf(__('%d tools allowed', 'glpimcp'), count($policy['tools']))
            : __('all tools', 'glpimcp');
        if ($policy['denied_categories'] !== []) {
            $parts[] = __('withdrawn:', 'glpimcp') . ' ' . implode(', ', array_map(fn($c) => Permissions::label($c), $policy['denied_categories']));
        }
        return implode(' — ', $parts);
    }
}
