<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class TicketAddSolution extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_add_solution';
    }

    public function title(): string
    {
        return __('Solve a ticket', 'glpimcp');
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
        return "Prépare l'ajout d'une solution à un ticket/problème/changement (le passe en « Résolu » selon le paramétrage). "
            . "Rédige une solution claire pour le demandeur. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'               => self::int('Numéro du ticket'),
            'itemtype'         => self::str('Ticket (défaut), Problem, Change', ['Ticket', 'Problem', 'Change']),
            'content'          => self::str('Texte de la solution'),
            'solutiontypes_id' => self::int('Type de solution (optionnel)'),
        ], ['id', 'content']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $parent = Items::load((string) ($args['itemtype'] ?? 'Ticket'), (int) self::requireArg($args, 'id'), READ);
        $input = [
            'itemtype' => $parent::class,
            'items_id' => $parent->getID(),
            'content'  => Items::toHtml((string) self::requireArg($args, 'content')),
        ];
        if (!empty($args['solutiontypes_id'])) {
            $input['solutiontypes_id'] = (int) $args['solutiontypes_id'];
        }
        $warn = \GlpiPlugin\Glpimcp\Tickets\LanguageCheck::languageWarning($parent, (string) $args['content']);
        $result = PendingActions::propose($ctx, $this->name(), sprintf(__('Solution on %s', 'glpimcp'), $parent->getNameID()), [[
            'op' => 'add', 'itemtype' => 'ITILSolution', 'input' => $input,
        ]], $warn !== null ? ['force_preview' => true] : []);
        // Vérification de langue (comme pour un humain : on répond dans la langue du demandeur)
        if ($warn !== null) {
            $result['vérification_langue'] = $warn;
        }
        return $result;
    }
}
