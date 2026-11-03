<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILActor;
use CommonITILObject;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Ticket;

class DescribeItemtype extends Tool
{
    public function name(): string
    {
        return 'glpi_describe_itemtype';
    }

    public function title(): string
    {
        return __('Fields of an item type', 'glpimcp');
    }

    public function description(): string
    {
        return "Décrit les champs d'un type GLPI (nom technique, libellé affiché dans GLPI, type de donnée, objet lié pour les clés *_id, valeurs possibles). "
            . "À utiliser AVANT de créer ou modifier un objet pour remplir les bons champs.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'itemtype' => self::str('Type GLPI (ex : Ticket, Computer, ITILCategory, Entity, SLA…)'),
        ], ['itemtype']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $item = Items::newItem((string) self::requireArg($args, 'itemtype'));
        if (!$item::canView() && !$item::canCreate()) {
            throw new ToolException("Ton profil n'a aucun droit sur : " . $item::getTypeName(1));
        }

        // Libellés des champs via les options de recherche GLPI
        $labels = [];
        try {
            foreach ($item->searchOptions() as $opt) {
                if (!is_array($opt) || empty($opt['field']) || empty($opt['name'])) {
                    continue;
                }
                if (($opt['table'] ?? '') === $item::getTable()) {
                    $labels[$opt['linkfield'] ?? $opt['field']] ??= $opt['name'];
                } elseif (!empty($opt['linkfield'])) {
                    $labels[$opt['linkfield']] ??= $opt['name'];
                } elseif (isset($opt['table']) && ($opt['joinparams'] ?? []) === [] ) {
                    $fk = getForeignKeyFieldForTable($opt['table']);
                    $labels[$fk] ??= $opt['name'];
                }
            }
        } catch (\Throwable) {
        }

        $fields = [];
        foreach (Items::columns($item) as $name => $col) {
            if (in_array($name, Items::SECRET_FIELDS, true) || str_ends_with($name, '_token') || str_contains($name, 'passw')) {
                continue;
            }
            $f = [
                'champ'   => $name,
                'libellé' => $labels[$name] ?? null,
                'type'    => $col['Type'],
            ];
            if ($name === 'id' || in_array($name, ['date_creation', 'date_mod'], true)) {
                $f['lecture_seule'] = true;
            }
            if (str_ends_with($name, '_id') || preg_match('/_id_/', $name)) {
                $target = getItemTypeForTable(getTableNameForForeignKeyField($name));
                if ($target && $target !== 'UNKNOWN') {
                    $f['objet_lié'] = $target;
                }
            }
            $fields[] = $f;
        }

        $out = [
            'itemtype' => $item::class,
            'nom'      => $item::getTypeName(1),
            'droits'   => [
                'lire'      => $item::canView(),
                'créer'     => $item::canCreate(),
                'modifier'  => $item::canUpdate(),
                'supprimer' => $item::canDelete() || $item::canPurge(),
            ],
            'arborescent' => Items::isTree($item::class),
            'rattaché_à_une_entité' => $item->isEntityAssign(),
            'champs'   => $fields,
        ];

        if ($item instanceof CommonITILObject) {
            $out['valeurs_possibles'] = [
                'status'  => $item::getAllStatusArray(),
                'urgency/impact' => [1 => 'Très basse', 2 => 'Basse', 3 => 'Moyenne', 4 => 'Haute', 5 => 'Très haute'],
                'priority' => [1 => 'Très basse', 2 => 'Basse', 3 => 'Moyenne', 4 => 'Haute', 5 => 'Très haute', 6 => 'Majeure'],
            ];
            if ($item instanceof Ticket) {
                $out['valeurs_possibles']['type'] = Ticket::getTypes();
            }
            $out['acteurs'] = "À la création, utilise _users_id_requester / _users_id_assign / _groups_id_assign / _users_id_observer (id ou liste d'id). "
                . "Types d'acteur : " . CommonITILActor::REQUESTER . '=demandeur, ' . CommonITILActor::ASSIGN . '=attribué, ' . CommonITILActor::OBSERVER . '=observateur.';
        }
        if ($item::class === 'Glpi\\Form\\Form') {
            $out['conseil'] = 'Pour créer un formulaire complet (sections + questions), passe par l\'opération form_build de glpi_apply_operations (format : glpi_form_question_types).';
        }
        if (Items::isTree($item::class)) {
            $out['conseil_arborescence'] = 'Le parent se renseigne via le champ ' . getForeignKeyFieldForItemType($item::class) . '.';
        }
        return $out;
    }
}
