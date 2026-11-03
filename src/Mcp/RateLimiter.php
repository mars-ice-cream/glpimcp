<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Config;
use QueuedNotification;
use Throwable;

/**
 * Garde-fous contre l'abus d'un jeton (fuite, script emballé) : valables pour TOUS les jetons.
 *
 *  - requêtes MCP par minute et par jeton (HTTP 429 + Retry-After au-delà) ;
 *  - écritures par heure et par utilisateur (propositions comprises : un flot d'aperçus est aussi un abus) ;
 *  - tickets créés par heure et par utilisateur (création directe ou via formulaire) ;
 *  - écritures par heure pour l'ensemble d'un client IA géré (ex. Copilot de l'entreprise) ;
 *  - au-delà de rl_suspend_after refus en 10 minutes, le jeton est SUSPENDU et l'administrateur alerté.
 *
 * Limites effectives : surcharge du jeton > politique du client IA > réglages globaux. 0 = pas de limite.
 * Compteurs à fenêtre fixe en base, incrémentés atomiquement (INSERT … ON DUPLICATE KEY UPDATE).
 */
final class RateLimiter
{
    private const TABLE = 'glpi_plugin_glpimcp_counters';

    /**
     * Incrémente le compteur de la fenêtre courante et renvoie SA valeur après cet incrément, de façon
     * atomique (UPDATE … LAST_INSERT_ID(expr) : la valeur lue est celle écrite par cette requête, même
     * en cas d'appels simultanés). $by peut être négatif (libération d'une réservation).
     */
    public static function hit(string $key, int $window, int $by = 1): int
    {
        global $DB;
        $win = intdiv(time(), $window);
        $k = $DB->escape(mb_substr($key, 0, 100));
        $exp = ($win + 1) * $window;
        $table = self::TABLE;
        $DB->doQuery("INSERT IGNORE INTO `{$table}` (`k`, `win`, `n`, `exp`) VALUES ('{$k}', {$win}, 0, {$exp})");
        $DB->doQuery("UPDATE `{$table}` SET `n` = LAST_INSERT_ID(GREATEST(0, CAST(`n` AS SIGNED) + ({$by}))) WHERE `k` = '{$k}' AND `win` = {$win}");
        $value = (int) ($DB->doQuery('SELECT LAST_INSERT_ID() AS v')->fetch_assoc()['v'] ?? 0);
        if (random_int(1, 200) === 1) {
            $DB->delete($table, ['exp' => ['<', time() - 60]]);
        }
        return $value;
    }

    public static function peek(string $key, int $window): int
    {
        global $DB;
        $it = $DB->request(['SELECT' => ['n'], 'FROM' => self::TABLE, 'WHERE' => ['k' => mb_substr($key, 0, 100), 'win' => intdiv(time(), $window)]]);
        return count($it) ? (int) $it->current()['n'] : 0;
    }

    private static function retryAfter(int $window): int
    {
        return $window - (time() % $window);
    }

    /** @return array{requests_per_minute:int, writes_per_hour:int, tickets_per_hour:int, client_writes_per_hour:int} */
    public static function limits(array $token): array
    {
        $cfg = Config::getConfigurationValues('plugin:glpimcp', ['rl_requests_per_minute', 'rl_writes_per_hour', 'rl_tickets_per_hour']);
        $client = self::client($token);
        $pick = function (string $k, int $default) use ($token, $client, $cfg): int {
            foreach ([$token[$k] ?? null, $client[$k] ?? null, $cfg[$k] ?? null] as $v) {
                if ($v !== null && $v !== '') {
                    return max(0, (int) $v);
                }
            }
            return $default;
        };
        return [
            'requests_per_minute'    => $pick('rl_requests_per_minute', 600),
            'writes_per_hour'        => $pick('rl_writes_per_hour', 200),
            'tickets_per_hour'       => $pick('rl_tickets_per_hour', 20),
            'client_writes_per_hour' => max(0, (int) ($client['rl_client_writes_per_hour'] ?? 0)),
        ];
    }

    private static function client(array $token): ?array
    {
        static $cache = [];
        $id = (int) ($token['oauthclients_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, $cache)) {
            global $DB;
            $it = $DB->request(['FROM' => 'glpi_plugin_glpimcp_oauthclients', 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
            $cache[$id] = count($it) ? $it->current() : null;
        }
        return $cache[$id];
    }

    /** À chaque requête MCP authentifiée. @throws RateLimitException */
    public static function checkRequest(array $token): void
    {
        $limit = self::limits($token)['requests_per_minute'];
        if ($limit <= 0) {
            return;
        }
        if (self::hit('req:t' . (int) $token['id'], 60) > $limit) {
            self::rejected($token, "plus de {$limit} requêtes par minute");
            throw new RateLimitException("Trop de requêtes : limite de {$limit} par minute pour ce jeton.", self::retryAfter(60));
        }
    }

    /**
     * Avant une proposition ou une exécution d'écriture : refus si un quota serait dépassé.
     * $count = true compte cette écriture (à l'aperçu) ; les tickets sont comptés à l'exécution.
     * @throws ToolException
     */
    public static function checkWrite(Context $ctx, array $ops, bool $count): void
    {
        $l = self::limits($ctx->token);
        $tickets = self::estimateTickets($ops);
        if ($l['writes_per_hour'] > 0) {
            $n = $count ? self::hit('w:u' . $ctx->users_id, 3600) : self::peek('w:u' . $ctx->users_id, 3600);
            if ($n > $l['writes_per_hour']) {
                self::rejected($ctx->token, "plus de {$l['writes_per_hour']} écritures par heure");
                throw new ToolException("Quota atteint : {$l['writes_per_hour']} écritures par heure pour ce compte. Réessaie dans " . ceil(self::retryAfter(3600) / 60) . ' min.', ['quota' => 'écritures/heure', 'réessayer_dans_s' => self::retryAfter(3600)]);
            }
        }
        // Vérification indicative (l'aperçu) ; la réservation atomique se fait à l'exécution (reserveTickets)
        if ($tickets > 0 && $l['tickets_per_hour'] > 0 && self::peek('tk:u' . $ctx->users_id, 3600) + $tickets > $l['tickets_per_hour']) {
            self::rejected($ctx->token, "plus de {$l['tickets_per_hour']} tickets par heure");
            throw self::ticketQuota($l['tickets_per_hour']);
        }
        $cid = (int) ($ctx->token['oauthclients_id'] ?? 0);
        if ($cid > 0 && $l['client_writes_per_hour'] > 0) {
            $n = $count ? self::hit('w:c' . $cid, 3600) : self::peek('w:c' . $cid, 3600);
            if ($n > $l['client_writes_per_hour']) {
                self::alertOnce('client' . $cid, 'Quota global du client IA atteint', ['client' => $cid, 'limite_par_heure' => $l['client_writes_per_hour']]);
                throw new ToolException("Quota global de l'assistant de l'entreprise atteint ({$l['client_writes_per_hour']} écritures par heure). L'administrateur a été prévenu.", ['quota' => 'client/heure']);
            }
        }
    }

    private static function ticketQuota(int $limit): ToolException
    {
        return new ToolException("Quota atteint : {$limit} tickets par heure pour ce compte. Réessaie dans " . ceil(self::retryAfter(3600) / 60) . ' min.', ['quota' => 'tickets/heure', 'réessayer_dans_s' => self::retryAfter(3600)]);
    }

    /**
     * Réserve atomiquement, avant l'exécution, les tickets que le lot peut créer. Deux exécutions
     * simultanées ne peuvent pas passer toutes les deux au-delà du quota. Renvoie le nombre réservé.
     * @throws ToolException
     */
    public static function reserveTickets(Context $ctx, array $ops): int
    {
        $n = self::estimateTickets($ops);
        if ($n <= 0) {
            return 0;
        }
        $limit = self::limits($ctx->token)['tickets_per_hour'];
        $after = self::hit('tk:u' . $ctx->users_id, 3600, $n);
        if ($limit > 0 && $after > $limit) {
            self::hit('tk:u' . $ctx->users_id, 3600, -$n);
            self::rejected($ctx->token, "plus de {$limit} tickets par heure");
            throw self::ticketQuota($limit);
        }
        return $n;
    }

    /** Ajuste la réservation au nombre réellement créé (0 en cas d'échec : réservation libérée). */
    public static function settleTickets(Context $ctx, int $reserved, int $actual): void
    {
        if ($actual !== $reserved) {
            self::hit('tk:u' . $ctx->users_id, 3600, $actual - $reserved);
        }
    }

    /**
     * Majorant des objets ITIL créés par un lot : créations directes de tickets/changements/problèmes,
     * et pour une soumission de formulaire, le nombre de ses destinations (chacune peut créer un objet).
     */
    public static function estimateTickets(array $ops): int
    {
        $n = 0;
        foreach ($ops as $op) {
            $kind = (string) ($op['op'] ?? '');
            $type = ltrim((string) ($op['itemtype'] ?? ''), '\\');
            if ($kind === 'add' && in_array($type, self::ITIL, true)) {
                $n++;
            } elseif ($kind === 'form_submit') {
                $n += max(1, self::formDestinations((int) ($op['forms_id'] ?? 0)));
            }
        }
        return $n;
    }

    /** Objets ITIL réellement créés, d'après les résultats d'exécution */
    public static function actualTickets(array $ops, array $results): int
    {
        $n = 0;
        foreach ($ops as $i => $op) {
            $kind = (string) ($op['op'] ?? '');
            $type = ltrim((string) ($op['itemtype'] ?? ''), '\\');
            if ($kind === 'add' && in_array($type, self::ITIL, true)) {
                $n++;
            } elseif ($kind === 'form_submit') {
                foreach ($results[$i]['objets_créés'] ?? [] as $obj) {
                    if (in_array(ltrim((string) ($obj['itemtype'] ?? ''), '\\'), self::ITIL, true)) {
                        $n++;
                    }
                }
            }
        }
        return $n;
    }

    private const ITIL = ['Ticket', 'Change', 'Problem'];

    private static function formDestinations(int $forms_id): int
    {
        if ($forms_id <= 0 || !class_exists(\Glpi\Form\Destination\FormDestination::class)) {
            return 1;
        }
        return countElementsInTable(\Glpi\Form\Destination\FormDestination::getTable(), ['forms_forms_id' => $forms_id]);
    }

    /** @deprecated garde la compatibilité des tests unitaires */
    public static function ticketsIn(array $ops): int
    {
        $n = 0;
        foreach ($ops as $op) {
            $type = ltrim((string) ($op['itemtype'] ?? ''), '\\');
            if ((($op['op'] ?? '') === 'add' && in_array($type, self::ITIL, true)) || ($op['op'] ?? '') === 'form_submit') {
                $n++;
            }
        }
        return $n;
    }

    /** Refus comptés ; au-delà du seuil, suspension du jeton et alerte. */
    private static function rejected(array $token, string $what): void
    {
        $threshold = (int) (Config::getConfigurationValues('plugin:glpimcp', ['rl_suspend_after'])['rl_suspend_after'] ?? 50);
        if ($threshold <= 0 || empty($token['id'])) {
            return;
        }
        if (self::hit('rej:t' . (int) $token['id'], 600) === $threshold) {
            self::suspend($token, "Suspension automatique : {$threshold} refus en 10 minutes ({$what})");
        }
    }

    public static function suspend(array $token, string $reason): void
    {
        global $DB;
        try {
            $DB->update('glpi_plugin_glpimcp_tokens', [
                'is_active' => 0, 'suspended_reason' => mb_substr($reason, 0, 1000), 'date_suspended' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $token['id']]);
            AuditLog::write(new Context((int) $token['users_id'], $token), 'garde-fou', 'suspendu', $reason, [
                'jeton' => $token['name'] ?? '', 'adresse_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
            self::mail('[GLPI MCP] Jeton suspendu : ' . ($token['name'] ?? '#' . $token['id']), [
                'Jeton'       => ($token['name'] ?? '') . ' (#' . (int) $token['id'] . ')',
                'Compte'      => getUserName((int) $token['users_id']),
                'Raison'      => $reason,
                'Adresse IP'  => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'Que faire'   => 'Vérifier l\'activité dans le journal IA ; si le jeton a fuité, le laisser révoqué et en créer un autre.',
            ]);
        } catch (Throwable $e) {
            trigger_error('glpimcp suspension: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** Une alerte au plus par heure pour une même clé */
    private static function alertOnce(string $key, string $subject, array $details): void
    {
        if (self::hit('alert:' . $key, 3600) === 1) {
            self::mail('[GLPI MCP] ' . $subject, $details);
        }
    }

    private static function mail(string $subject, array $details): void
    {
        $cfg = Config::getConfigurationValues('plugin:glpimcp', ['alert_emails']);
        $emails = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($cfg['alert_emails'] ?? '')) ?: []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($emails === []) {
            $admin = Config::getAdminEmailSender();
            if (!empty($admin['email'])) {
                $emails = [$admin['email']];
            }
        }
        $text = $subject . "\n\n";
        foreach ($details as $k => $v) {
            $text .= $k . ' : ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . "\n";
        }
        $sender = Config::getAdminEmailSender();
        foreach (array_unique($emails) as $email) {
            (new QueuedNotification())->add([
                'itemtype' => '', 'items_id' => 0, 'notificationtemplates_id' => 0, 'entities_id' => 0,
                'name' => $subject, 'sender' => $sender['email'] ?? '', 'sendername' => $sender['name'] ?? 'GLPI',
                'recipient' => $email, 'recipientname' => $email,
                'body_text' => $text, 'body_html' => '<pre style="font-family:sans-serif;white-space:pre-wrap">' . htmlescape($text) . '</pre>',
                'messageid' => '<glpimcp.' . bin2hex(random_bytes(8)) . '@' . (gethostname() ?: 'glpi') . '>',
                'mode' => 'mailing',
            ]);
        }
    }
}
