<?php

/**
 * GLPI MCP — serveur MCP natif pour GLPI 11
 *
 * Expose GLPI à un assistant IA (Claude, Copilot, etc.) via le Model Context
 * Protocol, en respectant strictement les droits du compte GLPI utilisé.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpimcp\Token;

define('PLUGIN_GLPIMCP_VERSION', '1.6.0');
define('PLUGIN_GLPIMCP_MIN_GLPI', '11.0.0');
define('PLUGIN_GLPIMCP_MAX_GLPI', '11.99.99');

function plugin_version_glpimcp(): array
{
    return [
        'name'         => 'GLPI MCP',
        'version'      => PLUGIN_GLPIMCP_VERSION,
        'author'       => 'Gerome Le Duff',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://dokore.fr',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GLPIMCP_MIN_GLPI,
                'max' => PLUGIN_GLPIMCP_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_glpimcp_check_prerequisites(): bool
{
    return true;
}

function plugin_glpimcp_check_config($verbose = false): bool
{
    return true;
}

function plugin_init_glpimcp(): void
{
    global $PLUGIN_HOOKS;

    // Le point d'entrée MCP est sans état : authentification par jeton Bearer,
    // pas de cookie de session, pas de CSRF.
    SessionManager::registerPluginStatelessPath('glpimcp', '#^/mcp$#');
    SessionManager::registerPluginStatelessPath('glpimcp', '#^/oauth/(token|register)$#');
    SessionManager::registerPluginStatelessPath('glpimcp', '#^/\.well-known/#');

    // Découverte OAuth aux emplacements racine (/.well-known/oauth-*) attendus par claude.ai, ChatGPT…
    global $kernel;
    static $listener_added = false;
    if (!$listener_added && isset($kernel) && $kernel->getContainer() !== null) {
        $kernel->getContainer()->get('event_dispatcher')->addListener(
            \Symfony\Component\HttpKernel\KernelEvents::REQUEST,
            [\GlpiPlugin\Glpimcp\OAuth\WellKnown::class, 'onRequest'],
            512
        );
        $listener_added = true;
    }

    // Onglet « Assistant IA (MCP) » dans les préférences et la fiche utilisateur
    Plugin::registerClass(Token::class, ['addtabon' => ['Preference', 'User']]);

    // Onglet « Assistant IA (MCP) » sur la fiche profil : outils et types d'action par profil
    Plugin::registerClass(\GlpiPlugin\Glpimcp\ProfilePolicy::class, ['addtabon' => ['Profile']]);

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['glpimcp'] = 'front/config.form.php';
        // Administration > Assistant IA (MCP)
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['glpimcp'] = ['admin' => \GlpiPlugin\Glpimcp\Menu::class];
    }
}
