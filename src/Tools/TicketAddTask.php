<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILObject;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

class TicketAddTask extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_add_task';
    }

    public function title(): string
    {
        return __('Add a task', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        return "Prépare l'ajout d'une tâche technicien sur un ticket/problème/changement (temps passé, état, technicien, planification). Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'              => self::int('Numéro du ticket'),
            'itemtype'        => self::str('Ticket (défaut), Problem, Change', ['Ticket', 'Problem', 'Change']),
            'content'         => self::str('Description de la tâche'),
            'duration_minutes' => self::int('Temps passé en minutes'),
            'state'           => self::str('a_faire, fait ou information (défaut : fait)', ['a_faire', 'fait', 'information']),
            'users_id_tech'   => self::int('Technicien (défaut : moi)'),
            'begin'           => self::str('Début planifié (AAAA-MM-JJ HH:MM)'),
            'is_private'      => self::bool('Tâche privée'),
            'taskcategories_id' => self::int('Catégorie de tâche'),
        ], ['id', 'content']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $parent = Items::load((string) ($args['itemtype'] ?? 'Ticket'), (int) self::requireArg($args, 'id'), READ);
        if (!$parent instanceof CommonITILObject) {
            throw new ToolException('Type non ITIL.');
        }
        $task_class = $parent::getTaskClass();
        $minutes = max(0, (int) ($args['duration_minutes'] ?? 0));
        $input = [
            $parent::getForeignKeyField() => $parent->getID(),
            'content'       => Items::toHtml((string) self::requireArg($args, 'content')),
            'actiontime'    => $minutes * 60,
            'state'         => ['a_faire' => 1, 'fait' => 2, 'information' => 0][$args['state'] ?? 'fait'] ?? 2,
            'users_id_tech' => (int) ($args['users_id_tech'] ?? $ctx->users_id),
            'is_private'    => !empty($args['is_private']) ? 1 : 0,
        ];
        if (!empty($args['taskcategories_id'])) {
            $input['taskcategories_id'] = (int) $args['taskcategories_id'];
        }
        if (!empty($args['begin'])) {
            $begin = strtotime((string) $args['begin']);
            if ($begin === false) {
                throw new ToolException('Date de début invalide.');
            }
            $input['begin'] = date('Y-m-d H:i:s', $begin);
            $input['end']   = date('Y-m-d H:i:s', $begin + max(15, $minutes) * 60);
        }
        return PendingActions::propose($ctx, $this->name(), sprintf(__('Task on %s', 'glpimcp'), $parent->getNameID()), [[
            'op' => 'add', 'itemtype' => $task_class, 'input' => $input,
        ]]);
    }
}
