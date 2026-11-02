<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILActor;
use CommonITILObject;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

class TicketAssign extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_add_actor';
    }

    public function title(): string
    {
        return 'Attribuer / ajouter un acteur';
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
        return "Prépare l'ajout d'un acteur (technicien ou groupe attribué, demandeur, observateur) sur un ticket/problème/changement. "
            . "Les droits d'attribution GLPI s'appliquent. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'        => self::int('Numéro du ticket'),
            'itemtype'  => self::str('Ticket (défaut), Problem, Change', ['Ticket', 'Problem', 'Change']),
            'role'      => self::str('Rôle', ['attribue', 'demandeur', 'observateur']),
            'users_id'  => self::int('Utilisateur'),
            'groups_id' => self::int('Groupe'),
        ], ['id', 'role']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $parent = Items::load((string) ($args['itemtype'] ?? 'Ticket'), (int) self::requireArg($args, 'id'), READ);
        if (!$parent instanceof CommonITILObject) {
            throw new ToolException('Type non ITIL.');
        }
        $type = ['attribue' => CommonITILActor::ASSIGN, 'demandeur' => CommonITILActor::REQUESTER, 'observateur' => CommonITILActor::OBSERVER][$args['role']] ?? null;
        if ($type === null) {
            throw new ToolException('Rôle invalide.');
        }
        $ops = [];
        $fk = $parent::getForeignKeyField();
        if (!empty($args['users_id'])) {
            $ops[] = ['op' => 'add', 'itemtype' => $parent->userlinkclass, 'input' => [$fk => $parent->getID(), 'users_id' => (int) $args['users_id'], 'type' => $type]];
        }
        if (!empty($args['groups_id'])) {
            $ops[] = ['op' => 'add', 'itemtype' => $parent->grouplinkclass, 'input' => [$fk => $parent->getID(), 'groups_id' => (int) $args['groups_id'], 'type' => $type]];
        }
        if ($ops === []) {
            throw new ToolException('Indique users_id et/ou groups_id.');
        }
        return PendingActions::propose($ctx, $this->name(), sprintf('Ajout %s sur %s', $args['role'], $parent->getNameID()), $ops);
    }
}
