<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp;

use GlpiPlugin\Glpimcp\Mcp\AuditLog;
use Html;

/**
 * Affichage du journal des actions IA dans l'interface GLPI.
 */
final class LogView
{
    public const STATUS_BADGES = [
        'exécuté' => 'bg-green-lt',
        'échec'   => 'bg-red-lt',
        'refusé'  => 'bg-orange-lt',
        'annulé'  => 'bg-secondary-lt',
    ];

    /** Statut enregistré (clé française stable) → libellé dans la langue de l'utilisateur */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'exécuté'  => __('executed', 'glpimcp'),
            'échec'    => __('failed', 'glpimcp'),
            'refusé'   => __('refused', 'glpimcp'),
            'annulé'   => __('cancelled', 'glpimcp'),
            'approuvé' => __('approved', 'glpimcp'),
            'suspendu' => __('suspended', 'glpimcp'),
            default    => $status,
        };
    }

    public static function url(int $id): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/glpimcp/front/log.form.php?id=' . $id;
    }

    public static function renderTable(array $where, int $limit = 200, bool $show_user = true): void
    {
        global $DB;

        echo '<table class="table table-sm table-hover mb-0"><thead><tr>';
        echo '<th>#</th><th>' . __('Date') . '</th>';
        if ($show_user) {
            echo '<th>' . \User::getTypeName(1) . '</th>';
        }
        echo '<th>' . __('Assistant', 'glpimcp') . '</th><th>' . __('Tool', 'glpimcp') . '</th><th>' . __('Status') . '</th><th>' . __('Summary', 'glpimcp') . '</th><th></th>';
        echo '</tr></thead><tbody>';

        $rows = $DB->request(['FROM' => AuditLog::TABLE, 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $limit]);
        foreach ($rows as $log) {
            $badge = self::STATUS_BADGES[$log['status']] ?? 'bg-secondary-lt';
            $undo  = json_decode((string) ($log['undo'] ?? ''), true) ?: [];
            echo '<tr>';
            echo '<td><a href="' . htmlescape(self::url((int) $log['id'])) . '">' . (int) $log['id'] . '</a></td>';
            echo '<td>' . Html::convDateTime($log['date_creation']) . '</td>';
            if ($show_user) {
                echo '<td>' . htmlescape(getUserName($log['users_id'])) . '</td>';
            }
            echo '<td>' . htmlescape($log['client'] ?: '—') . '</td>';
            echo '<td><code>' . htmlescape($log['tool']) . '</code></td>';
            echo '<td><span class="badge ' . $badge . '">' . htmlescape(self::statusLabel((string) $log['status'])) . '</span>';
            if ((int) $log['rolled_back_by']) {
                echo ' <span class="badge bg-purple-lt">' . sprintf(__('undone (#%d)', 'glpimcp'), (int) $log['rolled_back_by']) . '</span>';
            }
            if ((int) $log['rollback_of']) {
                echo ' <span class="badge bg-purple-lt">' . sprintf(__('undo of #%d', 'glpimcp'), (int) $log['rollback_of']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . htmlescape($log['summary']) . '</td>';
            echo '<td class="text-end">';
            if ($log['status'] === 'exécuté' && $undo !== [] && !(int) $log['rolled_back_by']) {
                echo '<a class="btn btn-sm btn-outline-warning" href="' . htmlescape(self::url((int) $log['id'])) . '"><i class="ti ti-arrow-back-up me-1"></i>' . __('Undo…', 'glpimcp') . '</a>';
            } else {
                echo '<a class="btn btn-sm btn-ghost-secondary" href="' . htmlescape(self::url((int) $log['id'])) . '">' . __('Details', 'glpimcp') . '</a>';
            }
            echo '</td></tr>';
        }
        if (count($rows) === 0) {
            echo '<tr><td colspan="8" class="text-muted p-3">' . __('No AI action yet.', 'glpimcp') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
