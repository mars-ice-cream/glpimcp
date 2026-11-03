<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

/**
 * Approbation humaine vérifiée dans GLPI.
 *
 * glpi_confirm_action est un second appel de l'assistant : il ne prouve pas qu'un humain a vu l'aperçu.
 * Pour les opérations critiques, l'exécution exige en plus une approbation donnée dans l'interface GLPI
 * (session GLPI authentifiée du même utilisateur, jeton CSRF), liée par empreinte à la proposition exacte
 * et à son contexte. Le jeton MCP et le confirmation_id seuls ne suffisent jamais.
 *
 * Portée (réglage approval_scope) :
 *  - critical (défaut) : suppressions définitives, comptes/groupes/habilitations, droits des profils,
 *    configuration générale (y compris les réglages de ce plugin), annulations forcées malgré des conflits ;
 *  - sensitive : en plus, toute opération marquée « sensible » à l'aperçu (corbeille, règles, entités, SLA,
 *    notifications, fusions de logiciels…).
 */
final class Approval
{
    public const SCOPES = ['critical', 'sensitive'];

    /** @return string[] raisons (vide = pas d'approbation humaine requise) */
    public static function reasons(array $ops, array $meta, bool $sensitive_preview, string $scope): array
    {
        $rollback = !empty($meta['rollback_of']);
        $labels = [
            'purge'        => 'Suppression définitive (irréversible)',
            'users_groups' => 'Comptes, groupes ou habilitations des utilisateurs',
            'rights'       => 'Droits des profils',
            'config'       => 'Configuration générale de GLPI ou de ce plugin',
        ];
        $reasons = [];
        foreach ($ops as $op) {
            foreach (Permissions::categoriesOf($op) as $cat) {
                // Annuler une création de l'IA purge l'élément créé : ce n'est critique que si l'annulation est forcée
                if ($cat === 'purge' && $rollback && empty($meta['force'])) {
                    continue;
                }
                if (isset($labels[$cat])) {
                    $reasons[$cat] = $labels[$cat];
                }
            }
        }
        if ($rollback && !empty($meta['force'])) {
            $reasons['force'] = 'Annulation forcée malgré des modifications faites depuis l\'action IA';
        }
        if ($scope === 'sensitive' && $sensitive_preview) {
            $reasons['sensitive'] = 'Opération sensible (politique de l\'administrateur : approbation de toutes les opérations sensibles)';
        }
        return array_values($reasons);
    }

    public static function scope(Context $ctx): string
    {
        $s = (string) $ctx->config('approval_scope', 'critical');
        return in_array($s, self::SCOPES, true) ? $s : 'critical';
    }

    public static function describeScope(Context $ctx): string
    {
        return self::scope($ctx) === 'sensitive'
            ? 'exigée dans GLPI pour toutes les opérations sensibles'
            : 'exigée dans GLPI pour les suppressions définitives, comptes/groupes, droits des profils, configuration et annulations forcées';
    }

    public static function url(string $uuid): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/glpimcp/front/approval.php?uuid=' . rawurlencode($uuid);
    }

    /** Empreinte qui lie l'approbation à la proposition exacte et à son contexte. */
    public static function hash(array $row): string
    {
        return hash('sha256', implode('|', [
            $row['uuid'], (int) $row['users_id'], (int) $row['tokens_id'], (int) $row['profiles_id'],
            $row['context_hash'], $row['payload_hash'], $row['preview_hash'],
        ]));
    }
}
