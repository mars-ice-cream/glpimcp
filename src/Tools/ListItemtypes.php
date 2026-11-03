<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Throwable;

class ListItemtypes extends Tool
{
    public const GROUPS = [
        'Assistance' => ['Ticket', 'Problem', 'Change', 'TicketRecurrent', 'Project', 'ProjectTask', 'KnowbaseItem', 'Reminder', 'Glpi\\Form\\Form'],
        'Parc'       => ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone', 'Software', 'SoftwareLicense', 'Certificate', 'Appliance', 'Cartridge', 'CartridgeItem', 'ConsumableItem', 'Rack', 'Enclosure', 'PDU', 'PassiveDCEquipment', 'Cable', 'DatabaseInstance', 'Unmanaged'],
        'Gestion'    => ['Contract', 'Supplier', 'Contact', 'Budget', 'Document', 'Line', 'Datacenter', 'DCRoom'],
        'Administration' => ['User', 'Group', 'Entity', 'Profile', 'RuleTicket', 'RuleRight', 'RuleAsset', 'RuleMailCollector', 'Glpi\\Form\\Category'],
        'Configuration' => ['ITILCategory', 'Location', 'State', 'Manufacturer', 'RequestType', 'SolutionType', 'SolutionTemplate', 'TaskCategory', 'TaskTemplate', 'ITILFollowupTemplate', 'Calendar', 'Holiday', 'SLM', 'SLA', 'OLA', 'MailCollector', 'Notification', 'NotificationTemplate', 'FieldUnicity', 'TicketTemplate', 'BusinessCriticity', 'UserTitle', 'UserCategory'],
    ];

    public function name(): string
    {
        return 'glpi_list_itemtypes';
    }

    public function title(): string
    {
        return __('Available GLPI item types', 'glpimcp');
    }

    public function description(): string
    {
        return "Liste les types d'objets GLPI (tickets, matériels, entités, profils, catégories, SLA, règles…) que l'utilisateur peut lire et/ou créer, "
            . "regroupés par menu GLPI. Avec include_all_dropdowns=true, ajoute tous les intitulés (listes déroulantes).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'include_all_dropdowns' => self::bool('Inclure la liste complète des intitulés GLPI'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $out = [];
        foreach (self::GROUPS as $group => $types) {
            foreach ($types as $class) {
                if ($row = self::describe($class)) {
                    $out[$group][] = $row;
                }
            }
        }
        if (!empty($args['include_all_dropdowns'])) {
            foreach (Dropdown::getStandardDropdownItemTypes() as $section => $types) {
                foreach (array_keys($types) as $class) {
                    if ($row = self::describe($class)) {
                        $out['Intitulés : ' . $section][] = $row;
                    }
                }
            }
        }
        return $out;
    }

    private static function describe(string $class): ?array
    {
        try {
            if (!class_exists($class) || !is_a($class, CommonDBTM::class, true)) {
                return null;
            }
            $read = $class::canView();
            $create = $class::canCreate();
            if (!$read && !$create) {
                return null;
            }
            return [
                'itemtype' => $class,
                'nom'      => $class::getTypeName(1),
                'lire'     => $read,
                'créer'    => $create,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
