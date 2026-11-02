<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILActor;
use CommonITILObject;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

class SearchItems extends Tool
{
    private const TEXT_COLUMNS = ['name', 'completename', 'serial', 'otherserial', 'realname', 'firstname', 'contact', 'title', 'content', 'comment', 'answer'];

    private const SUMMARY_COLUMNS = [
        'name', 'completename', 'realname', 'firstname', 'status', 'type', 'priority', 'urgency', 'date', 'date_mod',
        'time_to_resolve', 'entities_id', 'itilcategories_id', 'locations_id', 'states_id', 'manufacturers_id',
        'serial', 'otherserial', 'users_id', 'groups_id', 'users_id_tech', 'is_active', 'is_recursive', 'level',
        'begin_date', 'end_date', 'email', 'phone',
    ];

    public function name(): string
    {
        return 'glpi_search';
    }

    public function title(): string
    {
        return 'Rechercher des objets GLPI';
    }

    public function description(): string
    {
        return "Recherche n'importe quel type d'objet GLPI visible par l'utilisateur (tickets, ordinateurs, utilisateurs, catégories, entités…). "
            . "Seuls les objets que son profil et ses entités autorisent sont renvoyés. "
            . "filters : égalité stricte sur des champs (ex {\"status\": 2, \"itilcategories_id\": 5}), liste = IN, objet {\"op\":\">=\",\"value\":…} pour comparer. "
            . "Raccourcis tickets/problèmes/changements : _open (non résolus/clos), _requester (id utilisateur), _assigned_user, _assigned_group, _mine (demandés par ou attribués à moi).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'itemtype'        => self::str('Type GLPI ou alias (ticket, ordinateur, utilisateur, categorie, lieu, entite…)'),
            'query'           => self::str('Texte recherché (nom, numéro de série, contenu…)'),
            'filters'         => self::anyObj('Filtres champ → valeur'),
            'include_deleted' => self::bool('Inclure les éléments à la corbeille'),
            'order'           => self::str('Champ de tri (défaut : date_mod ou id)'),
            'order_dir'       => self::str('ASC ou DESC (défaut DESC)', ['ASC', 'DESC']),
            'limit'           => self::int('Nombre max de résultats (défaut 20)'),
            'fields'          => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Champs à renvoyer (défaut : résumé)'],
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
        $limit   = max(1, min($ctx->maxResults(), (int) ($args['limit'] ?? 20)));

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

        // Texte libre
        $q = trim((string) ($args['query'] ?? ''));
        if ($q !== '') {
            $or = [];
            foreach (self::TEXT_COLUMNS as $c) {
                if (isset($columns[$c])) {
                    $or[] = ["$table.$c" => ['LIKE', '%' . $q . '%']];
                }
            }
            if (ctype_digit($q)) {
                $or[] = ["$table.id" => (int) $q];
            }
            if ($or) {
                $where[] = ['OR' => $or];
            }
        }

        // Filtres
        foreach ((array) ($args['filters'] ?? []) as $field => $value) {
            if ($item instanceof CommonITILObject && str_starts_with((string) $field, '_')) {
                $where[] = $this->itilShortcut($item, (string) $field, $value, $ctx);
                continue;
            }
            if (!isset($columns[$field]) || in_array($field, Items::SECRET_FIELDS, true)) {
                throw new ToolException("Champ de filtre inconnu « {$field} » pour " . $item::getTypeName(1) . ". Utilise glpi_describe_itemtype.");
            }
            if (is_array($value) && isset($value['op'])) {
                $op = strtoupper((string) $value['op']);
                if (!in_array($op, ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
                    throw new ToolException("Opérateur non supporté : {$op}");
                }
                $where["$table.$field"] = [$op, $value['value'] ?? null];
            } else {
                $where["$table.$field"] = $value;
            }
        }

        $order = (string) ($args['order'] ?? (isset($columns['date_mod']) ? 'date_mod' : 'id'));
        if (!isset($columns[$order])) {
            $order = 'id';
        }
        $dir = strtoupper((string) ($args['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $only = isset($args['fields']) && is_array($args['fields']) ? array_values(array_map('strval', $args['fields'])) : null;
        if ($only === null) {
            $only = array_values(array_filter(self::SUMMARY_COLUMNS, fn($c) => isset($columns[$c])));
        }

        $total_query = $DB->request(['COUNT' => 'cpt', 'FROM' => $table, 'WHERE' => $where])->current();
        $total = (int) ($total_query['cpt'] ?? 0);

        $results = [];
        $scanned = 0;
        $offset  = 0;
        // On vérifie l'accès objet par objet (droits fins GLPI : « mes tickets », groupes, etc.)
        while (count($results) < $limit && $offset < 2000) {
            $it = $DB->request([
                'SELECT' => ["$table.id"],
                'FROM'   => $table,
                'WHERE'  => $where,
                'ORDER'  => ["$table.$order $dir", "$table.id DESC"],
                'START'  => $offset,
                'LIMIT'  => 200,
            ]);
            if (count($it) === 0) {
                break;
            }
            foreach ($it as $row) {
                $scanned++;
                $obj = new ($item::class)();
                if (!$obj->getFromDB($row['id']) || !$obj->canViewItem()) {
                    continue;
                }
                $results[] = Items::export($obj, $only, false);
                if (count($results) >= $limit) {
                    break;
                }
            }
            $offset += 200;
        }

        return [
            'type'      => $item::getTypeName(2),
            'itemtype'  => $item::class,
            'total_correspondant_avant_droits_fins' => $total,
            'renvoyés'  => count($results),
            'résultats' => $results,
        ];
    }

    private function itilShortcut(CommonITILObject $item, string $key, mixed $value, Context $ctx): array
    {
        $table   = $item::getTable();
        $fk      = $item::getForeignKeyField();
        $u_link  = $item->userlinkclass;
        $g_link  = $item->grouplinkclass;

        return match ($key) {
            '_open' => $value
                ? ["$table.status" => ['NOT IN', array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray())]]
                : ["$table.status" => array_merge($item::getSolvedStatusArray(), $item::getClosedStatusArray())],
            '_requester' => ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk, 'FROM' => $u_link::getTable(),
                'WHERE' => ['users_id' => (int) $value, 'type' => CommonITILActor::REQUESTER],
            ])],
            '_assigned_user' => ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk, 'FROM' => $u_link::getTable(),
                'WHERE' => ['users_id' => (int) $value, 'type' => CommonITILActor::ASSIGN],
            ])],
            '_assigned_group' => ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk, 'FROM' => $g_link::getTable(),
                'WHERE' => ['groups_id' => (int) $value, 'type' => CommonITILActor::ASSIGN],
            ])],
            '_mine' => ["$table.id" => new QuerySubQuery([
                'SELECT' => $fk, 'FROM' => $u_link::getTable(),
                'WHERE' => ['users_id' => $ctx->users_id],
            ])],
            default => throw new ToolException("Raccourci inconnu : {$key}"),
        };
    }
}
