<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonDBTM;
use CommonITILActor;
use CommonITILObject;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Infocom;

class GetItem extends Tool
{
    public function name(): string
    {
        return 'glpi_get_item';
    }

    public function title(): string
    {
        return 'Fiche complète d\'un objet';
    }

    public function description(): string
    {
        return "Renvoie la fiche complète d'un objet GLPI (tous ses champs avec les libellés des objets liés). "
            . "Pour un ticket/problème/changement, inclut les acteurs ; pour un matériel, les infos financières si visibles.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'itemtype' => self::str('Type GLPI ou alias'),
            'id'       => self::int('Identifiant'),
        ], ['itemtype', 'id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $item = Items::load((string) self::requireArg($args, 'itemtype'), (int) self::requireArg($args, 'id'), READ);
        $out = Items::export($item);

        if ($item instanceof CommonITILObject) {
            $out['acteurs'] = self::actors($item);
        }
        if (Infocom::canApplyOn($item) && Infocom::canView()) {
            $ic = new Infocom();
            if ($ic->getFromDBforDevice($item::class, $item->getID())) {
                $out['infos_financières'] = Items::export($ic, ['buy_date', 'use_date', 'warranty_date', 'warranty_duration', 'value', 'suppliers_id', 'order_number', 'delivery_number', 'immo_number', 'bill', 'budgets_id']);
            }
        }
        $out['droits_sur_cet_objet'] = [
            'modifier'  => $item->can($item->getID(), UPDATE),
            'supprimer' => $item->can($item->getID(), DELETE) || $item->can($item->getID(), PURGE),
        ];
        return $out;
    }

    public static function actors(CommonITILObject $item): array
    {
        $labels = [CommonITILActor::REQUESTER => 'demandeurs', CommonITILActor::ASSIGN => 'attribués', CommonITILActor::OBSERVER => 'observateurs'];
        $out = [];
        foreach ($labels as $type => $label) {
            $out[$label] = [];
            foreach ($item->getUsers($type) as $u) {
                $out[$label][] = $u['users_id'] > 0 ? ['utilisateur' => getUserName($u['users_id']), 'id' => $u['users_id']] : ['email' => $u['alternative_email']];
            }
            foreach ($item->getGroups($type) as $g) {
                $out[$label][] = ['groupe' => \Dropdown::getDropdownName('glpi_groups', $g['groups_id']), 'id' => $g['groups_id']];
            }
            if (method_exists($item, 'getSuppliers')) {
                foreach ($item->getSuppliers($type) as $s) {
                    $out[$label][] = ['fournisseur' => \Dropdown::getDropdownName('glpi_suppliers', $s['suppliers_id']), 'id' => $s['suppliers_id']];
                }
            }
        }
        return $out;
    }
}
