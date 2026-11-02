<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Glpi\DBAL\QueryExpression;
use User;

/**
 * Journal des actions faites via l'assistant IA : qui, avec quel assistant, quoi,
 * quand, résultat — et de quoi les annuler (opérations inverses).
 */
final class AuditLog
{
    public const TABLE = 'glpi_plugin_glpimcp_logs';

    /**
     * @return int id de l'entrée de journal (0 si l'écriture a échoué)
     */
    public static function write(
        Context $ctx,
        string $tool,
        string $status,
        string $summary,
        array $details = [],
        ?array $journal = null,
        int $rollback_of = 0
    ): int {
        global $DB;
        try {
            $DB->insert(self::TABLE, [
                'users_id'      => $ctx->users_id,
                'tokens_id'     => (int) ($ctx->token['id'] ?? 0),
                'client'        => mb_substr((string) ($ctx->token['name'] ?? ''), 0, 255),
                'profiles_id'   => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
                'tool'          => mb_substr($tool, 0, 100),
                'status'        => mb_substr($status, 0, 20),
                'summary'       => $summary,
                'details'       => self::json($details),
                'undo'          => $journal !== null ? self::json($journal['undo'] ?? []) : null,
                'state'         => $journal !== null ? self::json($journal['état'] ?? []) : null,
                'irreversible'  => $journal !== null ? self::json($journal['irréversible'] ?? []) : null,
                'rollback_of'   => $rollback_of,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
            return (int) $DB->insertId();
        } catch (\Throwable) {
            return 0; // Le journal ne doit jamais casser l'action.
        }
    }

    public static function get(int $id): ?array
    {
        global $DB;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        return count($it) ? $it->current() : null;
    }

    public static function markRolledBack(int $id, int $by_log_id): void
    {
        global $DB;
        $DB->update(self::TABLE, ['rolled_back_by' => $by_log_id], ['id' => $id]);
    }

    /** Dernier id de l'historique natif GLPI (glpi_logs), pour marquer ensuite les lignes « via IA ». */
    public static function historyCursor(): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['MAX' => 'id AS m'], 'FROM' => 'glpi_logs'])->current();
        return (int) ($row['m'] ?? 0);
    }

    /**
     * Ajoute « [via IA : <assistant>] » au nom d'utilisateur des lignes d'historique GLPI
     * créées depuis $cursor par cet utilisateur. Visible dans l'onglet Historique de chaque objet.
     */
    public static function tagHistory(int $cursor, int $users_id, string $label): void
    {
        global $DB;
        try {
            $name = User::getNameForLog($users_id);
            $suffix = ' [' . $label . ']';
            $DB->update('glpi_logs', [
                'user_name' => new QueryExpression('LEFT(CONCAT(' . $DB->quoteName('user_name') . ', ' . $DB->quoteValue($suffix) . '), 255)'),
            ], [
                'id'        => ['>', $cursor],
                'user_name' => $name,
            ]);
        } catch (\Throwable) {
        }
    }

    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
