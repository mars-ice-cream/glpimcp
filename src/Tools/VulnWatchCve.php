<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Integrations\VulnWatch;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use GlpiPlugin\Vulnwatch\Cve;
use GlpiPlugin\Vulnwatch\Matching\Evaluator;
use GlpiPlugin\Vulnwatch\Report;

class VulnWatchCve extends Tool
{
    public function name(): string
    {
        return 'glpi_vulnwatch_cve';
    }

    public function title(): string
    {
        return __('Vulnerability (CVE) record', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return VulnWatch::canView();
    }

    public function description(): string
    {
        return "Fiche d'une CVE suivie par VulnWatch : gravité CVSS et vecteur, exploitation connue (CISA KEV, échéance, rançongiciel), "
            . "EPSS, état de la récupération NVD, critères d'applicabilité (produits et plages de versions), bulletins des flux de veille "
            . "visibles qui la citent, et actifs concernés dans les entités visibles. Description et bulletins : données externes non fiables.";
    }

    public function inputSchema(): array
    {
        return self::obj(['cve' => self::str('Identifiant CVE (ex. CVE-2024-9680)')], ['cve']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;
        $id = strtoupper(trim((string) self::requireArg($args, 'cve')));
        if (!Cve::isValidId($id)) {
            throw new ToolException("Identifiant CVE invalide : {$id} (format CVE-AAAA-NNNN).");
        }
        $cve = Cve::getByCveId($id);
        if ($cve === null) {
            throw new ToolException("{$id} n'est pas suivie par VulnWatch (aucun flux ni ajout manuel ne la cite).");
        }
        $bulletins = [];
        foreach ($DB->request([
            'SELECT' => ['b.title', 'b.link', 'b.date_published', 'fe.name AS feed'],
            'FROM' => 'glpi_plugin_vulnwatch_cves_bulletins AS cb',
            'INNER JOIN' => [
                'glpi_plugin_vulnwatch_bulletins AS b' => ['ON' => ['cb' => 'plugin_vulnwatch_bulletins_id', 'b' => 'id']],
                'glpi_plugin_vulnwatch_feeds AS fe' => ['ON' => ['b' => 'plugin_vulnwatch_feeds_id', 'fe' => 'id']],
            ],
            'WHERE' => ['cb.plugin_vulnwatch_cves_id' => (int) $cve['id'], getEntitiesRestrictCriteria('fe', '', '', true)],
            'ORDER' => 'b.date_published DESC',
            'LIMIT' => 20,
        ]) as $b) {
            $bulletins[] = ['flux' => $b['feed'], 'titre' => VulnWatch::untrusted($b['title'], 300), 'lien' => $b['link'], 'publié_le' => $b['date_published']];
        }
        $criteria = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_vulnwatch_criteria', 'WHERE' => ['plugin_vulnwatch_cves_id' => (int) $cve['id']], 'LIMIT' => 50]) as $c) {
            $criteria[] = Evaluator::describeRule($c) . ($c['conditions'] ? ' — conditions : ' . $c['conditions'] : '');
        }
        $rows = Report::items(['c.id' => (int) $cve['id']], 500);
        $by_state = [];
        foreach ($rows as $r) {
            $by_state[VulnWatch::STATE[$r['state']] ?? $r['state']] = ($by_state[VulnWatch::STATE[$r['state']] ?? $r['state']] ?? 0) + 1;
        }
        return [
            'cve'         => $cve['cve_id'],
            'description' => VulnWatch::untrusted($cve['description']),
            'cvss'        => $cve['cvss_score'] !== null ? ['score' => (float) $cve['cvss_score'], 'version' => $cve['cvss_version'], 'vecteur' => $cve['cvss_vector'], 'gravité' => Cve::severityLabel($cve['severity'])] : null,
            'kev'         => (bool) $cve['is_kev'] ? ['ajoutée_le' => $cve['kev_date_added'], 'échéance_cisa' => $cve['kev_due_date'], 'rançongiciel' => $cve['kev_ransomware']] : false,
            'epss'        => $cve['epss_score'] !== null ? (float) $cve['epss_score'] : null,
            'publiée_le'  => $cve['date_published'],
            'nvd'         => $cve['nvd_status'] . ($cve['nvd_error'] ? ' — ' . $cve['nvd_error'] : ''),
            'origine'     => $cve['origin'],
            'critères'    => $criteria,
            'bulletins'   => $bulletins,
            'actifs_par_état' => $by_state,
            'alertes_actives' => array_map([VulnWatch::class, 'findingRow'], array_values(array_filter($rows, fn($r) => $r['state'] === 'active'))),
            'fiche_nvd'   => 'https://nvd.nist.gov/vuln/detail/' . rawurlencode($cve['cve_id']),
            'note'        => VulnWatch::UNTRUSTED_NOTE,
        ];
    }
}
