<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\OAuth;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Sert les métadonnées OAuth aux emplacements « racine » que cherchent les clients MCP
 * (RFC 8414 / RFC 9728 : /.well-known/<type>/<chemin>), sans configuration du serveur web.
 *
 * Écoute l'événement kernel.request de GLPI avant le routage.
 */
final class WellKnown
{
    public static function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/.well-known/')) {
            return;
        }

        $plugin_path = '/plugins/glpimcp';
        $cors = ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store'];

        $prm = [
            '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-protected-resource' . $plugin_path . '/mcp',
            '/.well-known/oauth-protected-resource' . $plugin_path,
        ];
        $asm = [
            '/.well-known/oauth-authorization-server',
            '/.well-known/oauth-authorization-server' . $plugin_path,
            '/.well-known/openid-configuration',
            '/.well-known/openid-configuration' . $plugin_path,
        ];

        try {
            if (in_array($path, $prm, true)) {
                $event->setResponse(new JsonResponse(OAuthServer::protectedResourceMetadata(), 200, $cors));
            } elseif (in_array($path, $asm, true) && OAuthServer::enabled()) {
                $event->setResponse(new JsonResponse(OAuthServer::authorizationServerMetadata(), 200, $cors));
            }
        } catch (\Throwable) {
            // Ne jamais casser GLPI pour une requête de découverte.
        }
    }
}
