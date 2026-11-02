<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonDBTM;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

/**
 * Primitive de lecture en masse, pour les programmes qui s'appuient sur le socle (analyses, rapports).
 * Mêmes garanties que glpi_search : droits du profil, entités, droits fins objet par objet, champs secrets exclus.
 */
class ExportItems extends Tool
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

    public function name(): string
    {
        return 'glpi_export';
    }

    public function title(): string
    {
        return 'Lecture en masse (primitive)';
    }

    public function description(): string
    {
        return "Primitive de lecture en masse pour les programmes (rapports, analyses) : renvoie les champs bruts d'un type GLPI, par pages "
            . "(after_id + limit ≤ 1000), avec les mêmes contrôles de droits que glpi_search (profil, entités, droits fins objet par objet, champs secrets exclus). "
            . "group_by=<champ> renvoie plutôt un décompte par valeur. Un assistant qui répond à une question doit préférer glpi_search.";
    }

    public function isAvailable(Context $ctx): bool
    {
        return !$ctx->isHelpdeskInterface();
    }

    public function inputSchema(): array
    {
        return self::obj([
            'itemtype'        => self::str('Type GLPI (y compris types de liaison : NetworkPort_NetworkPort, Item_SoftwareVersion, ImpactRelation…)'),
            'fields'          => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Champs à renvoyer (défaut : tous sauf secrets)'],
            'filters'         => self::anyObj('Filtres champ → valeur, liste (IN) ou {"op": "...", "value": ...}'),
            'after_id'        => self::int('Pagination : renvoyer les id > after_id (défaut : depuis le début, id 0 compris)'),
            'limit'           => self::int('Taille de page (défaut 500, max 1000)'),
            'include_deleted' => self::bool('Inclure la corbeille'),
            'group_by'        => self::str('Décompte par valeur de ce champ (au lieu des lignes)'),
        ], ['itemtype']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;

        $item = Items::newItem((string) self::requireArg($args, 'itemtype'));
        if (!$item::canView()) {
            throw new ToolException("Ton profil ne permet pas de consulter : " . $item::getTypeName(2));
        }
        $table   = $item::getTable();
        $columns = Items::columns($item);

        $where = [];
        if ($item->maybeDeleted() && empty($args['include_deleted'])) {
            $where["$table.is_deleted"] = 0;
        }
        if ($item->maybeTemplate()) {
            $where["$table.is_template"] = 0;
        }
        if ($item->isEntityAssign()) {
            $where[] = getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());
        }
        foreach ((array) ($args['filters'] ?? []) as $field => $value) {
            if (!isset($columns[$field]) || in_array($field, Items::SECRET_FIELDS, true)) {
                throw new ToolException("Champ de filtre inconnu « {$field} » pour " . $item::class . ".");
            }
            if (is_array($value) && isset($value['op'])) {
                $op = strtoupper((string) $value['op']);
                if (!in_array($op, self::OPERATORS, true)) {
                    throw new ToolException("Opérateur non supporté : {$op}");
                }
                $where["$table.$field"] = in_array($op, ['IN', 'NOT IN'], true) ? [$op, (array) $value['value']] : [$op, $value['value'] ?? null];
            } else {
                $where["$table.$field"] = $value;
            }
        }

        // Décompte par valeur (droits : profil + entités ; pas de contrôle objet par objet)
        if (!empty($args['group_by'])) {
            $g = (string) $args['group_by'];
            if (!isset($columns[$g]) || in_array($g, Items::SECRET_FIELDS, true)) {
                throw new ToolException("Champ de regroupement inconnu « {$g} ».");
            }
            if (!$item->isEntityAssign() && !$this->isSafeForAggregate($item)) {
                throw new ToolException("Décompte non disponible pour " . $item::class . " (droits vérifiés objet par objet) : utiliser la lecture par pages.");
            }
            $out = [];
            foreach ($DB->request(['SELECT' => ["$table.$g AS v", 'COUNT' => "$table.id AS n"], 'FROM' => $table, 'WHERE' => $where, 'GROUPBY' => "$table.$g", 'LIMIT' => 20000]) as $r) {
                $out[] = ['valeur' => $r['v'], 'nombre' => (int) $r['n']];
            }
            return ['itemtype' => $item::class, 'regroupement' => $g, 'décomptes' => $out];
        }

        $after = max(-1, (int) ($args['after_id'] ?? -1));
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 500)));
        // Condition séparée : ne remplace pas un éventuel filtre sur l'id
        $where[] = ["$table.id" => ['>', $after]];
        $only = isset($args['fields']) && is_array($args['fields']) ? array_values(array_map('strval', $args['fields'])) : null;

        $rows = [];
        $last = $after;
        $scanned = 0;
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => "$table.id ASC", 'LIMIT' => $limit]) as $r) {
            $scanned++;
            $last = (int) $r['id'];
            /** @var CommonDBTM $o */
            $o = new ($item::class)();
            $o->fields = $r;
            if (!$o->canViewItem()) {
                continue;
            }
            $row = [];
            foreach ($r as $k => $v) {
                if (in_array($k, Items::SECRET_FIELDS, true) || ($only !== null && !in_array($k, $only, true) && $k !== 'id')) {
                    continue;
                }
                $row[$k] = $v;
            }
            $rows[] = $row;
        }

        return [
            'itemtype'   => $item::class,
            'nom_type'   => $item::getTypeName(2),
            'lignes'     => $rows,
            'renvoyées'  => count($rows),
            'page_suivante_after_id' => $scanned === $limit ? $last : null,
        ];
    }

    /** Types de liaison dont la visibilité découle du profil (pas de droits fins par objet) */
    private function isSafeForAggregate(CommonDBTM $item): bool
    {
        return in_array($item::class, ['Item_SoftwareVersion', 'Item_Ticket', 'Item_Problem', 'Contract_Item', 'Appliance_Item'], true);
    }
}
