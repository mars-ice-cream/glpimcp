<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonDBTM;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class AssetSummary extends Tool
{
    private const TYPES = ['Computer', 'Monitor', 'NetworkEquipment', 'Printer', 'Phone', 'Peripheral', 'Software', 'SoftwareLicense', 'Contract', 'Certificate'];

    public function name(): string
    {
        return 'glpi_asset_summary';
    }

    public function title(): string
    {
        return __('Asset overview', 'glpimcp');
    }

    public function description(): string
    {
        return "Synthèse du parc visible par l'utilisateur : nombre d'éléments par type, répartition par statut, garanties et contrats qui expirent bientôt, "
            . "matériels sans lieu ou sans utilisateur. Pour le détail, utiliser glpi_search.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'days' => self::int('Horizon des échéances en jours (défaut 90)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;

        $days = max(1, min(730, (int) ($args['days'] ?? 90)));
        $limit_date = date('Y-m-d', strtotime("+{$days} days"));
        $out = ['par_type' => [], 'alertes' => []];

        foreach (self::TYPES as $class) {
            if (!class_exists($class) || !$class::canView()) {
                continue;
            }
            /** @var CommonDBTM $item */
            $item  = new $class();
            $table = $class::getTable();
            $where = [];
            if ($item->maybeDeleted()) {
                $where["$table.is_deleted"] = 0;
            }
            if ($item->maybeTemplate()) {
                $where["$table.is_template"] = 0;
            }
            if ($item->isEntityAssign()) {
                $where[] = getEntitiesRestrictCriteria($table, '', '', $item->maybeRecursive());
            }
            $total = (int) $DB->request(['COUNT' => 'c', 'FROM' => $table, 'WHERE' => $where])->current()['c'];
            $entry = ['type' => $class::getTypeName(2), 'total' => $total];

            if ($total > 0 && $DB->fieldExists($table, 'states_id')) {
                $by_state = [];
                foreach ($DB->request(['SELECT' => ['states_id', 'COUNT' => 'id AS c'], 'FROM' => $table, 'WHERE' => $where, 'GROUPBY' => 'states_id']) as $r) {
                    $by_state[$r['states_id'] ? \Dropdown::getDropdownName('glpi_states', $r['states_id']) : '(sans statut)'] = (int) $r['c'];
                }
                $entry['par_statut'] = $by_state;
            }
            if ($total > 0 && $DB->fieldExists($table, 'locations_id')) {
                $entry['sans_lieu'] = (int) $DB->request(['COUNT' => 'c', 'FROM' => $table, 'WHERE' => $where + ["$table.locations_id" => 0]])->current()['c'];
            }
            if ($total > 0 && $DB->fieldExists($table, 'users_id')) {
                $entry['sans_utilisateur'] = (int) $DB->request(['COUNT' => 'c', 'FROM' => $table, 'WHERE' => $where + ["$table.users_id" => 0]])->current()['c'];
            }
            $out['par_type'][] = $entry;
        }

        // Garanties qui expirent
        if (\Infocom::canView()) {
            $warranties = [];
            foreach ($DB->request([
                'FROM'  => 'glpi_infocoms',
                'WHERE' => ['warranty_duration' => ['>', 0], 'NOT' => ['warranty_date' => null]],
                'LIMIT' => 2000,
            ]) as $ic) {
                $end = date('Y-m-d', strtotime($ic['warranty_date'] . ' +' . (int) $ic['warranty_duration'] . ' months'));
                if ($end >= date('Y-m-d') && $end <= $limit_date && class_exists($ic['itemtype'])) {
                    $it = new $ic['itemtype']();
                    if ($it->getFromDB($ic['items_id']) && $it->canViewItem()) {
                        $warranties[] = ['objet' => $it->getNameID(), 'type' => $it::getTypeName(1), 'fin_garantie' => $end];
                    }
                }
            }
            $out['alertes']['garanties_expirant'] = array_slice($warranties, 0, 50);
        }

        // Contrats qui expirent
        if (\Contract::canView()) {
            $contracts = [];
            foreach ($DB->request(['FROM' => 'glpi_contracts', 'WHERE' => ['is_deleted' => 0, 'is_template' => 0, 'duration' => ['>', 0], 'NOT' => ['begin_date' => null]] + getEntitiesRestrictCriteria('glpi_contracts', '', '', true)]) as $c) {
                $end = date('Y-m-d', strtotime($c['begin_date'] . ' +' . (int) $c['duration'] . ' months'));
                if ($end >= date('Y-m-d') && $end <= $limit_date) {
                    $contracts[] = ['contrat' => $c['name'], 'id' => (int) $c['id'], 'fin' => $end, 'préavis_mois' => (int) $c['notice']];
                }
            }
            $out['alertes']['contrats_expirant'] = $contracts;
        }

        $out['horizon_jours'] = $days;
        return $out;
    }
}
