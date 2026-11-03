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

class CreateItems extends Tool
{
    public const RICH_TEXT = ['content', 'answer'];

    public function name(): string
    {
        return 'glpi_create_items';
    }

    public function title(): string
    {
        return __('Create one or more items', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare la création d'un ou plusieurs objets GLPI de n'importe quel type (matériel, utilisateur, groupe, lieu, catégorie, entité, contrat, SLA…). "
            . "Rien n'est créé tout de suite : l'outil vérifie les droits et renvoie un APERÇU + un confirmation_id ; il faut montrer l'aperçu à l'utilisateur puis appeler glpi_confirm_action. "
            . "Chaîner des créations : donne un `ref` à un élément et utilise \"@ref:<ref>\" comme valeur dans un élément suivant "
            . "(ex : créer un groupe ref=support puis une catégorie avec groups_id_tech=\"@ref:support\"). Tout est exécuté en une transaction (tout ou rien).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'summary' => self::str('Résumé en une phrase de ce qui va être créé (affiché à l\'utilisateur)'),
            'items'   => [
                'type'  => 'array',
                'minItems' => 1,
                'maxItems' => 200,
                'items' => self::obj([
                    'itemtype' => self::str('Type GLPI ou alias'),
                    'ref'      => self::str('Nom de référence optionnel pour les éléments suivants'),
                    'fields'   => self::anyObj('Champs de l\'objet (noms techniques, voir glpi_describe_itemtype)'),
                ], ['itemtype', 'fields']),
            ],
        ], ['items']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $ops = [];
        foreach ((array) self::requireArg($args, 'items') as $i => $spec) {
            if (!is_array($spec) || empty($spec['itemtype'])) {
                throw new ToolException('Élément ' . ($i + 1) . ' : itemtype manquant.');
            }
            $fields = (array) ($spec['fields'] ?? []);
            foreach (self::RICH_TEXT as $rt) {
                if (isset($fields[$rt]) && is_string($fields[$rt])) {
                    $fields[$rt] = Items::toHtml($fields[$rt]);
                }
            }
            $ops[] = [
                'op'       => 'add',
                'itemtype' => Items::resolveType((string) $spec['itemtype']),
                'input'    => $fields,
                'ref'      => isset($spec['ref']) ? (string) $spec['ref'] : null,
            ];
        }
        $summary = (string) ($args['summary'] ?? sprintf(__('Creation of %d item(s)', 'glpimcp'), count($ops)));
        return PendingActions::propose($ctx, $this->name(), $summary, $ops);
    }
}
