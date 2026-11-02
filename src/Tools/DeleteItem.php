<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class DeleteItem extends Tool
{
    public function name(): string
    {
        return 'glpi_delete_items';
    }

    public function title(): string
    {
        return 'Supprimer des objets';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare la mise à la corbeille (par défaut) ou la suppression définitive (purge=true) d'objets GLPI. "
            . "Toujours soumis à confirmation explicite de l'utilisateur via glpi_confirm_action. Privilégier la corbeille.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'summary' => self::str('Résumé de la suppression'),
            'items'   => [
                'type'  => 'array',
                'minItems' => 1,
                'maxItems' => 100,
                'items' => self::obj([
                    'itemtype' => self::str('Type GLPI ou alias'),
                    'id'       => self::int('Identifiant'),
                    'purge'    => self::bool('Suppression définitive (défaut : corbeille)'),
                ], ['itemtype', 'id']),
            ],
        ], ['items']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $ops = [];
        foreach ((array) self::requireArg($args, 'items') as $spec) {
            $ops[] = [
                'op'       => 'delete',
                'itemtype' => Items::resolveType((string) ($spec['itemtype'] ?? '')),
                'id'       => (int) ($spec['id'] ?? 0),
                'purge'    => !empty($spec['purge']),
            ];
        }
        return PendingActions::propose($ctx, $this->name(), (string) ($args['summary'] ?? sprintf('Suppression de %d objet(s)', count($ops))), $ops);
    }
}
