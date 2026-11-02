<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

class UpdateItem extends Tool
{
    public function name(): string
    {
        return 'glpi_update_items';
    }

    public function title(): string
    {
        return 'Modifier un ou plusieurs objets';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare la modification d'un ou plusieurs objets GLPI existants. Renvoie un aperçu « avant → après » champ par champ "
            . "et un confirmation_id ; rien n'est modifié avant glpi_confirm_action. Les droits sont vérifiés objet par objet.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'summary' => self::str('Résumé en une phrase de la modification'),
            'items'   => [
                'type'  => 'array',
                'minItems' => 1,
                'maxItems' => 200,
                'items' => self::obj([
                    'itemtype' => self::str('Type GLPI ou alias'),
                    'id'       => self::int('Identifiant de l\'objet'),
                    'fields'   => self::anyObj('Champs à modifier uniquement'),
                ], ['itemtype', 'id', 'fields']),
            ],
        ], ['items']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $ops = [];
        foreach ((array) self::requireArg($args, 'items') as $i => $spec) {
            if (!is_array($spec) || empty($spec['itemtype']) || !isset($spec['id']) || !is_numeric($spec['id'])) {
                throw new ToolException('Élément ' . ($i + 1) . ' : itemtype et id obligatoires.');
            }
            $fields = (array) ($spec['fields'] ?? []);
            foreach (CreateItems::RICH_TEXT as $rt) {
                if (isset($fields[$rt]) && is_string($fields[$rt])) {
                    $fields[$rt] = Items::toHtml($fields[$rt]);
                }
            }
            $ops[] = [
                'op'       => 'update',
                'itemtype' => Items::resolveType((string) $spec['itemtype']),
                'id'       => (int) $spec['id'],
                'input'    => $fields,
            ];
        }
        $summary = (string) ($args['summary'] ?? sprintf('Modification de %d objet(s)', count($ops)));
        return PendingActions::propose($ctx, $this->name(), $summary, $ops);
    }
}
