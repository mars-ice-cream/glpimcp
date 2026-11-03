<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILValidation;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class TicketValidationAnswer extends Tool
{
    public function name(): string
    {
        return 'glpi_validation_answer';
    }

    public function title(): string
    {
        return __('Answer an approval request', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare l'acceptation ou le refus d'une demande de validation (approbation) de ticket ou changement adressée à l'utilisateur. "
            . "L'id de validation se trouve dans glpi_ticket_get (chronologie). Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'validation_id' => self::int('Identifiant de la validation'),
            'itemtype'      => self::str('TicketValidation (défaut) ou ChangeValidation', ['TicketValidation', 'ChangeValidation']),
            'decision'      => self::str('accepter ou refuser', ['accepter', 'refuser']),
            'comment'       => self::str('Commentaire (obligatoire en cas de refus)'),
        ], ['validation_id', 'decision']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $itemtype = (string) ($args['itemtype'] ?? 'TicketValidation');
        $id = (int) self::requireArg($args, 'validation_id');
        Items::load($itemtype, $id, READ);
        $accept = ($args['decision'] ?? '') === 'accepter';
        return PendingActions::propose($ctx, $this->name(), sprintf($accept ? __('Acceptance of approval request #%d', 'glpimcp') : __('Refusal of approval request #%d', 'glpimcp'), $id), [[
            'op'       => 'update',
            'itemtype' => $itemtype,
            'id'       => $id,
            'input'    => [
                'status'             => $accept ? CommonITILValidation::ACCEPTED : CommonITILValidation::REFUSED,
                'comment_validation' => (string) ($args['comment'] ?? ''),
            ],
        ]]);
    }
}
