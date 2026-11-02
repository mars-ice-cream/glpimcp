<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class ListPendingActions extends Tool
{
    public function name(): string
    {
        return 'glpi_list_pending_actions';
    }

    public function title(): string
    {
        return 'Actions en attente de confirmation';
    }

    public function description(): string
    {
        return "Liste les actions préparées par l'assistant et pas encore confirmées par l'utilisateur.";
    }

    public function inputSchema(): array
    {
        return self::obj([]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        return ['actions_en_attente' => PendingActions::listForUser($ctx)];
    }
}
