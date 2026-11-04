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

class VulnWatchAssetContext extends Tool
{
    public function name(): string
    {
        return 'glpi_vulnwatch_asset_context';
    }

    public function title(): string
    {
        return __('Set the criticality and exposure of an asset', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return VulnWatch::canUpdate() && !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        return "Renseigne le contexte VulnWatch d'un actif, utilisé dans le calcul de priorité des alertes : criticité "
            . "(0 non renseignée, 1 faible, 2 moyenne, 3 haute) et exposition (0 inconnue, 1 interne, 2 exposé à Internet). "
            . "Exige le droit de modifier l'actif. Aperçu avant/après puis confirmation ; tracé dans le journal des décisions de VulnWatch.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'itemtype'    => self::str('Type d\'actif', ['Computer', 'Phone', 'NetworkEquipment', 'Printer']),
            'items_id'    => self::int('Actif'),
            'criticality' => self::int('Criticité 0 à 3'),
            'exposure'    => self::int('Exposition 0 à 2'),
        ], ['itemtype', 'items_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $itemtype = (string) self::requireArg($args, 'itemtype');
        $id = (int) self::requireArg($args, 'items_id');
        if (!isset($args['criticality']) && !isset($args['exposure'])) {
            throw new ToolException('Indique criticality et/ou exposure.');
        }
        VulnWatch::loadAsset($itemtype, $id);
        $op = ['op' => 'vulnwatch_context', 'itemtype' => $itemtype, 'items_id' => $id];
        foreach (['criticality', 'exposure'] as $k) {
            if (isset($args[$k])) {
                $op[$k] = (int) $args[$k];
            }
        }
        return PendingActions::propose($ctx, $this->name(),
            sprintf(__('VulnWatch context of %s', 'glpimcp'), VulnWatch::assetLabel($itemtype, $id)), [$op]);
    }
}
