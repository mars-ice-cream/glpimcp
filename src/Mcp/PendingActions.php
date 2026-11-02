<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Ramsey\Uuid\Uuid;

/**
 * Cycle « aperçu → confirmation » des écritures.
 *
 * L'IA ne peut rien modifier sans que l'utilisateur ait vu l'aperçu : l'outil
 * d'écriture renvoie un identifiant de confirmation, et seul un second appel
 * explicite (glpi_confirm_action) exécute réellement les opérations, avec
 * une nouvelle vérification des droits.
 *
 * Chaque étape est journalisée (aperçu refusé, annulé, exécuté, échec), avec
 * les opérations inverses permettant d'annuler l'action plus tard.
 */
final class PendingActions
{
    private const TABLE = 'glpi_plugin_glpimcp_pendingactions';

    /**
     * @param array $meta  ex : ['rollback_of' => <id du journal annulé>]
     */
    public static function propose(Context $ctx, string $tool, string $summary, array $ops, array $meta = []): array
    {
        global $DB;

        try {
            Permissions::assertOps($ops, !empty($meta['rollback_of']));
            $check = Operations::check($ops);
        } catch (ToolException $e) {
            AuditLog::write($ctx, $tool, 'refusé', $summary, ['erreur' => $e->getMessage(), 'operations' => $ops]);
            throw $e;
        }

        $mode = (string) $ctx->config('confirm_mode', 'all');
        if ($mode === 'sensitive' && !$check['sensitive'] && empty($meta['rollback_of']) && empty($meta['force_preview'])) {
            // Mode « confirmation des seules actions sensibles » : exécution immédiate.
            $out = self::run($ctx, $tool, $summary, $ops, $meta);
            $out['avertissements'] = $check['warnings'];
            return $out;
        }

        self::purgeExpired();

        $uuid = 'conf_' . str_replace('-', '', Uuid::uuid4()->toString());
        $ttl  = max(1, (int) $ctx->config('pending_ttl_minutes', 30));
        $DB->insert(self::TABLE, [
            'uuid'            => $uuid,
            'users_id'        => $ctx->users_id,
            'tokens_id'       => (int) $ctx->token['id'],
            'action'          => $tool,
            'payload'         => json_encode(['ops' => $ops, 'meta' => $meta], JSON_UNESCAPED_UNICODE),
            'summary'         => $summary,
            'date_creation'   => date('Y-m-d H:i:s'),
            'date_expiration' => date('Y-m-d H:i:s', time() + $ttl * 60),
        ]);

        return [
            'statut'          => 'en_attente_de_confirmation',
            'confirmation_id' => $uuid,
            'résumé'          => $summary,
            'aperçu'          => $check['preview'],
            'avertissements'  => $check['warnings'],
            'sensible'        => $check['sensitive'],
            'expire_dans_minutes' => $ttl,
            'consigne_pour_l_ia' => "RIEN n'a encore été modifié. Présente cet aperçu à l'utilisateur de façon claire, "
                . "puis demande-lui une validation explicite. Seulement s'il accepte, appelle glpi_confirm_action "
                . "avec confirmation_id. S'il refuse ou veut changer quelque chose, appelle glpi_cancel_action puis refais une proposition.",
        ];
    }

    public static function confirm(Context $ctx, string $uuid): array
    {
        global $DB;

        $row = self::get($ctx, $uuid);
        $payload = json_decode((string) $row['payload'], true) ?? [];
        $ops  = $payload['ops'] ?? $payload; // compatibilité 0.1
        $meta = $payload['meta'] ?? [];

        // Usage unique : on retire l'action avant exécution (pas de double exécution).
        $DB->delete(self::TABLE, ['id' => $row['id']]);

        return self::run($ctx, (string) $row['action'], (string) $row['summary'], $ops, $meta);
    }

    /**
     * Exécution + journal + marquage « via IA » dans l'historique GLPI.
     */
    public static function run(Context $ctx, string $tool, string $summary, array $ops, array $meta = []): array
    {
        $rollback_of = (int) ($meta['rollback_of'] ?? 0);
        $cursor = AuditLog::historyCursor();
        $journal = null;

        try {
            // Réglages revérifiés à l'exécution (ils ont pu changer entre l'aperçu et la confirmation)
            Permissions::assertOps($ops, $rollback_of > 0);
            $results = Operations::execute($ops, $journal);
        } catch (ToolException $e) {
            AuditLog::write($ctx, $tool, 'échec', $summary, ['erreur' => $e->getMessage(), 'détails' => $e->getDetails(), 'operations' => $ops], null, $rollback_of);
            throw $e;
        }

        $log_id = AuditLog::write($ctx, $tool, 'exécuté', $summary, ['operations' => $ops, 'résultats' => $results], $journal, $rollback_of);
        AuditLog::tagHistory(
            $cursor,
            $ctx->users_id,
            ($rollback_of ? 'annulation IA #' . $rollback_of : 'via IA') . ' : ' . ($ctx->token['name'] ?? 'assistant') . ' — journal #' . $log_id
        );
        if ($rollback_of > 0 && $log_id > 0) {
            AuditLog::markRolledBack($rollback_of, $log_id);
        }

        $out = [
            'statut'      => 'exécuté',
            'résumé'      => $summary,
            'journal_id'  => $log_id,
            'résultats'   => $results,
        ];
        if (($journal['undo'] ?? []) !== [] && !$rollback_of) {
            $out['annulable'] = "Oui : glpi_rollback_action avec log_id={$log_id} (ou bouton « Annuler » dans le journal GLPI).";
        }
        if (($journal['irréversible'] ?? []) !== []) {
            $out['non_annulable'] = $journal['irréversible'];
        }
        return $out;
    }

    public static function cancel(Context $ctx, string $uuid): array
    {
        global $DB;
        $row = self::get($ctx, $uuid);
        $DB->delete(self::TABLE, ['id' => $row['id']]);
        AuditLog::write($ctx, (string) $row['action'], 'annulé', (string) $row['summary'], ['raison' => "refusé par l'utilisateur à l'aperçu"]);
        return ['statut' => 'annulé', 'résumé' => $row['summary']];
    }

    public static function listForUser(Context $ctx): array
    {
        global $DB;
        self::purgeExpired();
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $ctx->users_id], 'ORDER' => 'id DESC']) as $row) {
            $out[] = [
                'confirmation_id' => $row['uuid'],
                'outil'           => $row['action'],
                'résumé'          => $row['summary'],
                'créé_le'         => $row['date_creation'],
                'expire_le'       => $row['date_expiration'],
            ];
        }
        return $out;
    }

    private static function get(Context $ctx, string $uuid): array
    {
        global $DB;
        self::purgeExpired();
        $it = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['uuid' => $uuid, 'users_id' => $ctx->users_id],
            'LIMIT' => 1,
        ]);
        if (count($it) !== 1) {
            throw new ToolException("Confirmation « {$uuid} » introuvable ou expirée. Refais la demande pour obtenir un nouvel aperçu.");
        }
        return $it->current();
    }

    private static function purgeExpired(): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['date_expiration' => ['<', date('Y-m-d H:i:s')]]);
    }
}
