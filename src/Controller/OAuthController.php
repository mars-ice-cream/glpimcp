<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Controller;

use Dropdown;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Http\HeaderlessStreamedResponse;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Glpimcp\OAuth\OAuthException;
use GlpiPlugin\Glpimcp\OAuth\ClientPolicy;
use GlpiPlugin\Glpimcp\OAuth\OAuthServer;
use Html;
use Profile_User;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Points d'entrée OAuth 2.1 du serveur MCP.
 */
final class OAuthController extends AbstractController
{
    private const CORS = [
        'Access-Control-Allow-Origin'  => '*',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Mcp-Protocol-Version',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Cache-Control'                => 'no-store',
    ];

    #[Route('/.well-known/oauth-protected-resource', name: 'glpimcp_prm', methods: ['GET', 'OPTIONS'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'glpimcp_prm_mcp', methods: ['GET', 'OPTIONS'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function protectedResource(): Response
    {
        return new JsonResponse(OAuthServer::protectedResourceMetadata(), 200, self::CORS);
    }

    #[Route('/.well-known/oauth-authorization-server', name: 'glpimcp_asm', methods: ['GET', 'OPTIONS'])]
    #[Route('/.well-known/openid-configuration', name: 'glpimcp_oidc', methods: ['GET', 'OPTIONS'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function authorizationServer(): Response
    {
        if (!OAuthServer::enabled()) {
            return new JsonResponse(['error' => 'not_found'], 404, self::CORS);
        }
        return new JsonResponse(OAuthServer::authorizationServerMetadata(), 200, self::CORS);
    }

    #[Route('/oauth/register', name: 'glpimcp_oauth_register', methods: ['POST', 'OPTIONS'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function register(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, self::CORS);
        }
        try {
            $body = json_decode((string) $request->getContent(), true);
            if (!is_array($body)) {
                throw new OAuthException('invalid_client_metadata', 'Corps JSON attendu.');
            }
            return new JsonResponse(OAuthServer::register($body), 201, self::CORS);
        } catch (OAuthException $e) {
            return new JsonResponse($e->toArray(), $e->status, self::CORS);
        }
    }

    #[Route('/oauth/token', name: 'glpimcp_oauth_token', methods: ['POST', 'OPTIONS'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function token(Request $request): Response
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, self::CORS);
        }
        $params = $request->request->all();
        if ($params === [] && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $params = json_decode((string) $request->getContent(), true) ?: [];
        }
        try {
            return new JsonResponse(OAuthServer::token($params, $request->headers->get('Authorization')), 200, self::CORS + ['Pragma' => 'no-cache']);
        } catch (OAuthException $e) {
            $headers = self::CORS;
            if ($e->status === 401) {
                $headers['WWW-Authenticate'] = 'Basic realm="glpi-mcp"';
            }
            return new JsonResponse($e->toArray(), $e->status, $headers);
        }
    }

    /**
     * Page de consentement : l'utilisateur, connecté à GLPI, autorise l'assistant
     * et choisit le profil avec lequel il agira.
     */
    #[Route('/oauth/authorize', name: 'glpimcp_oauth_authorize', methods: ['GET', 'POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function authorize(Request $request): Response
    {
        global $CFG_GLPI;

        // Utilisateur pas encore connecté à GLPI : on l'envoie nous-mêmes sur la page de connexion en
        // conservant TOUS les paramètres (PKCE, state, resource…). Le pare-feu GLPI ajouterait « error=3 »
        // (« session expirée ») et ferait passer par une page d'erreur + déconnexion qui ré-encode l'URL.
        if (!Session::getLoginUserID()) {
            if ($request->isMethod('POST')) {
                return $this->page(fn() => print('<div class="alert alert-warning">Session GLPI expirée : relancez la connexion depuis l\'assistant.</div>'), 401);
            }
            $target = $request->getPathInfo() . ($request->getQueryString() !== null ? '?' . $request->getQueryString() : '');
            // Double encodage : GLPI applique un rawurldecode() de plus avant de rediriger (sinon %26, %2B… seraient perdus)
            return new RedirectResponse(($CFG_GLPI['root_doc'] ?? '') . '/?redirect=' . rawurlencode(rawurlencode($target)));
        }
        if (Session::getCurrentInterface() === false) {
            return $this->page(fn() => print('<div class="alert alert-danger">Aucun profil GLPI actif.</div>'), 403);
        }

        $q = $request->isMethod('POST') ? $request->request->all() : $request->query->all();

        try {
            $req = OAuthServer::validateAuthorizeRequest($q);
        } catch (OAuthException $e) {
            return $this->page(fn() => print('<div class="alert alert-danger"><strong>' . htmlescape($e->error) . '</strong> — ' . htmlescape($e->getMessage()) . '</div>'), 400);
        }
        if ($req['errors'] !== []) {
            [$err, $desc] = $req['errors'][0];
            return new RedirectResponse(OAuthServer::redirectWith($req['redirect_uri'], ['error' => $err, 'error_description' => $desc, 'state' => $req['state']]));
        }

        $users_id = (int) Session::getLoginUserID();

        // Client IA géré par l'entreprise : profil imposé, consentement implicite possible (SSO → accès direct)
        $client = $req['client'];
        if (ClientPolicy::isManaged($client)) {
            $forced = ClientPolicy::forcedProfileFor($client, $users_id);
            if ($forced === null) {
                return $this->page(fn() => print('<div class="alert alert-warning">' . sprintf(
                    htmlescape(__('This assistant is reserved by your administrator to accounts with the profile "%s". Your account does not have it.', 'glpimcp')),
                    htmlescape(\Dropdown::getDropdownName('glpi_profiles', (int) $client['forced_profiles_id']))
                ) . '</div>'), 403);
            }
            $days = (int) ($client['token_days'] ?? 0) > 0 ? (int) $client['token_days'] : 90;
            if (!empty($client['skip_consent']) || ($request->isMethod('POST') && ($q['decision'] ?? '') === 'allow')) {
                OAuthServer::purgeExpiredCodes();
                $code = OAuthServer::issueCode($req, $users_id, $forced, $days);
                return new RedirectResponse(OAuthServer::redirectWith($req['redirect_uri'], [
                    'code'  => $code,
                    'state' => $req['state'],
                    'iss'   => OAuthServer::base(),
                ]));
            }
            $q['profiles_id'] = $forced;
        }

        if ($request->isMethod('POST')) {
            if (($q['decision'] ?? '') !== 'allow') {
                return new RedirectResponse(OAuthServer::redirectWith($req['redirect_uri'], ['error' => 'access_denied', 'error_description' => "L'utilisateur a refusé l'accès.", 'state' => $req['state']]));
            }
            OAuthServer::purgeExpiredCodes();
            $code = OAuthServer::issueCode($req, $users_id, (int) ($q['profiles_id'] ?? 0), (int) ($q['days'] ?? 90));
            return new RedirectResponse(OAuthServer::redirectWith($req['redirect_uri'], [
                'code'  => $code,
                'state' => $req['state'],
                'iss'   => OAuthServer::base(),
            ]));
        }

        $host = parse_url($req['redirect_uri'], PHP_URL_HOST);

        return $this->page(function () use ($q, $client, $host, $users_id) {
            echo '<div class="card mx-auto" style="max-width:620px"><div class="card-body p-4">';
            echo '<h2 class="mb-3"><i class="ti ti-robot me-2"></i>' . sprintf(__('%s wants to access your GLPI', 'glpimcp'), '<strong>' . htmlescape($client['name']) . '</strong>') . '</h2>';
            echo '<p>' . sprintf(__('After approval you will be redirected to %s.', 'glpimcp'), '<code>' . htmlescape((string) $host) . '</code>') . '</p>';
            $res = rtrim((string) ($q['resource'] ?? ''), '/');
            if ($res !== '' && $res !== rtrim(OAuthServer::resource(), '/')) {
                echo '<div class="alert alert-info">' . sprintf(__('The assistant will reach GLPI through the relay server %s, authorised by your administrator.', 'glpimcp'), '<code>' . htmlescape((string) parse_url($res, PHP_URL_HOST)) . '</code>') . '</div>';
            }
            echo '<ul class="mb-3">';
            echo '<li>' . sprintf(__('It will act as %s, limited to the profile chosen below — it will never be able to use another profile with this access.', 'glpimcp'), '<strong>' . htmlescape(getUserName($users_id)) . '</strong>') . '</li>';
            echo '<li>' . __('Changes are previewed before being applied (unless the administrator chose immediate application of ordinary changes); critical actions require your own approval in GLPI. What can be undone is shown, and undone from the AI journal.', 'glpimcp') . '</li>';
            echo '<li>' . __('You can revoke this access at any time in My settings > AI assistant (MCP).', 'glpimcp') . '</li>';
            echo '</ul>';
            echo '<form method="post" action="">';
            foreach (['client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource'] as $k) {
                if (isset($q[$k])) {
                    echo Html::hidden($k, ['value' => (string) $q[$k]]);
                }
            }
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo '<div class="row g-2 mb-3"><div class="col-sm-7"><label class="form-label">' . __('Profile used by the assistant', 'glpimcp') . '</label>';
            echo '<select name="profiles_id" class="form-select"' . (ClientPolicy::isManaged($client) ? ' disabled' : '') . '>';
            $active = ClientPolicy::isManaged($client) ? (int) $client['forced_profiles_id'] : (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            foreach (Profile_User::getUserProfiles($users_id) as $pid) {
                if (ClientPolicy::isManaged($client) && (int) $pid !== $active) {
                    continue;
                }
                echo '<option value="' . (int) $pid . '"' . ((int) $pid === $active ? ' selected' : '') . '>' . htmlescape(Dropdown::getDropdownName('glpi_profiles', $pid)) . '</option>';
            }
            echo '</select></div><div class="col-sm-5"><label class="form-label">' . __('Validity', 'glpimcp') . '</label><select name="days" class="form-select">';
            foreach ([7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an'] as $d => $l) {
                echo '<option value="' . $d . '"' . ($d === 90 ? ' selected' : '') . '>' . $l . '</option>';
            }
            echo '</select></div></div>';
            echo '<div class="d-flex gap-2 justify-content-end">';
            echo '<button type="submit" name="decision" value="deny" class="btn btn-outline-secondary">' . __('Deny', 'glpimcp') . '</button>';
            echo '<button type="submit" name="decision" value="allow" class="btn btn-primary"><i class="ti ti-check me-1"></i>' . __('Allow', 'glpimcp') . '</button>';
            echo '</div></form></div></div>';
        });
    }

    private function page(callable $body, int $status = 200): Response
    {
        return new HeaderlessStreamedResponse(function () use ($body, $status) {
            http_response_code($status);
            Html::header(__('AI assistant (MCP)', 'glpimcp'));
            echo '<div class="container py-4">';
            $body();
            echo '</div>';
            Html::footer();
        });
    }
}
