<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Cycle « aperçu → confirmation (→ approbation humaine) → exécution » des écritures.
 *
 * - Toute écriture produit d'abord un aperçu et une proposition liée à son contexte GLPI (jeton, profil,
 *   entités actives), au contenu exact du lot (empreinte) et à l'aperçu montré (empreinte).
 * - glpi_confirm_action est le second appel de l'assistant. Pour les opérations critiques (voir Approval),
 *   il ne suffit pas : un humain doit d'abord approuver dans GLPI.
 * - Usage unique garanti en base : la proposition est RÉSERVÉE par un UPDATE conditionnel (pending → claimed,
 *   vérifié par le nombre de lignes affectées) ; seule la requête qui l'a réservée exécute. Le passage à
 *   « executed » est écrit DANS la transaction des écritures : il est validé avec elles ou annulé avec elles.
 * - Au moment de l'exécution : droits GLPI et interrupteurs revérifiés, aperçu recalculé et comparé (si les
 *   données ont changé, nouvel aperçu exigé), conflits d'annulation revérifiés.
 *
 * Statuts : pending, claimed, executed, failed, stale (données changées), cancelled, refused (refus humain).
 */
final class PendingActions
{
    private const TABLE = 'glpi_plugin_glpimcp_pendingactions';

    /** Une réservation plus ancienne est considérée interrompue (son écriture n'a pas été validée). */
    public const CLAIM_TIMEOUT = 900;

    /** Propositions terminées conservées (réponse idempotente aux nouvelles tentatives), en jours */
    private const KEEP_DAYS = 30;

    /**
     * @param array $meta  ex : ['rollback_of' => <id>, 'force' => bool, 'conflits_acceptés' => [...]]
     */
    public static function propose(Context $ctx, string $tool, string $summary, array $ops, array $meta = []): array
    {
        global $DB;

        $ops = Operations::canonicalize($ops);
        try {
            if (empty($meta['rollback_of'])) {
                // Quotas (écritures/heure, tickets/heure, client) : un flot d'aperçus est aussi un abus
                RateLimiter::checkWrite($ctx, $ops, true);
            }
            Permissions::assertOps($ops, !empty($meta['rollback_of']));
            $check = Operations::check($ops);
        } catch (ToolException $e) {
            AuditLog::write($ctx, $tool, 'refusé', $summary, ['erreur' => $e->getMessage(), 'operations' => $ops]);
            throw $e;
        }

        $reasons = Approval::reasons($ops, $meta, $check['sensitive'], Approval::scope($ctx));
        $mode = (string) $ctx->config('confirm_mode', 'all');
        if (
            $mode === 'sensitive' && !$check['sensitive'] && $reasons === []
            && empty($meta['rollback_of']) && empty($meta['force_preview'])
        ) {
            // Mode « confirmation des seules actions sensibles » : exécution immédiate des écritures ordinaires.
            $out = self::run($ctx, $tool, $summary, $ops, $meta);
            $out['avertissements'] = $check['warnings'];
            $out['mode'] = 'exécution immédiate (mode « sensibles » choisi par l\'administrateur)';
            return $out;
        }

        self::purge();

        $uuid    = 'conf_' . str_replace('-', '', Uuid::uuid4()->toString());
        $ttl     = max(1, (int) $ctx->config('pending_ttl_minutes', 30));
        $payload = self::encode(['ops' => $ops, 'meta' => $meta]);
        $DB->insert(self::TABLE, [
            'uuid'              => $uuid,
            'users_id'          => $ctx->users_id,
            'tokens_id'         => (int) $ctx->token['id'],
            'profiles_id'       => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
            'action'            => $tool,
            'payload'           => $payload,
            'summary'           => $summary,
            'status'            => 'pending',
            'context_hash'      => TokenScope::contextHash($ctx),
            'payload_hash'      => hash('sha256', $payload),
            'preview'           => self::encode([
                'aperçu' => $check['preview'], 'avertissements' => $check['warnings'], 'sensible' => $check['sensitive'],
                'contexte' => [
                    'assistant' => (string) ($ctx->token['name'] ?? ''),
                    'profil'    => $ctx->profileName(),
                    'entité'    => (string) ($_SESSION['glpiactive_entity_name'] ?? ''),
                    'sous_entités' => (bool) ($_SESSION['glpiactive_entity_recursive'] ?? false),
                ],
                'annulation' => !empty($meta['rollback_of']) ? ['de_l_action' => (int) $meta['rollback_of'], 'forcée' => !empty($meta['force']), 'conflits_acceptés' => $meta['conflits_acceptés'] ?? []] : null,
            ]),
            'preview_hash'      => self::previewHash($check['preview'], Operations::basis($ops)),
            'requires_approval' => $reasons !== [] ? 1 : 0,
            'approval_reasons'  => self::encode($reasons),
            'date_creation'     => date('Y-m-d H:i:s'),
            'date_mod'          => date('Y-m-d H:i:s'),
            'date_expiration'   => date('Y-m-d H:i:s', time() + $ttl * 60),
        ]);

        $out = [
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
        if ($reasons !== []) {
            $out['statut'] = 'en_attente_d_approbation_humaine';
            $out['approbation_humaine'] = [
                'requise' => true,
                'raisons' => $reasons,
                'url'     => Approval::url($uuid),
            ];
            $out['consigne_pour_l_ia'] = "RIEN n'a encore été modifié. Cette action est critique : ta confirmation ne suffit pas. "
                . "Présente l'aperçu, puis donne à l'utilisateur le lien « approbation_humaine.url » : il doit approuver lui-même dans GLPI "
                . "(connecté avec son compte). Une fois qu'il te dit l'avoir fait, appelle glpi_confirm_action avec confirmation_id. "
                . "Ne prétends jamais avoir approuvé à sa place.";
        }
        return $out;
    }

    public static function confirm(Context $ctx, string $uuid): array
    {
        global $DB;

        $row = self::find($ctx, $uuid);
        self::assertConfirmable($ctx, $row);

        // ---- Réservation atomique (usage unique, y compris en cas d'appels simultanés) -------------
        $claim = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $DB->update(self::TABLE, ['status' => 'claimed', 'claim_token' => $claim, 'date_claim' => $now, 'date_mod' => $now], [
            'id' => (int) $row['id'],
            'OR' => [
                ['status' => 'pending'],
                // Réservation interrompue : son écriture n'a jamais été validée (le statut « executed » est
                // écrit dans la même transaction que les écritures), une reprise ne peut donc rien rejouer.
                ['status' => 'claimed', 'date_claim' => ['<', date('Y-m-d H:i:s', time() - self::CLAIM_TIMEOUT)]],
            ],
        ]);
        if ((int) $DB->affectedRows() !== 1) {
            return self::alreadyHandled(self::find($ctx, $uuid));
        }

        $payload = json_decode((string) $row['payload'], true) ?? [];
        $ops  = $payload['ops'] ?? [];
        $meta = $payload['meta'] ?? [];
        $tool = (string) $row['action'];
        $summary = (string) $row['summary'];

        try {
            if (empty($meta['rollback_of'])) {
                RateLimiter::checkWrite($ctx, $ops, false);
            }
            // Réglages et droits revérifiés maintenant (ils ont pu changer depuis l'aperçu)
            Permissions::assertOps($ops, !empty($meta['rollback_of']));
            $check = Operations::check($ops);
            if (!hash_equals((string) $row['preview_hash'], self::previewHash($check['preview'], Operations::basis($ops)))) {
                self::finish($claim, 'stale', 'données modifiées depuis l\'aperçu');
                AuditLog::write($ctx, $tool, 'refusé', $summary, ['erreur' => 'aperçu obsolète', 'nouvel_aperçu' => $check['preview']]);
                throw new ToolException(
                    "Les données qui fondaient l'aperçu ont changé depuis (ou tes droits ont changé). Rien n'a été modifié. "
                    . "Montre le nouvel aperçu à l'utilisateur et refais la demande pour obtenir une nouvelle confirmation.",
                    ['nouvel_aperçu' => $check['preview']]
                );
            }
            if (!empty($meta['rollback_of'])) {
                Rollback::recheck((int) $meta['rollback_of'], $ops, (array) ($meta['conflits_acceptés'] ?? []));
            }
        } catch (ToolException $e) {
            self::finish($claim, 'failed', $e->getMessage());
            if (!str_contains($e->getMessage(), "Rien n'a été modifié")) {
                throw new ToolException($e->getMessage() . " Rien n'a été modifié : l'exécution n'a pas commencé (vérification au moment de la confirmation).", $e->getDetails());
            }
            throw $e;
        }

        if ((int) $row['requires_approval'] === 1) {
            $meta['approbation'] = [
                'par' => getUserName((int) $row['approved_by']) . ' (#' . (int) $row['approved_by'] . ')',
                'le'  => $row['date_approval'],
            ];
        }
        $meta['confirmation_id'] = $uuid;

        $mark_executed = function () use ($claim): void {
            global $DB;
            $DB->update(self::TABLE, ['status' => 'executed', 'date_mod' => date('Y-m-d H:i:s')], ['claim_token' => $claim, 'status' => 'claimed']);
            if ((int) $DB->affectedRows() !== 1) {
                throw new ToolException('Réservation de la confirmation perdue : exécution abandonnée.');
            }
        };

        try {
            $out = self::run($ctx, $tool, $summary, $ops, $meta, $mark_executed);
        } catch (ToolException $e) {
            self::finish($claim, 'failed', $e->getMessage());
            throw $e;
        }
        $DB->update(self::TABLE, ['logs_id' => (int) $out['journal_id']], ['claim_token' => $claim]);
        return $out;
    }

    /** Refus explicites avant toute réservation */
    private static function assertConfirmable(Context $ctx, array $row): void
    {
        $status = (string) $row['status'];
        if ($status !== 'pending' && $status !== 'claimed') {
            return; // traité par alreadyHandled après l'échec de la réservation
        }
        if ($status === 'pending' && strtotime((string) $row['date_expiration']) < time()) {
            throw new ToolException('Confirmation expirée. Refais la demande pour obtenir un nouvel aperçu.');
        }
        if ((int) $row['tokens_id'] !== (int) ($ctx->token['id'] ?? 0)) {
            throw new ToolException('Cette proposition a été faite avec un autre jeton : elle ne peut être confirmée qu\'avec le même. Refais la demande.');
        }
        if (!hash_equals((string) $row['context_hash'], TokenScope::contextHash($ctx))) {
            throw new ToolException('Le contexte GLPI (profil ou entités actives) n\'est plus celui de l\'aperçu. Rien n\'a été modifié ; refais la demande dans le contexte voulu.');
        }
        if (!hash_equals((string) $row['payload_hash'], hash('sha256', (string) $row['payload']))) {
            throw new ToolException('Proposition altérée : refusée.');
        }
        // Politique d'approbation revérifiée : si l'administrateur l'a durcie depuis l'aperçu, elle s'applique
        if ((int) $row['requires_approval'] !== 1) {
            $payload = json_decode((string) $row['payload'], true) ?? [];
            $preview = json_decode((string) $row['preview'], true) ?? [];
            $reasons = Approval::reasons(
                Operations::canonicalize($payload['ops'] ?? []),
                $payload['meta'] ?? [],
                !empty($preview['sensible']),
                Approval::scope($ctx)
            );
            if ($reasons !== []) {
                global $DB;
                $DB->update(self::TABLE, [
                    'requires_approval' => 1,
                    'approval_reasons'  => self::encode($reasons),
                    'date_mod'          => date('Y-m-d H:i:s'),
                ], ['id' => (int) $row['id'], 'status' => 'pending', 'requires_approval' => 0]);
                $row['requires_approval'] = 1;
                $row['approved_by'] = 0;
                $row['approval_reasons'] = self::encode($reasons);
            }
        }
        if ((int) $row['requires_approval'] === 1) {
            if ((int) $row['approved_by'] === 0) {
                throw new ToolException(
                    "Approbation humaine requise : l'utilisateur doit approuver cette action lui-même dans GLPI avant toute exécution. "
                    . "Rien n'a été modifié. Donne-lui le lien, puis rappelle glpi_confirm_action quand il l'a fait.",
                    ['url_approbation' => Approval::url((string) $row['uuid']), 'raisons' => json_decode((string) $row['approval_reasons'], true)]
                );
            }
            if ((int) $row['approved_by'] !== (int) $row['users_id'] || !hash_equals((string) $row['approval_hash'], Approval::hash($row))) {
                throw new ToolException('Approbation invalide pour cette proposition (elle ne correspond pas à l\'aperçu approuvé). Refais la demande.');
            }
        }
    }

    /** Réponse quand la réservation échoue : la proposition est déjà prise en charge ou terminée. */
    private static function alreadyHandled(array $row): array
    {
        switch ($row['status']) {
            case 'executed':
                return [
                    'statut'     => 'déjà_exécutée',
                    'résumé'     => $row['summary'],
                    'journal_id' => (int) $row['logs_id'],
                    'note'       => 'Cette confirmation a déjà été utilisée : rien n\'a été rejoué.',
                ];
            case 'claimed':
                throw new ToolException('Cette confirmation est en cours d\'exécution par une autre requête : rien n\'a été rejoué. Consulte glpi_ai_history dans un instant.');
            case 'cancelled':
                throw new ToolException('Cette proposition a été annulée : rien n\'a été modifié.');
            case 'refused':
                throw new ToolException('L\'utilisateur a refusé cette action dans GLPI : rien n\'a été modifié.');
            case 'stale':
                throw new ToolException('Proposition obsolète (les données ont changé depuis l\'aperçu) : refais la demande.');
            case 'failed':
                throw new ToolException('Cette proposition a échoué (' . ($row['error'] ?: 'voir le journal') . '). Elle n\'est pas rejouée : refais la demande pour un nouvel aperçu.');
            default:
                throw new ToolException('Confirmation inutilisable (statut « ' . $row['status'] . ' »).');
        }
    }

    private static function finish(string $claim, string $status, string $error = ''): void
    {
        global $DB;
        $DB->update(self::TABLE, ['status' => $status, 'error' => mb_substr($error, 0, 2000), 'date_mod' => date('Y-m-d H:i:s')], [
            'claim_token' => $claim,
            'status'      => 'claimed',
        ]);
    }

    /**
     * Exécution + journal + marquage « via IA » dans l'historique GLPI.
     *
     * @param callable|null $before_commit appelé dans la transaction des écritures, juste avant sa validation
     */
    public static function run(Context $ctx, string $tool, string $summary, array $ops, array $meta = [], ?callable $before_commit = null): array
    {
        $rollback_of = (int) ($meta['rollback_of'] ?? 0);
        $cursor = AuditLog::historyCursor();
        $journal = null;
        $trace = array_filter([
            'confirmation_id' => $meta['confirmation_id'] ?? null,
            'approbation'     => $meta['approbation'] ?? null,
            'annulation_forcée' => !empty($meta['force']) ? true : null,
        ], fn($v) => $v !== null);

        try {
            // Réglages revérifiés à l'exécution (ils ont pu changer entre l'aperçu et la confirmation)
            Permissions::assertOps($ops, $rollback_of > 0);
            $results = Operations::execute($ops, $journal, $before_commit);
        } catch (ToolException $e) {
            AuditLog::write($ctx, $tool, 'échec', $summary, $trace + ['erreur' => $e->getMessage(), 'détails' => $e->getDetails(), 'operations' => $ops], null, $rollback_of);
            throw $e;
        }

        RateLimiter::recordWrite($ctx, $ops);
        $undoability = Operations::undoability($journal);
        $log_id = AuditLog::write($ctx, $tool, 'exécuté', $summary, $trace + [
            'operations' => $ops, 'résultats' => $results, 'annulabilité' => $undoability,
        ], $journal, $rollback_of);
        AuditLog::tagHistory(
            $cursor,
            $ctx->users_id,
            ($rollback_of ? 'annulation IA #' . $rollback_of : 'via IA') . ' : ' . ($ctx->token['name'] ?? 'assistant') . ' — journal #' . $log_id
        );
        if ($rollback_of > 0 && $log_id > 0) {
            AuditLog::markRolledBack($rollback_of, $log_id);
        }
        \GlpiPlugin\Glpimcp\Integrations\SecIncident::afterRun($ctx, $summary, $ops, $results, $log_id, $rollback_of);

        $out = [
            'statut'      => 'exécuté',
            'résumé'      => $summary,
            'journal_id'  => $log_id,
            'résultats'   => $results,
            'annulabilité' => $undoability['niveau'],
        ];
        if (isset($meta['approbation'])) {
            $out['approuvé_par'] = $meta['approbation'];
        }
        if (($journal['undo'] ?? []) !== [] && !$rollback_of) {
            $out['annulable'] = "glpi_rollback_action avec log_id={$log_id} (ou bouton « Annuler » dans le journal GLPI)"
                . ($undoability['niveau'] === 'partielle' ? ' — annulation PARTIELLE, voir non_annulable.' : '.');
        }
        if (($journal['irréversible'] ?? []) !== []) {
            $out['non_annulable'] = $journal['irréversible'];
        }
        if ($undoability['effets_externes'] !== []) {
            $out['effets_hors_base'] = $undoability['effets_externes'];
        }
        return $out;
    }

    public static function cancel(Context $ctx, string $uuid): array
    {
        global $DB;
        $row = self::find($ctx, $uuid);
        // Atomique face à une confirmation simultanée : seule une proposition encore « pending » est annulée
        $DB->update(self::TABLE, ['status' => 'cancelled', 'date_mod' => date('Y-m-d H:i:s')], ['id' => (int) $row['id'], 'status' => 'pending']);
        if ((int) $DB->affectedRows() !== 1) {
            $now = self::find($ctx, $uuid);
            if ($now['status'] === 'cancelled') {
                return ['statut' => 'annulé', 'résumé' => $now['summary']];
            }
            if ($now['status'] === 'executed' || $now['status'] === 'claimed') {
                throw new ToolException('Trop tard : cette action a déjà été confirmée' . ($now['logs_id'] ? ' (journal #' . (int) $now['logs_id'] . ')' : '')
                    . '. Pour revenir en arrière, utilise glpi_rollback_action.');
            }
            throw new ToolException('Proposition déjà close (statut « ' . $now['status'] . ' »).');
        }
        AuditLog::write($ctx, (string) $row['action'], 'annulé', (string) $row['summary'], ['raison' => "refusé par l'utilisateur à l'aperçu", 'confirmation_id' => $uuid]);
        return ['statut' => 'annulé', 'résumé' => $row['summary']];
    }

    public static function listForUser(Context $ctx): array
    {
        global $DB;
        self::purge();
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => $ctx->users_id, 'status' => 'pending'], 'ORDER' => 'id DESC']) as $row) {
            $item = [
                'confirmation_id' => $row['uuid'],
                'outil'           => $row['action'],
                'résumé'          => $row['summary'],
                'créé_le'         => $row['date_creation'],
                'expire_le'       => $row['date_expiration'],
            ];
            if ((int) $row['requires_approval'] === 1) {
                $item['approbation_humaine'] = (int) $row['approved_by'] > 0 ? 'donnée le ' . $row['date_approval'] : 'en attente : ' . Approval::url($row['uuid']);
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Proposition de l'utilisateur, quel que soit son statut */
    private static function find(Context $ctx, string $uuid): array
    {
        global $DB;
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

    /** Lecture pour la page d'approbation (contrôle d'accès fait par l'appelant) */
    public static function getByUuid(string $uuid): ?array
    {
        global $DB;
        $it = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['uuid' => $uuid], 'LIMIT' => 1]);
        return count($it) === 1 ? $it->current() : null;
    }

    /**
     * Approbation ou refus humain depuis l'interface GLPI (session authentifiée + CSRF vérifiés par l'appelant).
     */
    public static function decide(array $row, int $users_id, bool $approve): array
    {
        global $DB;
        if ((int) $row['users_id'] !== $users_id) {
            throw new ToolException('Seul l\'utilisateur pour qui l\'assistant agit peut approuver cette action.');
        }
        if ($row['status'] !== 'pending') {
            throw new ToolException('Cette proposition n\'est plus en attente (statut « ' . $row['status'] . ' »).');
        }
        if (strtotime((string) $row['date_expiration']) < time()) {
            throw new ToolException('Cette proposition a expiré : demande à l\'assistant de la refaire.');
        }
        if ((int) $row['requires_approval'] !== 1) {
            throw new ToolException('Cette action ne demande pas d\'approbation dans GLPI.');
        }
        if ((int) $row['approved_by'] > 0) {
            throw new ToolException('Cette action est déjà approuvée.');
        }
        $now = date('Y-m-d H:i:s');
        if ($approve) {
            $DB->update(self::TABLE, [
                'approved_by'   => $users_id,
                'date_approval' => $now,
                'approval_hash' => Approval::hash($row),
                'date_mod'      => $now,
            ], ['id' => (int) $row['id'], 'status' => 'pending', 'approved_by' => 0]);
        } else {
            $DB->update(self::TABLE, ['status' => 'refused', 'date_mod' => $now], ['id' => (int) $row['id'], 'status' => 'pending']);
        }
        if ((int) $DB->affectedRows() !== 1) {
            throw new ToolException('La proposition a changé pendant la décision : recharge la page.');
        }
        $ctx = new Context((int) $row['users_id'], ['id' => (int) $row['tokens_id'], 'name' => self::tokenName((int) $row['tokens_id'])]);
        AuditLog::write($ctx, (string) $row['action'], $approve ? 'approuvé' : 'refusé', (string) $row['summary'], [
            'confirmation_id' => $row['uuid'],
            'décision'        => $approve ? 'approuvée dans GLPI' : 'refusée dans GLPI',
            'par'             => getUserName($users_id) . ' (#' . $users_id . ')',
            'le'              => $now,
            'adresse_ip'      => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'raisons'         => json_decode((string) $row['approval_reasons'], true),
        ]);
        return ['statut' => $approve ? 'approuvée' : 'refusée', 'le' => $now];
    }

    private static function tokenName(int $id): string
    {
        global $DB;
        $it = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_plugin_glpimcp_tokens', 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        return count($it) ? (string) $it->current()['name'] : '';
    }

    public static function previewHash(array $preview, array $basis = []): string
    {
        return hash('sha256', self::encode(['aperçu' => $preview, 'état' => $basis]));
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function purge(): void
    {
        global $DB;
        try {
            $DB->delete(self::TABLE, ['status' => 'pending', 'date_expiration' => ['<', date('Y-m-d H:i:s')]]);
            $DB->delete(self::TABLE, ['status' => ['executed', 'failed', 'stale', 'cancelled', 'refused'], 'date_creation' => ['<', date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]]);
        } catch (Throwable) {
        }
    }
}
