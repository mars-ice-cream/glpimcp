<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Rules\RuleDescriber;
use RuleTicket;

class RuleList extends Tool
{
    public function name(): string
    {
        return 'glpi_rule_list';
    }

    public function title(): string
    {
        return 'Règles métier existantes';
    }

    public function isAvailable(Context $ctx): bool
    {
        return RuleTicket::canView();
    }

    public function description(): string
    {
        return "Liste les règles métier des tickets en clair (critères, actions, ordre d'exécution, active ou non). "
            . "Filtres : texte, ou seulement celles qui demandent une validation (validation_only).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'query'           => self::str('Texte dans le nom'),
            'validation_only' => self::bool('Seulement les règles qui envoient une demande de validation'),
            'include_inactive' => self::bool('Inclure les règles inactives'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $where = ['sub_type' => RuleTicket::class];
        if (empty($args['include_inactive'])) {
            $where['is_active'] = 1;
        }
        if (!empty($args['query'])) {
            $where['name'] = ['LIKE', '%' . $args['query'] . '%'];
        }
        $out = [];
        foreach ((new RuleTicket())->find($where, ['ranking ASC'], 300) as $row) {
            $d = RuleDescriber::describe((int) $row['id']);
            if (!empty($args['validation_only']) && !array_filter($d['actions'], fn($a) => str_contains($a['clé'], 'validate'))) {
                continue;
            }
            $out[] = $d;
        }
        return ['nb' => count($out), 'règles' => $out];
    }
}
