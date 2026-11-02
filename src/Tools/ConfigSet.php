<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Config;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Session;

class ConfigSet extends Tool
{
    public function name(): string
    {
        return 'glpi_config_set';
    }

    public function title(): string
    {
        return 'Modifier la configuration générale';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public function description(): string
    {
        return "Prépare la modification de paramètres de configuration générale (ex : url_base, admin_email, use_notifications, smtp_host, politique de mot de passe). "
            . "Seules des clés existantes sont acceptées ; mots de passe et secrets refusés (à saisir dans GLPI). Aperçu avant/après puis confirmation. "
            . "Pour les réglages d'une entité (e-mails, délais, enquêtes de satisfaction…), utiliser plutôt glpi_update_items sur Entity.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'values'  => self::anyObj('clé → nouvelle valeur'),
            'context' => self::str('core (défaut) ou inventory'),
            'summary' => self::str('Résumé'),
        ], ['values']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $context = (string) ($args['context'] ?? 'core');
        if (!in_array($context, ['core', 'inventory'], true)) {
            throw new ToolException('Contexte non autorisé via l\'IA.');
        }
        $values = (array) self::requireArg($args, 'values');
        $existing = Config::getConfigurationValues($context);
        foreach (array_keys($values) as $k) {
            if (!array_key_exists($k, $existing)) {
                throw new ToolException("Paramètre « {$k} » inconnu dans le contexte {$context}. Utilise glpi_config_get.");
            }
            if (is_bool($values[$k])) {
                $values[$k] = (int) $values[$k];
            }
        }
        return PendingActions::propose($ctx, $this->name(), (string) ($args['summary'] ?? 'Modification de la configuration'), [[
            'op' => 'config', 'context' => $context, 'values' => $values,
        ]]);
    }
}
