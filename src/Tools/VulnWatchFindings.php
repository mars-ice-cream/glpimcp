<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Integrations\VulnWatch;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use GlpiPlugin\Vulnwatch\Report;

class VulnWatchFindings extends Tool
{
    public function name(): string
    {
        return 'glpi_vulnwatch_findings';
    }

    public function title(): string
    {
        return __('Vulnerability alerts', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return VulnWatch::canView();
    }

    public function description(): string
    {
        return "Alertes de vulnérabilité (actif × CVE) du plugin VulnWatch, triées par priorité, dans les entités visibles : "
            . "statut (correspondance établie, à vérifier…), état (actif, corrigé, correction non confirmée, régression), "
            . "confiance, version relevée et corrigée, règle appliquée et raisons, ticket de campagne éventuel. Filtres : CVE, "
            . "logiciel, actif, statut, état, priorité, KEV, sans campagne. Les alerte_id servent à glpi_vulnwatch_campaign.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'cve'         => self::str('Identifiant CVE (ex. CVE-2024-9680)'),
            'software'    => self::str('Nom du logiciel (contient)'),
            'itemtype'    => self::str('Type d\'actif', ['Computer', 'Phone', 'NetworkEquipment', 'Printer']),
            'items_id'    => self::int('Actif (avec itemtype)'),
            'status'      => self::str('Statut : established, to_check, not_evaluated, not_affected, rejected, actionable (établie ou à vérifier, défaut) ou all', ['established', 'to_check', 'not_evaluated', 'not_affected', 'rejected', 'actionable', 'all']),
            'state'       => self::str('État : active (défaut), fixed, unverified, closed, regression ou all', ['active', 'fixed', 'unverified', 'closed', 'regression', 'all']),
            'min_priority' => self::str('Priorité minimale', ['P1', 'P2', 'P3', 'P4']),
            'kev_only'    => self::bool('Seulement les CVE exploitées (CISA KEV)'),
            'without_campaign' => self::bool('Seulement les alertes sans ticket de campagne'),
            'limit'       => self::int('Nombre maximum (défaut 30, max 200)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $where = [];
        if (!empty($args['cve'])) {
            $where['c.cve_id'] = strtoupper(trim((string) $args['cve']));
        }
        if (!empty($args['software'])) {
            $where['s.name'] = ['LIKE', '%' . trim((string) $args['software']) . '%'];
        }
        if (!empty($args['itemtype'])) {
            $where['i.itemtype'] = (string) $args['itemtype'];
            if (!empty($args['items_id'])) {
                $where['i.items_id'] = (int) $args['items_id'];
            }
        } elseif (!empty($args['items_id'])) {
            throw new ToolException('items_id demande aussi itemtype.');
        }
        $status = (string) ($args['status'] ?? 'actionable');
        if ($status === 'actionable') {
            $where['f.status'] = ['established', 'to_check'];
        } elseif ($status !== 'all') {
            $where['f.status'] = $status;
        }
        $state = (string) ($args['state'] ?? 'active');
        if ($state === 'regression') {
            $where['i.state'] = 'active';
            $where['i.is_regression'] = 1;
        } elseif ($state !== 'all') {
            $where['i.state'] = $state;
        }
        if (!empty($args['kev_only'])) {
            $where['c.is_kev'] = 1;
        }
        if (!empty($args['without_campaign'])) {
            $where['i.plugin_vulnwatch_campaigns_id'] = 0;
        }
        $rows = Report::items($where, 2000);
        if (!empty($args['min_priority'])) {
            $max = (int) substr((string) $args['min_priority'], 1);
            $rows = array_values(array_filter($rows, fn($r) => (int) substr($r['priority']['level'], 1) <= $max));
        }
        $limit = min(200, max(1, (int) ($args['limit'] ?? 30)));
        return [
            'total'   => count($rows),
            'alertes' => array_map([VulnWatch::class, 'findingRow'], array_slice($rows, 0, $limit)),
            'tronqué' => count($rows) > $limit,
        ];
    }
}
