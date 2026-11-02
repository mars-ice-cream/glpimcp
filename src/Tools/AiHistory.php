<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\AuditLog;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Rollback;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Session;

class AiHistory extends Tool
{
    public function name(): string
    {
        return 'glpi_ai_history';
    }

    public function title(): string
    {
        return 'Journal des actions IA';
    }

    public function description(): string
    {
        return "Journal des actions faites via l'assistant IA : qui, avec quel assistant, quel outil, quand, statut (exécuté, échec, refusé, annulé) "
            . "et si l'action est annulable. Par défaut : mes actions. Un administrateur GLPI peut voir celles de tout le monde (all_users=true). "
            . "log_id=… pour le détail d'une action (opérations, résultats, ce que ferait l'annulation).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'log_id'    => self::int('Détail d\'une entrée'),
            'all_users' => self::bool('Toutes les personnes (administrateurs uniquement)'),
            'users_id'  => self::int('Filtrer sur un utilisateur (administrateurs)'),
            'status'    => self::str('Filtrer sur un statut', ['exécuté', 'échec', 'refusé', 'annulé']),
            'query'     => self::str('Texte dans le résumé'),
            'limit'     => self::int('Nombre max (défaut 20)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;
        $is_admin = Session::haveRight('config', UPDATE);

        if (!empty($args['log_id'])) {
            $row = AuditLog::get((int) $args['log_id']);
            if ($row === null || ((int) $row['users_id'] !== $ctx->users_id && !$is_admin)) {
                throw new ToolException('Entrée de journal introuvable ou appartenant à un autre utilisateur.');
            }
            return Rollback::describe($row, true);
        }

        $where = [];
        if (!$is_admin || (empty($args['all_users']) && empty($args['users_id']))) {
            $where['users_id'] = $ctx->users_id;
        } elseif (!empty($args['users_id'])) {
            $where['users_id'] = (int) $args['users_id'];
        }
        if (!empty($args['status'])) {
            $where['status'] = (string) $args['status'];
        }
        if (!empty($args['query'])) {
            $where['summary'] = ['LIKE', '%' . $args['query'] . '%'];
        }
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));
        $out = [];
        foreach ($DB->request(['FROM' => AuditLog::TABLE, 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $limit]) as $row) {
            $out[] = Rollback::describe($row);
        }
        return ['nb' => count($out), 'actions' => $out];
    }
}
