<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Integrations\VulnWatch;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

class VulnWatchCampaign extends Tool
{
    public function name(): string
    {
        return 'glpi_vulnwatch_campaign';
    }

    public function title(): string
    {
        return __('Create or complete a vulnerability campaign ticket', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return VulnWatch::canCampaign() && !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        return "Crée ou complète les tickets de campagne de correction VulnWatch pour des alertes choisies (alerte_id de "
            . "glpi_vulnwatch_findings) : un ticket par entité × produit × version cible, actifs liés, CVE et versions listées ; "
            . "si un ticket ouvert existe, un suivi ne liste que les nouveaux éléments, sans doublon. Seules les alertes actives "
            . "(correspondance établie ou à vérifier) sont prises. Le plugin suit les corrections, il n'installe rien. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'finding_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'alerte_id des alertes à traiter (max 200)'],
        ], ['finding_ids']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $ids = array_slice(array_map('intval', (array) self::requireArg($args, 'finding_ids')), 0, 200);
        if ($ids === []) {
            throw new ToolException('finding_ids vide.');
        }
        $preview = VulnWatch::campaignPreview($ids);
        return PendingActions::propose($ctx, $this->name(),
            sprintf(__('VulnWatch campaign: %d alert(s)', 'glpimcp'), array_sum(array_column($preview['campagnes'], 'alertes'))),
            [['op' => 'vulnwatch_campaign', 'items' => $ids]]);
    }
}
