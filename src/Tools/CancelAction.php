<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class CancelAction extends Tool
{
    public function name(): string
    {
        return 'glpi_cancel_action';
    }

    public function title(): string
    {
        return __('Cancel a pending action', 'glpimcp');
    }

    public function description(): string
    {
        return "Abandonne une action préparée et non confirmée (l'utilisateur a refusé ou veut changer quelque chose).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'confirmation_id' => self::str('Identifiant de l\'action à abandonner'),
        ], ['confirmation_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        return PendingActions::cancel($ctx, (string) self::requireArg($args, 'confirmation_id'));
    }
}
