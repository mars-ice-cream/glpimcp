<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Rollback;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class RollbackAction extends Tool
{
    public function name(): string
    {
        return 'glpi_rollback_action';
    }

    public function title(): string
    {
        return 'Annuler une action IA passée';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare l'annulation d'une action déjà exécutée via l'IA (log_id issu de glpi_ai_history ou du résultat d'une action) : "
            . "supprime ce qui a été créé, remet les anciennes valeurs, restaure ce qui a été mis à la corbeille, rétablit droits et configuration. "
            . "Si des éléments ont été modifiés depuis, l'outil s'arrête et liste les conflits (force=true pour passer outre, avec l'accord de l'utilisateur). "
            . "Toujours un aperçu à confirmer avec glpi_confirm_action. Réservé à l'auteur de l'action ou à un administrateur.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'log_id' => self::int('Entrée du journal à annuler'),
            'force'  => self::bool('Annuler malgré les conflits (accord explicite de l\'utilisateur requis)'),
        ], ['log_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $plan = Rollback::plan((int) self::requireArg($args, 'log_id'), !empty($args['force']));
        $log  = $plan['log'];

        $result = PendingActions::propose(
            $ctx,
            $this->name(),
            sprintf("Annulation de l'action IA #%d du %s par %s : %s", $log['id'], $log['date_creation'], getUserName($log['users_id']), $log['summary']),
            $plan['ops'],
            ['rollback_of' => (int) $log['id']]
        );
        $result['étapes_d_annulation'] = $plan['étapes'];
        if ($plan['conflits']) {
            $result['conflits_ignorés'] = $plan['conflits'];
        }
        if ($plan['irréversible']) {
            $result['ce_qui_ne_sera_pas_défait'] = $plan['irréversible'];
        }
        if ($plan['avertissements']) {
            $result['avertissements'] = array_merge($result['avertissements'] ?? [], $plan['avertissements']);
        }
        return $result;
    }
}
