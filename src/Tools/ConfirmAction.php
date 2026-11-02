<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class ConfirmAction extends Tool
{
    public function name(): string
    {
        return 'glpi_confirm_action';
    }

    public function title(): string
    {
        return 'Confirmer une action';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Exécute une action préparée (création, modification, suppression, paramétrage…) APRÈS accord explicite de l'utilisateur sur l'aperçu. "
            . "Ne jamais appeler sans que l'utilisateur ait vu l'aperçu et dit oui. Les droits sont revérifiés ; en cas d'échec, rien n'est modifié.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'confirmation_id' => self::str('Identifiant renvoyé par l\'outil de préparation'),
        ], ['confirmation_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        return PendingActions::confirm($ctx, (string) self::requireArg($args, 'confirmation_id'));
    }
}
