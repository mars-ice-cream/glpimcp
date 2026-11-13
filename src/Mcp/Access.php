<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Config;
use Group_User;
use Profile_User;
use Session;

/**
 * Qui a le droit d'utiliser l'assistant IA (Administration > Assistant IA (MCP) > Accès).
 *
 *  - Mode « tous » : tout compte GLPI peut connecter un assistant (comportement historique).
 *  - Mode « liste » : seulement les comptes autorisés nommément, membres d'un groupe autorisé ou ayant un
 *    profil autorisé (ex. Technicien) ; les autres ne peuvent ni créer de jeton ni se connecter par OAuth,
 *    et leurs jetons existants sont refusés.
 *  - Comptes bloqués : refusés quel que soit le mode, même administrateurs ; leurs jetons et accès OAuth
 *    sont révoqués au moment du blocage.
 *
 * Vérifié à chaque requête MCP, à la création d'un jeton, à la connexion OAuth et à chaque rafraîchissement.
 * Ne touche jamais à l'accès à GLPI lui-même.
 */
final class Access
{
    public const MODE_ALL = 'all';
    public const MODE_LIST = 'allowlist';

    /** @var array<int, ?string> */
    private static array $cache = [];

    /** @return array{mode: string, users: int[], groups: int[], profiles: int[], blocked: int[]} */
    public static function settings(): array
    {
        $cfg = Config::getConfigurationValues('plugin:glpimcp', ['access_mode', 'access_users', 'access_groups', 'access_profiles', 'blocked_users']);
        return [
            'mode'     => ($cfg['access_mode'] ?? '') === self::MODE_LIST ? self::MODE_LIST : self::MODE_ALL,
            'users'    => self::ids($cfg['access_users'] ?? '[]'),
            'groups'   => self::ids($cfg['access_groups'] ?? '[]'),
            'profiles' => self::ids($cfg['access_profiles'] ?? '[]'),
            'blocked'  => self::ids($cfg['blocked_users'] ?? '[]'),
        ];
    }

    /** @return int[] */
    public static function ids(mixed $json): array
    {
        $list = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $list), fn($i) => $i > 0)));
    }

    /**
     * Décision pure (testable). Renvoie la raison du refus, ou null si l'accès est permis.
     *
     * @param int[] $groups   groupes du compte
     * @param int[] $profiles profils du compte
     */
    public static function decide(array $s, int $users_id, array $groups, array $profiles): ?string
    {
        if (in_array($users_id, $s['blocked'], true)) {
            return 'accès à l\'assistant IA bloqué pour ce compte par l\'administrateur GLPI';
        }
        if ($s['mode'] !== self::MODE_LIST) {
            return null;
        }
        if (
            in_array($users_id, $s['users'], true)
            || array_intersect(array_map('intval', $groups), $s['groups']) !== []
            || array_intersect(array_map('intval', $profiles), $s['profiles']) !== []
        ) {
            return null;
        }
        return 'ce compte n\'est pas autorisé à utiliser l\'assistant IA (accès réservé par l\'administrateur GLPI)';
    }

    /** Raison du refus pour ce compte, ou null */
    public static function check(int $users_id): ?string
    {
        if (array_key_exists($users_id, self::$cache)) {
            return self::$cache[$users_id];
        }
        $s = self::settings();
        $groups = $s['mode'] === self::MODE_LIST && $s['groups'] !== [] ? array_map(fn($g) => (int) $g['id'], Group_User::getUserGroups($users_id)) : [];
        $profiles = $s['mode'] === self::MODE_LIST && $s['profiles'] !== [] ? array_map('intval', Profile_User::getUserProfiles($users_id)) : [];
        return self::$cache[$users_id] = self::decide($s, $users_id, $groups, $profiles);
    }

    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Révoque tous les jetons et accès OAuth d'un compte, et les codes d'autorisation en attente.
     * @return int jetons révoqués
     */
    public static function revokeAll(int $users_id): int
    {
        global $DB;
        $DB->update('glpi_plugin_glpimcp_tokens', ['is_active' => 0], ['users_id' => $users_id, 'is_active' => 1]);
        $n = (int) $DB->affectedRows();
        if ($DB->tableExists('glpi_plugin_glpimcp_oauthcodes')) {
            $DB->delete('glpi_plugin_glpimcp_oauthcodes', ['users_id' => $users_id]);
        }
        return $n;
    }

    /** Bloque ou débloque un compte ; le blocage révoque aussitôt ses jetons */
    public static function setBlocked(int $users_id, bool $blocked): int
    {
        $s = self::settings();
        $list = $blocked ? array_values(array_unique([...$s['blocked'], $users_id])) : array_values(array_diff($s['blocked'], [$users_id]));
        Config::setConfigurationValues('plugin:glpimcp', ['blocked_users' => json_encode($list)]);
        self::reset();
        $revoked = $blocked ? self::revokeAll($users_id) : 0;
        AuditLog::write(
            new Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']),
            'plugin_settings', 'exécuté',
            ($blocked ? 'Assistant IA : accès bloqué pour ' : 'Assistant IA : accès débloqué pour ') . getUserName($users_id),
            ['users_id' => $users_id, 'jetons_révoqués' => $revoked]
        );
        return $revoked;
    }

    /** Enregistre les réglages d'accès ; les comptes nouvellement bloqués voient leurs jetons révoqués */
    public static function save(array $new): array
    {
        $before = self::settings();
        $clean = [
            'access_mode'     => ($new['mode'] ?? '') === self::MODE_LIST ? self::MODE_LIST : self::MODE_ALL,
            'access_users'    => json_encode(self::ids($new['users'] ?? [])),
            'access_groups'   => json_encode(self::ids($new['groups'] ?? [])),
            'access_profiles' => json_encode(self::ids($new['profiles'] ?? [])),
            'blocked_users'   => json_encode(self::ids($new['blocked'] ?? [])),
        ];
        Config::setConfigurationValues('plugin:glpimcp', $clean);
        self::reset();
        $after = self::settings();
        $revoked = 0;
        foreach (array_diff($after['blocked'], $before['blocked']) as $uid) {
            $revoked += self::revokeAll((int) $uid);
        }
        if ($before !== $after) {
            AuditLog::write(
                new Context((int) Session::getLoginUserID(), ['id' => 0, 'name' => 'Interface GLPI']),
                'plugin_settings', 'exécuté', 'Assistant IA : réglages d\'accès modifiés',
                ['avant' => $before, 'après' => $after, 'jetons_révoqués' => $revoked]
            );
        }
        return ['settings' => $after, 'revoked' => $revoked];
    }
}
