<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Profile_User;
use Session;
use User;

/**
 * Périmètre d'un jeton MCP : le profil et les entités accordés à sa création (ou au consentement OAuth)
 * sont une LIMITE. Ni l'en-tête X-GLPI-Profile / X-GLPI-Entity, ni glpi_switch_context, ni un relais ne
 * peuvent l'élargir, même si le compte GLPI possède un profil ou des entités en plus.
 *
 * - profiles_id   : profil accordé (toujours > 0 depuis 1.4.0).
 * - entities_id   : entité accordée (NULL = toutes les entités que le profil donne au compte).
 * - is_recursive  : sous-entités comprises dans l'autorisation.
 * - context_*     : entité de travail choisie par glpi_switch_context, toujours à l'intérieur de la limite.
 *
 * Les droits GLPI restent la seconde limite : un profil ou une entité retirés au compte après la création
 * du jeton rendent le jeton inutilisable (ou réduisent son périmètre), jamais l'inverse.
 */
final class TokenScope
{
    /** Entités autorisées (identifiants) pour ce jeton, profil accordé déjà actif dans la session. */
    public static function allowedEntities(array $token): array
    {
        $profile_set = [];
        foreach ($_SESSION['glpiactiveprofile']['entities'] ?? [] as $e) {
            $profile_set[(int) $e['id']] = true;
            if (!empty($e['is_recursive'])) {
                foreach (getSonsOf('glpi_entities', (int) $e['id']) as $son) {
                    $profile_set[(int) $son] = true;
                }
            }
        }
        if ($token['entities_id'] === null || $token['entities_id'] === '') {
            return array_keys($profile_set);
        }
        $grant = [(int) $token['entities_id'] => true];
        if (!empty($token['is_recursive'])) {
            foreach (getSonsOf('glpi_entities', (int) $token['entities_id']) as $son) {
                $grant[(int) $son] = true;
            }
        }
        return array_keys(array_intersect_key($grant, $profile_set));
    }

    /**
     * Applique le périmètre à la session MCP qui vient d'être ouverte.
     *
     * @throws ScopeException refus explicite (jamais de repli silencieux)
     */
    public static function apply(array $token, ?string $header_profile, ?string $header_entity): void
    {
        $granted = (int) $token['profiles_id'];
        if ($granted <= 0) {
            throw new ScopeException('Jeton sans profil accordé (ancien jeton non migré) : recrée un jeton dans GLPI > Mes préférences > Assistant IA.');
        }
        if (!isset($_SESSION['glpiprofiles'][$granted])) {
            throw new ScopeException('Le profil accordé à ce jeton n\'est plus attribué à ce compte GLPI : le jeton ne peut plus être utilisé.');
        }
        if ($header_profile !== null && $header_profile !== '' && (int) $header_profile !== $granted) {
            throw new ScopeException(sprintf(
                'X-GLPI-Profile refusé : ce jeton est limité au profil « %s » (#%d). Crée un autre jeton pour un autre profil.',
                \Dropdown::getDropdownName('glpi_profiles', $granted),
                $granted
            ));
        }
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== $granted) {
            Session::changeProfile($granted);
        }
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) !== $granted) {
            throw new ScopeException('Impossible d\'activer le profil accordé à ce jeton (aucune entité attribuée pour ce profil).');
        }

        $allowed = self::allowedEntities($token);
        if ($allowed === []) {
            throw new ScopeException('L\'entité accordée à ce jeton n\'est plus accessible avec son profil : le jeton ne peut plus être utilisé.');
        }

        // Entité de travail : en-tête > contexte choisi > entité accordée > défaut du profil
        $desired = null;
        if ($header_entity !== null && $header_entity !== '') {
            if (!preg_match('/^\d+$/', $header_entity)) {
                throw new ScopeException('X-GLPI-Entity invalide (identifiant numérique attendu).');
            }
            $desired = (int) $header_entity;
            if (!in_array($desired, $allowed, true)) {
                throw new ScopeException("X-GLPI-Entity refusé : l'entité #{$desired} est hors du périmètre accordé à ce jeton.");
            }
        } elseif ($token['context_entities_id'] !== null && $token['context_entities_id'] !== '') {
            $desired = (int) $token['context_entities_id'];
            if (!in_array($desired, $allowed, true)) {
                // Contexte devenu hors périmètre (droits retirés) : on n'élargit pas, on se replie sur la limite
                $desired = null;
            }
        }
        if ($desired === null && $token['entities_id'] !== null && $token['entities_id'] !== '') {
            $desired = (int) $token['entities_id'];
        }

        if ($desired !== null) {
            $want_recursive = $token['context_is_recursive'] !== null && $token['context_is_recursive'] !== ''
                ? (bool) $token['context_is_recursive']
                : (bool) $token['is_recursive'];
            // Récursif seulement si toutes les sous-entités sont dans la limite
            $recursive = $want_recursive
                && array_diff(array_map('intval', getSonsOf('glpi_entities', $desired)), $allowed) === [];
            if (!Session::changeActiveEntities($desired, $recursive)) {
                throw new ScopeException("Impossible d'activer l'entité #{$desired}.");
            }
        }

        // Garde-fou final : la session ne doit couvrir aucune entité hors limite
        $active = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        $outside = array_diff($active, $allowed);
        if ($outside !== []) {
            $first = (int) $token['entities_id'] ?: $allowed[0];
            if (!Session::changeActiveEntities($first, false) || array_diff(array_map('intval', $_SESSION['glpiactiveentities'] ?? []), $allowed) !== []) {
                throw new ScopeException('Périmètre d\'entités incohérent : accès refusé.');
            }
        }
    }

    /** Empreinte du contexte GLPI effectif (lie une proposition au contexte de son aperçu). */
    public static function contextHash(Context $ctx): string
    {
        $entities = array_map('intval', $_SESSION['glpiactiveentities'] ?? []);
        sort($entities);
        return hash('sha256', json_encode([
            'users_id'  => $ctx->users_id,
            'tokens_id' => (int) ($ctx->token['id'] ?? 0),
            'profiles_id' => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
            'entities'  => $entities,
        ]));
    }

    /** Profil accordé à un nouveau jeton : profil demandé s'il appartient au compte, sinon profil par défaut. */
    public static function resolveProfile(int $users_id, int $requested): int
    {
        $owned = array_values(array_map('intval', Profile_User::getUserProfiles($users_id)));
        if ($requested > 0) {
            return in_array($requested, $owned, true) ? $requested : 0;
        }
        $user = new User();
        if ($user->getFromDB($users_id) && in_array((int) $user->fields['profiles_id'], $owned, true)) {
            return (int) $user->fields['profiles_id'];
        }
        return count($owned) === 1 ? $owned[0] : 0;
    }

    /**
     * Migration 1.4.0 : les jetons « profil par défaut » (profiles_id = 0) suivaient le profil par défaut
     * du compte et acceptaient tout X-GLPI-Profile. On les fige sur le profil par défaut actuel (ou le profil
     * unique du compte). Si aucun profil ne peut être déterminé, le jeton est désactivé.
     */
    public static function migrateLegacyTokens(): array
    {
        global $DB;
        $report = ['fixés' => 0, 'désactivés' => 0];
        foreach ($DB->request(['FROM' => 'glpi_plugin_glpimcp_tokens', 'WHERE' => ['profiles_id' => 0, 'is_active' => 1]]) as $row) {
            $pid = self::resolveProfile((int) $row['users_id'], 0);
            if ($pid > 0) {
                $DB->update('glpi_plugin_glpimcp_tokens', ['profiles_id' => $pid], ['id' => $row['id']]);
                $report['fixés']++;
            } else {
                $DB->update('glpi_plugin_glpimcp_tokens', ['is_active' => 0], ['id' => $row['id']]);
                $report['désactivés']++;
            }
        }
        return $report;
    }
}
