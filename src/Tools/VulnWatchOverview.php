<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Dropdown;
use GlpiPlugin\Glpimcp\Integrations\VulnWatch;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Vulnwatch\Report;

class VulnWatchOverview extends Tool
{
    public function name(): string
    {
        return 'glpi_vulnwatch_overview';
    }

    public function title(): string
    {
        return __('Vulnerability overview', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return VulnWatch::canView();
    }

    public function description(): string
    {
        return "Vue d'ensemble de la veille vulnérabilités (plugin VulnWatch) dans les entités visibles : compteurs par entité "
            . "(correspondances établies, à vérifier, non évaluées, corrigées, corrections non confirmées, régressions), couverture "
            . "réelle de l'analyse (logiciels rapprochés ou non, sans version, actifs à l'inventaire ancien) et alertes actives "
            . "les plus prioritaires. Une absence d'alerte ne vaut que dans la limite de la couverture affichée.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'top' => self::int('Nombre d\'alertes prioritaires à renvoyer (défaut 10, max 50)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $by_entity = [];
        foreach (Report::countsByEntity() as $eid => $counts) {
            $by_entity[] = ['entité' => Dropdown::getDropdownName('glpi_entities', (int) $eid)] + $counts;
        }
        $top = min(50, max(1, (int) ($args['top'] ?? 10)));
        $rows = Report::items(['i.state' => 'active', 'f.status' => ['established', 'to_check']], 500);
        return [
            'par_entité'  => $by_entity,
            'couverture'  => Report::coverage(),
            'alertes_prioritaires' => array_map([VulnWatch::class, 'findingRow'], array_slice($rows, 0, $top)),
            'alertes_actives' => count($rows),
            'note' => 'Priorité = gravité CVSS, exploitation connue (KEV), EPSS, criticité et exposition de l\'actif ; '
                . 'la confiance (fiabilité de la détection) est distincte. « Corrigé » n\'est posé qu\'après un inventaire récent.',
        ];
    }
}
