<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Config;
use CronTask;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Session;
use User;

/**
 * Faits sur l'instance GLPI qui ne se déduisent pas d'une simple lecture d'objets
 * (certains demandent un calcul côté serveur sans exposer de secret : comptes au mot de passe d'origine, tâches cron…).
 */
class SystemFacts extends Tool
{
    public function name(): string
    {
        return 'glpi_system_facts';
    }

    public function title(): string
    {
        return __('Facts about the GLPI instance (primitive)', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        return "Primitive de lecture : version de GLPI, langues, URL, inventaire, types activés pour l'analyse d'impact, et — avec le droit de lire "
            . "la configuration — comptes ayant encore leur mot de passe d'origine (noms seulement), annuaires et collecteurs actifs, tâches automatiques en mode CLI. "
            . "counts=[types] renvoie le nombre d'éléments visibles (hors corbeille et gabarits) par type.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'counts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Types à dénombrer (ex. Computer, ITILCategory, Glpi\\Form\\Form)'],
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $CFG_GLPI;
        $out = [
            'glpi_version'   => GLPI_VERSION,
            'plugin_version' => PLUGIN_GLPIMCP_VERSION,
            'langue_par_défaut' => $CFG_GLPI['language'] ?? null,
            'langues'        => array_keys($CFG_GLPI['languages'] ?? []),
            'url_base'       => $CFG_GLPI['url_base'] ?? null,
            'inventaire_activé' => (bool) (Config::getConfigurationValues('inventory', ['enabled_inventory'])['enabled_inventory'] ?? false),
            'types_analyse_impact' => array_values(\Impact::getEnabledItemtypes()),
        ];
        if (Session::haveRight('config', READ)) {
            $out['comptes_mot_de_passe_d_origine'] = User::checkDefaultPasswords();
            $out['annuaires_ldap_actifs'] = countElementsInTable('glpi_authldaps', ['is_active' => 1]);
            $out['collecteurs_actifs'] = countElementsInTable('glpi_mailcollectors', ['is_active' => 1]);
            $out['taches_cron_mode_cli'] = countElementsInTable('glpi_crontasks', ['mode' => CronTask::MODE_EXTERNAL, 'state' => CronTask::STATE_WAITING]);
        }
        $counts = [];
        foreach ((array) ($args['counts'] ?? []) as $type) {
            try {
                $item = Items::newItem((string) $type);
            } catch (\Throwable) {
                $counts[$type] = null;
                continue;
            }
            if (!$item::canView()) {
                $counts[$item::class] = null;
                continue;
            }
            $where = [];
            if ($item->maybeDeleted()) {
                $where['is_deleted'] = 0;
            }
            if ($item->maybeTemplate()) {
                $where['is_template'] = 0;
            }
            $counts[$item::class] = $item->isEntityAssign()
                ? countElementsInTableForMyEntities($item::getTable(), $where)
                : countElementsInTable($item::getTable(), $where);
        }
        if ($counts !== []) {
            $out['nombres'] = $counts;
        }
        return $out;
    }
}
