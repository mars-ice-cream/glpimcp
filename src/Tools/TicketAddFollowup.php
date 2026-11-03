<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class TicketAddFollowup extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_add_followup';
    }

    public function title(): string
    {
        return __('Add a follow-up', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare l'ajout d'un suivi (message) sur un ticket, problème ou changement. Public par défaut (visible du demandeur), "
            . "is_private=true pour une note interne. Aperçu puis glpi_confirm_action.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'         => self::int('Numéro du ticket'),
            'itemtype'   => self::str('Ticket (défaut), Problem, Change', ['Ticket', 'Problem', 'Change']),
            'content'    => self::str('Texte du suivi'),
            'is_private' => self::bool('Suivi privé (interne)'),
        ], ['id', 'content']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $itemtype = (string) ($args['itemtype'] ?? 'Ticket');
        $parent = Items::load($itemtype, (int) self::requireArg($args, 'id'), READ);
        $warn = empty($args['is_private']) ? \GlpiPlugin\Glpimcp\Tickets\LanguageCheck::languageWarning($parent, (string) $args['content']) : null;
        $result = PendingActions::propose($ctx, $this->name(), sprintf(!empty($args['is_private']) ? __('Private follow-up on %s', 'glpimcp') : __('Public follow-up on %s', 'glpimcp'), $parent->getNameID()), [[
            'op'       => 'add',
            'itemtype' => 'ITILFollowup',
            'input'    => [
                'itemtype'   => $parent::class,
                'items_id'   => $parent->getID(),
                'content'    => Items::toHtml((string) self::requireArg($args, 'content')),
                'is_private' => !empty($args['is_private']) ? 1 : 0,
            ],
        ]], $warn !== null ? ['force_preview' => true] : []);
        // Vérification de langue (comme pour un humain : on répond dans la langue du demandeur)
        if ($warn !== null) {
            $result['vérification_langue'] = $warn;
        }
        return $result;
    }
}
