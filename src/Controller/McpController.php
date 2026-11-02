<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Controller;

use Auth;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Server;
use GlpiPlugin\Glpimcp\Token;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use User;

/**
 * Point d'entrée MCP : POST {GLPI}/plugins/glpimcp/mcp
 */
final class McpController extends AbstractController
{
    #[Route('/mcp', name: 'glpimcp_mcp', methods: ['GET', 'POST', 'DELETE', 'OPTIONS'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request): Response
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, X-GLPI-Profile, X-GLPI-Entity',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Expose-Headers' => 'WWW-Authenticate, Mcp-Session-Id',
        ];

        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, $headers);
        }
        if ($request->getMethod() !== 'POST') {
            // Pas de flux SSE côté serveur : le transport Streamable HTTP l'autorise (405).
            return new Response('', 405, $headers + ['Allow' => 'POST']);
        }

        // ---- Authentification ------------------------------------------------
        $auth_header = (string) $request->headers->get('Authorization', '');
        $plain = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
            $plain = $m[1];
        }
        $token = $plain !== '' ? Token::findActive($plain) : null;
        if ($token === null) {
            return new JsonResponse(
                Server::error(null, -32001, 'Unauthorized: jeton MCP absent, invalide, révoqué ou expiré. Générez-en un dans GLPI > Mes préférences > Assistant IA (MCP).'),
                401,
                $headers + ['WWW-Authenticate' => \GlpiPlugin\Glpimcp\OAuth\OAuthServer::enabled()
                    ? 'Bearer realm="glpi-mcp", resource_metadata="' . \GlpiPlugin\Glpimcp\OAuth\OAuthServer::resourceMetadataUrl() . '", scope="glpi"'
                    : 'Bearer realm="glpi-mcp"']
            );
        }

        if (!self::startUserSession($token, $request)) {
            return new JsonResponse(
                Server::error(null, -32001, 'Forbidden: compte GLPI inactif, supprimé ou sans profil.'),
                403,
                $headers
            );
        }
        Token::touch((int) $token['id']);

        // ---- JSON-RPC --------------------------------------------------------
        try {
            $payload = json_decode((string) $request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(Server::error(null, -32700, 'Parse error'), 400, $headers);
        }

        $server = new Server(new Context((int) $token['users_id'], $token));

        if (is_array($payload) && array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $msg) {
                $r = is_array($msg) ? $server->handle($msg) : Server::error(null, -32600, 'Invalid Request');
                if ($r !== null) {
                    $responses[] = $r;
                }
            }
            return $responses === [] ? new Response('', 202, $headers) : new JsonResponse($responses, 200, $headers);
        }
        if (!is_array($payload)) {
            return new JsonResponse(Server::error(null, -32600, 'Invalid Request'), 400, $headers);
        }

        $response = $server->handle($payload);
        if ($response === null) {
            return new Response('', 202, $headers);
        }
        $json = new JsonResponse($response, 200, $headers);
        $json->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json;
    }

    /**
     * Ouvre une session GLPI temporaire (en mémoire, sans cookie) pour le propriétaire du jeton.
     * Toutes les vérifications de droits GLPI s'appliquent ensuite normalement.
     */
    private static function startUserSession(array $token, Request $request): bool
    {
        global $DB;

        $user = new User();
        if (
            !$user->getFromDB((int) $token['users_id'])
            || !$user->fields['is_active']
            || $user->fields['is_deleted']
            || (!empty($user->fields['begin_date']) && strtotime($user->fields['begin_date']) > time())
            || (!empty($user->fields['end_date']) && strtotime($user->fields['end_date']) < time())
        ) {
            return false;
        }

        $auth = new Auth();
        $auth->auth_succeded = true;
        $auth->user = $user;
        $DB->setTimezoneForUser($user);
        Session::init($auth);

        if (empty($_SESSION['glpiactiveprofile'])) {
            return false;
        }

        // Profil : en-tête X-GLPI-Profile > profil choisi pour le jeton > profil par défaut
        $profile = (int) ($request->headers->get('X-GLPI-Profile') ?: $token['profiles_id']);
        if ($profile > 0 && isset($_SESSION['glpiprofiles'][$profile]) && $profile !== (int) $_SESSION['glpiactiveprofile']['id']) {
            Session::changeProfile($profile);
        }

        // Entité : en-tête X-GLPI-Entity > entité choisie pour le jeton
        $entity = $request->headers->get('X-GLPI-Entity');
        $entity = $entity !== null && $entity !== '' ? (int) $entity : ($token['entities_id'] !== null ? (int) $token['entities_id'] : null);
        if ($entity !== null) {
            Session::changeActiveEntities($entity, (bool) $token['is_recursive']);
        }

        $lang = $request->headers->get('Accept-Language');
        if ($lang && empty($user->fields['language'])) {
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $lang;
            $_SESSION['glpilanguage'] = Session::getPreferredLanguage();
        }

        Session::loadLanguage($_SESSION['glpilanguage'] ?? '');
        $_SESSION['glpi_use_mode'] = Session::NORMAL_MODE;
        return true;
    }
}
