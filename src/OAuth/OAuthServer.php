<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\OAuth;

use Config;
use GlpiPlugin\Glpimcp\Token;
use Profile_User;

/**
 * Serveur d'autorisation OAuth 2.1 minimal pour les clients MCP distants
 * (claude.ai, ChatGPT, Mistral Le Chat, Claude Desktop/Code via OAuth…).
 *
 *  - Découverte : RFC 9728 (protected resource metadata) + RFC 8414 (authorization server metadata)
 *  - Enregistrement dynamique des clients : RFC 7591
 *  - Client ID Metadata Documents (client_id = URL https)
 *  - Code d'autorisation + PKCE S256 obligatoire, jetons de rafraîchissement tournants
 *
 * Le consentement se fait dans GLPI, avec la session GLPI de l'utilisateur :
 * le jeton émis porte exactement ses droits (profil choisi), comme un jeton personnel.
 */
final class OAuthServer
{
    public const CLIENTS = 'glpi_plugin_glpimcp_oauthclients';
    public const CODES   = 'glpi_plugin_glpimcp_oauthcodes';
    public const SCOPE   = 'glpi';

    public static function base(): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/glpimcp';
    }

    public static function resource(): string
    {
        return self::base() . '/mcp';
    }

    /** Chemin (sans hôte) de GLPI, ex : "" ou "/glpi" */
    public static function basePath(): string
    {
        global $CFG_GLPI;
        $path = parse_url((string) ($CFG_GLPI['url_base'] ?? ''), PHP_URL_PATH) ?: '';
        return rtrim($path, '/');
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $cfg = Config::getConfigurationValues('plugin:glpimcp', [$key]);
        return $cfg[$key] ?? $default;
    }

    /**
     * Serveurs relais autorisés (ex. Dokore) : un client peut demander un jeton pour eux (paramètre resource).
     * Le relais transmet ensuite ce jeton au socle, qui reste seul juge des droits.
     *
     * @return string[]
     */
    public static function trustedResources(): array
    {
        $raw = json_decode((string) self::config('oauth_trusted_resources', '[]'), true);
        return array_values(array_filter(array_map(fn($u) => rtrim(trim((string) $u), '/'), is_array($raw) ? $raw : [])));
    }

    /** Ressource acceptée : ce serveur MCP ou un relais autorisé */
    public static function resourceAllowed(string $resource): bool
    {
        $r = rtrim($resource, '/');
        return $r === rtrim(self::resource(), '/') || in_array($r, self::trustedResources(), true);
    }

    public static function enabled(): bool
    {
        return (bool) self::config('oauth_enabled', 1);
    }

    /** URL de la fiche « protected resource » à annoncer dans WWW-Authenticate */
    public static function resourceMetadataUrl(): string
    {
        global $CFG_GLPI;
        $origin = self::origin();
        return $origin . '/.well-known/oauth-protected-resource' . self::basePath() . '/plugins/glpimcp/mcp';
    }

    private static function origin(): string
    {
        global $CFG_GLPI;
        $p = parse_url((string) ($CFG_GLPI['url_base'] ?? ''));
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function protectedResourceMetadata(): array
    {
        return [
            'resource'                 => self::resource(),
            'authorization_servers'    => [self::base()],
            'scopes_supported'         => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'GLPI',
            'resource_documentation'   => self::base() . '/front/config.form.php',
        ];
    }

    public static function authorizationServerMetadata(): array
    {
        $b = self::base();
        $meta = [
            'issuer'                                => $b,
            'authorization_endpoint'                => $b . '/oauth/authorize',
            'token_endpoint'                        => $b . '/oauth/token',
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported'                      => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
            'service_documentation'                 => 'https://modelcontextprotocol.io',
        ];
        if (self::config('oauth_dcr_enabled', 1)) {
            $meta['registration_endpoint'] = $b . '/oauth/register';
        }
        return $meta;
    }

    // ------------------------------------------------------------------ clients

    /**
     * Enregistrement dynamique (RFC 7591).
     */
    public static function register(array $req): array
    {
        global $DB;

        if (!self::config('oauth_dcr_enabled', 1)) {
            throw new OAuthException('invalid_request', "L'enregistrement dynamique des clients est désactivé sur ce GLPI.", 403);
        }
        $uris = $req['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === []) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris est obligatoire.');
        }
        foreach ($uris as $u) {
            self::assertRedirectUri((string) $u);
        }
        $method = (string) ($req['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            throw new OAuthException('invalid_client_metadata', 'token_endpoint_auth_method non supporté.');
        }
        foreach ((array) ($req['grant_types'] ?? ['authorization_code']) as $g) {
            if (!in_array($g, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthException('invalid_client_metadata', "grant_type « {$g} » non supporté.");
            }
        }

        $client_id = 'gmcpc_' . bin2hex(random_bytes(16));
        $secret = $method === 'none' ? null : 'gmcps_' . bin2hex(random_bytes(24));
        $name = mb_substr(trim((string) ($req['client_name'] ?? '')), 0, 120) ?: self::guessName($uris);

        $DB->insert(self::CLIENTS, [
            'client_id'     => $client_id,
            'secret_hash'   => $secret ? hash('sha256', $secret) : null,
            'name'          => $name,
            'redirect_uris' => json_encode(array_values($uris)),
            'origin'        => 'dcr',
            'is_active'     => 1,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        $out = [
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'client_name'                => $name,
            'redirect_uris'              => array_values($uris),
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $method,
            'scope'                      => self::SCOPE,
        ];
        if ($secret) {
            $out['client_secret'] = $secret;
            $out['client_secret_expires_at'] = 0;
        }
        return $out;
    }

    /**
     * Retrouve un client par client_id ; si client_id est une URL https (Client ID Metadata Document),
     * récupère et met en cache son document de métadonnées.
     */
    public static function getClient(string $client_id): array
    {
        global $DB;

        $it = $DB->request(['FROM' => self::CLIENTS, 'WHERE' => ['client_id' => $client_id], 'LIMIT' => 1]);
        if (count($it) === 1) {
            $row = $it->current();
            if (!$row['is_active']) {
                throw new OAuthException('unauthorized_client', 'Ce client a été désactivé par l\'administrateur GLPI.', 401);
            }
            // Rafraîchir un CIMD de plus de 24 h
            if ($row['origin'] === 'cimd' && strtotime((string) $row['last_use']) < time() - 86400) {
                self::fetchClientMetadataDocument($client_id, (int) $row['id']);
                return self::getClient($client_id);
            }
            return $row;
        }
        if (str_starts_with($client_id, 'https://')) {
            self::fetchClientMetadataDocument($client_id);
            return self::getClient($client_id);
        }
        throw new OAuthException('invalid_client', 'Client OAuth inconnu.', 401);
    }

    private static function fetchClientMetadataDocument(string $url, int $existing_id = 0): void
    {
        global $DB;

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new OAuthException('invalid_client', 'URL de métadonnées client refusée.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200 || strlen((string) $body) > 20000) {
            throw new OAuthException('invalid_client', 'Impossible de récupérer les métadonnées du client.');
        }
        $doc = json_decode((string) $body, true);
        if (!is_array($doc) || ($doc['client_id'] ?? '') !== $url || empty($doc['redirect_uris']) || !is_array($doc['redirect_uris'])) {
            throw new OAuthException('invalid_client', 'Document de métadonnées client invalide.');
        }
        foreach ($doc['redirect_uris'] as $u) {
            self::assertRedirectUri((string) $u);
        }
        $fields = [
            'name'          => mb_substr((string) ($doc['client_name'] ?? $host), 0, 120),
            'redirect_uris' => json_encode(array_values($doc['redirect_uris'])),
            'last_use'      => date('Y-m-d H:i:s'),
        ];
        if ($existing_id) {
            $DB->update(self::CLIENTS, $fields, ['id' => $existing_id]);
        } else {
            $DB->insert(self::CLIENTS, $fields + [
                'client_id' => $url, 'origin' => 'cimd', 'is_active' => 1, 'date_creation' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function assertRedirectUri(string $uri): void
    {
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || isset($p['fragment'])) {
            throw new OAuthException('invalid_redirect_uri', "redirect_uri invalide : {$uri}");
        }
        $loopback = in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($p['scheme'] === 'https' || ($p['scheme'] === 'http' && $loopback)) {
            return;
        }
        throw new OAuthException('invalid_redirect_uri', "redirect_uri doit être en https (ou http://localhost) : {$uri}");
    }

    public static function redirectUriAllowed(array $client, string $uri): bool
    {
        $allowed = json_decode((string) $client['redirect_uris'], true) ?: [];
        if (in_array($uri, $allowed, true)) {
            return true;
        }
        // Boucle locale : le port peut varier (RFC 8252 §7.3)
        $p = parse_url($uri);
        if (in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            foreach ($allowed as $a) {
                $q = parse_url($a);
                if (($q['scheme'] ?? '') === ($p['scheme'] ?? '') && ($q['host'] ?? '') === ($p['host'] ?? '') && ($q['path'] ?? '') === ($p['path'] ?? '')) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function guessName(array $uris): string
    {
        $host = (string) parse_url((string) $uris[0], PHP_URL_HOST);
        return match (true) {
            str_contains($host, 'claude.ai'), str_contains($host, 'claude.com'), str_contains($host, 'anthropic') => 'Claude',
            str_contains($host, 'chatgpt.com'), str_contains($host, 'openai.com') => 'ChatGPT',
            str_contains($host, 'mistral.ai') => 'Le Chat (Mistral)',
            default => $host ?: 'Client MCP',
        };
    }

    // ------------------------------------------------------------- autorisation

    /**
     * Valide une demande d'autorisation (GET ou POST de consentement).
     * Les erreurs avant validation du redirect_uri sont affichées, jamais redirigées.
     */
    public static function validateAuthorizeRequest(array $q): array
    {
        if (!self::enabled()) {
            throw new OAuthException('access_denied', 'OAuth est désactivé sur ce GLPI.', 403);
        }
        $client = self::getClient((string) ($q['client_id'] ?? ''));
        $redirect_uri = (string) ($q['redirect_uri'] ?? '');
        if ($redirect_uri === '') {
            $allowed = json_decode((string) $client['redirect_uris'], true) ?: [];
            if (count($allowed) !== 1) {
                throw new OAuthException('invalid_request', 'redirect_uri manquant.');
            }
            $redirect_uri = $allowed[0];
        }
        if (!self::redirectUriAllowed($client, $redirect_uri)) {
            throw new OAuthException('invalid_request', "redirect_uri non enregistré pour ce client : {$redirect_uri}");
        }
        // À partir d'ici, les erreurs peuvent être renvoyées au client via redirect_uri
        $errors = [];
        if (($q['response_type'] ?? '') !== 'code') {
            $errors[] = ['unsupported_response_type', 'response_type doit valoir « code ».'];
        }
        if (empty($q['code_challenge']) || ($q['code_challenge_method'] ?? 'plain') !== 'S256') {
            $errors[] = ['invalid_request', 'PKCE S256 obligatoire.'];
        }
        if (!empty($q['resource']) && !self::resourceAllowed((string) $q['resource'])) {
            $errors[] = ['invalid_target', 'resource ne correspond ni à ce serveur MCP (' . self::resource() . ') ni à un relais autorisé.'];
        }
        return [
            'client'         => $client,
            'redirect_uri'   => $redirect_uri,
            'state'          => isset($q['state']) ? (string) $q['state'] : null,
            'code_challenge' => (string) ($q['code_challenge'] ?? ''),
            'errors'         => $errors,
        ];
    }

    public static function issueCode(array $req, int $users_id, int $profiles_id, int $days): string
    {
        global $DB;

        if ($profiles_id > 0 && !in_array($profiles_id, Profile_User::getUserProfiles($users_id), false)) {
            $profiles_id = 0;
        }
        $code = 'gmcpa_' . bin2hex(random_bytes(24));
        $DB->insert(self::CODES, [
            'code_hash'       => hash('sha256', $code),
            'oauthclients_id' => (int) $req['client']['id'],
            'users_id'        => $users_id,
            'profiles_id'     => $profiles_id,
            'redirect_uri'    => $req['redirect_uri'],
            'code_challenge'  => $req['code_challenge'],
            'days'            => max(1, min(365, $days)),
            'date_expiration' => date('Y-m-d H:i:s', time() + 120),
        ]);
        return $code;
    }

    public static function redirectWith(string $redirect_uri, array $params): string
    {
        return $redirect_uri . (str_contains($redirect_uri, '?') ? '&' : '?') . http_build_query(array_filter($params, fn($v) => $v !== null));
    }

    // ------------------------------------------------------------------- jetons

    public static function token(array $req, ?string $basic_auth): array
    {
        global $DB;

        if (!self::enabled()) {
            throw new OAuthException('access_denied', 'OAuth est désactivé sur ce GLPI.', 403);
        }

        // Authentification du client
        $client_id = (string) ($req['client_id'] ?? '');
        $client_secret = isset($req['client_secret']) ? (string) $req['client_secret'] : null;
        if ($basic_auth && str_starts_with($basic_auth, 'Basic ')) {
            [$bid, $bsecret] = array_map('urldecode', explode(':', (string) base64_decode(substr($basic_auth, 6)), 2) + [1 => '']);
            $client_id = $bid;
            $client_secret = $bsecret;
        }
        $client = self::getClient($client_id);
        if ($client['secret_hash'] !== null && !hash_equals($client['secret_hash'], hash('sha256', (string) $client_secret))) {
            throw new OAuthException('invalid_client', 'Authentification du client échouée.', 401);
        }

        $grant = (string) ($req['grant_type'] ?? '');
        $ttl = max(300, (int) self::config('oauth_access_ttl', 3600));

        if ($grant === 'authorization_code') {
            $it = $DB->request(['FROM' => self::CODES, 'WHERE' => ['code_hash' => hash('sha256', (string) ($req['code'] ?? ''))], 'LIMIT' => 1]);
            if (count($it) !== 1) {
                throw new OAuthException('invalid_grant', 'Code invalide ou déjà utilisé.');
            }
            $code = $it->current();
            $DB->delete(self::CODES, ['id' => $code['id']]); // usage unique
            if (strtotime((string) $code['date_expiration']) < time()) {
                throw new OAuthException('invalid_grant', 'Code expiré.');
            }
            if ((int) $code['oauthclients_id'] !== (int) $client['id']) {
                throw new OAuthException('invalid_grant', 'Code émis pour un autre client.');
            }
            if (isset($req['redirect_uri']) && $req['redirect_uri'] !== $code['redirect_uri']) {
                throw new OAuthException('invalid_grant', 'redirect_uri différent de la demande d\'autorisation.');
            }
            $verifier = (string) ($req['code_verifier'] ?? '');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if ($verifier === '' || !hash_equals($code['code_challenge'], $challenge)) {
                throw new OAuthException('invalid_grant', 'PKCE : code_verifier invalide.');
            }

            [$access, $refresh] = [Token::PREFIX . bin2hex(random_bytes(24)), 'gmcpr_' . bin2hex(random_bytes(24))];
            $token = new Token();
            $token->add([
                'users_id'           => (int) $code['users_id'],
                'oauthclients_id'    => (int) $client['id'],
                'name'               => $client['name'] . ' (OAuth)',
                'token_hash'         => hash('sha256', $access),
                'refresh_hash'       => hash('sha256', $refresh),
                'token_hint'         => 'OAuth',
                'profiles_id'        => (int) $code['profiles_id'],
                'entities_id'        => null,
                'is_recursive'       => 1,
                'is_active'          => 1,
                'date_creation'      => date('Y-m-d H:i:s'),
                'date_expiration'    => date('Y-m-d H:i:s', time() + $ttl),
                'refresh_expiration' => date('Y-m-d H:i:s', time() + (int) $code['days'] * 86400),
            ]);
            $DB->update(self::CLIENTS, ['last_use' => date('Y-m-d H:i:s')], ['id' => $client['id']]);
            return self::tokenResponse($access, $refresh, $ttl);
        }

        if ($grant === 'refresh_token') {
            $it = $DB->request(['FROM' => Token::getTable(), 'WHERE' => [
                'refresh_hash'    => hash('sha256', (string) ($req['refresh_token'] ?? '')),
                'oauthclients_id' => (int) $client['id'],
                'is_active'       => 1,
            ], 'LIMIT' => 1]);
            if (count($it) !== 1) {
                throw new OAuthException('invalid_grant', 'Jeton de rafraîchissement invalide ou révoqué.');
            }
            $row = $it->current();
            if (!empty($row['refresh_expiration']) && strtotime((string) $row['refresh_expiration']) < time()) {
                throw new OAuthException('invalid_grant', 'Autorisation expirée : reconnectez le connecteur.');
            }
            // Rotation : l'ancien couple devient inutilisable
            [$access, $refresh] = [Token::PREFIX . bin2hex(random_bytes(24)), 'gmcpr_' . bin2hex(random_bytes(24))];
            $DB->update(Token::getTable(), [
                'token_hash'      => hash('sha256', $access),
                'refresh_hash'    => hash('sha256', $refresh),
                'date_expiration' => date('Y-m-d H:i:s', time() + $ttl),
            ], ['id' => $row['id']]);
            return self::tokenResponse($access, $refresh, $ttl);
        }

        throw new OAuthException('unsupported_grant_type', 'grant_type non supporté.');
    }

    private static function tokenResponse(string $access, string $refresh, int $ttl): array
    {
        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $ttl,
            'refresh_token' => $refresh,
            'scope'         => self::SCOPE,
        ];
    }

    public static function purgeExpiredCodes(): void
    {
        global $DB;
        $DB->delete(self::CODES, ['date_expiration' => ['<', date('Y-m-d H:i:s')]]);
    }
}
