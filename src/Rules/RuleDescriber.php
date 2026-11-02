<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Rules;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Items;
use Rule;
use RuleTicket;

/**
 * Lecture lisible des règles GLPI (critères, opérateurs, actions) — utilisée par glpi_rule_list et les aperçus.
 */
final class RuleDescriber
{
    /** Opérateurs de règles GLPI : nom simple → constante */
    public const OPERATORS = [
        'est'              => Rule::PATTERN_IS,
        'n_est_pas'        => Rule::PATTERN_IS_NOT,
        'contient'         => Rule::PATTERN_CONTAIN,
        'ne_contient_pas'  => Rule::PATTERN_NOT_CONTAIN,
        'commence_par'     => Rule::PATTERN_BEGIN,
        'finit_par'        => Rule::PATTERN_END,
        'regex'            => Rule::REGEX_MATCH,
        'pas_regex'        => Rule::REGEX_NOT_MATCH,
        'sous'             => Rule::PATTERN_UNDER,
        'pas_sous'         => Rule::PATTERN_NOT_UNDER,
    ];

    public static function describe(int $rules_id): array
    {
        $rule = new RuleTicket();
        if (!$rule->getFromDB($rules_id)) {
            return [];
        }
        $crit = [];
        foreach ((new \RuleCriteria())->find(['rules_id' => $rules_id]) as $c) {
            $def = $rule->getCriteria($c['criteria']);
            $crit[] = [
                'critère'   => $def['name'] ?? $c['criteria'],
                'clé'       => $c['criteria'],
                'opérateur' => array_search((int) $c['condition'], self::OPERATORS, true) ?: (string) $c['condition'],
                'valeur'    => self::label($c['criteria'], $c['pattern']),
            ];
        }
        $acts = [];
        foreach ((new \RuleAction())->find(['rules_id' => $rules_id]) as $a) {
            $def = $rule->getAction($a['field']);
            $acts[] = [
                'action' => $def['name'] ?? $a['field'],
                'clé'    => $a['field'],
                'valeur' => self::label($a['field'], $a['value']),
            ];
        }
        return [
            'id'        => $rules_id,
            'nom'       => $rule->fields['name'],
            'active'    => (bool) $rule->fields['is_active'],
            'rang'      => (int) $rule->fields['ranking'],
            'logique'   => $rule->fields['match'],
            'déclenchement' => [1 => 'création', 2 => 'modification', 3 => 'création et modification'][(int) $rule->fields['condition']] ?? $rule->fields['condition'],
            'critères'  => $crit,
            'actions'   => $acts,
            'url'       => Items::url($rule),
        ];
    }

    private static function label(string $field, mixed $value): string
    {
        $map = [
            'entities_id' => 'glpi_entities', 'itilcategories_id' => 'glpi_itilcategories',
            '_groups_id_of_requester' => 'glpi_groups', 'groups_id_validate' => 'glpi_groups', 'groups_id_validate_any' => 'glpi_groups',
            '_groups_id_assign' => 'glpi_groups', '_groups_id_observer' => 'glpi_groups', '_locations_id_of_requester' => 'glpi_locations',
        ];
        if (isset($map[$field]) && is_numeric($value)) {
            return Dropdown::getDropdownName($map[$field], (int) $value) . " (#{$value})";
        }
        if (in_array($field, ['users_id_validate', '_users_id_assign', '_users_id_observer', '_users_id_requester'], true) && is_numeric($value)) {
            return getUserName((int) $value) . " (#{$value})";
        }
        return (string) $value;
    }
}
