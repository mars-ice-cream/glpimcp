<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\OAuth;

use Config;
use Profile_User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Politique d'un client IA « géré par l'entreprise » (ex. l'agent Copilot Studio de la société).
 *
 * Un client enregistré automatiquement (DCR) est « personnel » : chaque utilisateur voit la page de
 * consentement et choisit son profil. Un administrateur GLPI peut le passer en « géré » :
 *  - profil imposé (ex. Self-Service) : l'accès n'est accordé qu'aux comptes qui ont ce profil ;
 *  - consentement implicite : après la connexion GLPI (SSO AD), l'accès est accordé sans page à cliquer ;
 *  - durée des accès, adresses IP autorisées, quotas propres au client.
 * Rien de cela n'étend les droits : le profil imposé reste celui du compte, les garde-fous s'appliquent.
 */
final class ClientPolicy
{
    public static function isManaged(array $client): bool
    {
        return !empty($client['managed']);
    }

    /** Profil imposé si l'utilisateur le possède, sinon null (accès refusé) */
    public static function forcedProfileFor(array $client, int $users_id): ?int
    {
        $pid = (int) ($client['forced_profiles_id'] ?? 0);
        if ($pid <= 0) {
            return null;
        }
        return in_array($pid, array_map('intval', Profile_User::getUserProfiles($users_id)), true) ? $pid : null;
    }

    /** Le client accorde-t-il actuellement l'accès sans page de consentement ? */
    public static function grantsImplicitly(array $client): bool
    {
        return self::isManaged($client) && !empty($client['skip_consent']);
    }

    /**
     * Un accès OAuth déjà accordé respecte-t-il la politique ACTUELLE du client ?
     * Renvoie la raison du refus, ou null. Vérifié à chaque requête MCP, à chaque rafraîchissement et à
     * l'échange d'un code : retirer le statut « géré », le consentement implicite, changer le profil imposé
     * ou raccourcir la durée coupe les accès qui ne la respectent plus, sans attendre leur expiration.
     *
     * @param array $grant ligne de jeton (ou de code) : profiles_id, grant_mode, date_creation
     */
    public static function grantViolation(array $client, array $grant, ?int $now = null): ?string
    {
        $now ??= time();
        if (empty($client['is_active'])) {
            return 'client IA désactivé par l\'administrateur';
        }
        if (($grant['grant_mode'] ?? 'consent') === 'implicit' && !self::grantsImplicitly($client)) {
            return 'accès accordé sans consentement, mode retiré depuis par l\'administrateur';
        }
        if (self::isManaged($client) && (int) ($grant['profiles_id'] ?? 0) !== (int) ($client['forced_profiles_id'] ?? 0)) {
            return 'profil imposé par l\'administrateur différent de celui de l\'accès';
        }
        $days = (int) ($client['token_days'] ?? 0);
        if (
            self::isManaged($client) && $days > 0 && !empty($grant['date_creation'])
            && strtotime((string) $grant['date_creation']) + $days * 86400 < $now
        ) {
            return 'durée d\'accès fixée par l\'administrateur dépassée';
        }
        return null;
    }

    /** IP du client : REMOTE_ADDR, ou X-Forwarded-For seulement derrière un proxy déclaré de confiance */
    public static function clientIp(Request $request): string
    {
        $remote = (string) $request->server->get('REMOTE_ADDR', '');
        $trusted = self::cidrs((string) (Config::getConfigurationValues('plugin:glpimcp', ['trusted_proxies'])['trusted_proxies'] ?? ''));
        if ($trusted !== [] && self::inAny($remote, $trusted)) {
            $chain = array_reverse(array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For', ''))));
            foreach ($chain as $ip) {
                if ($ip !== '' && !self::inAny($ip, $trusted)) {
                    return $ip;
                }
            }
        }
        return $remote;
    }

    public static function ipAllowed(?array $client, string $ip): bool
    {
        if ($client === null) {
            return true;
        }
        $list = self::cidrs((string) ($client['allowed_ips'] ?? ''));
        return $list === [] || self::inAny($ip, $list);
    }

    /** @return string[] */
    public static function cidrs(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $c) {
            $c = trim($c);
            if ($c !== '' && self::validCidr($c)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    public static function validCidr(string $c): bool
    {
        [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return $bits === null || (ctype_digit($bits) && (int) $bits <= $max);
    }

    public static function inAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $c) {
            if (self::inCidr($ip, $c)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($a[$bytes] & $mask) === ($b[$bytes] & $mask));
    }
}
