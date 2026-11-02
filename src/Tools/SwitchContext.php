<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use GlpiPlugin\Glpimcp\Token;

class SwitchContext extends Tool
{
    public function name(): string
    {
        return 'glpi_switch_context';
    }

    public function title(): string
    {
        return 'Changer de profil ou d\'entité';
    }

    public function description(): string
    {
        return "Change le profil et/ou l'entité utilisés par l'assistant pour les prochains appels (parmi ceux que l'utilisateur possède déjà). "
            . "Ne donne aucun droit supplémentaire. entities_id = -1 pour revenir à toutes les entités du profil.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'profiles_id'  => self::int('Profil à utiliser (voir glpi_whoami → profils_disponibles)'),
            'entities_id'  => self::int('Entité à utiliser, -1 pour toutes'),
            'is_recursive' => self::bool('Inclure les sous-entités (défaut : oui)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $update = ['id' => (int) $ctx->token['id']];

        if (isset($args['profiles_id'])) {
            $pid = (int) $args['profiles_id'];
            if (!isset($_SESSION['glpiprofiles'][$pid])) {
                throw new ToolException("Tu ne possèdes pas le profil #{$pid}.");
            }
            $update['profiles_id'] = $pid;
        }
        if (isset($args['entities_id'])) {
            $eid = (int) $args['entities_id'];
            if ($eid === -1) {
                $update['entities_id'] = 'NULL';
            } else {
                $pid = $update['profiles_id'] ?? (int) $_SESSION['glpiactiveprofile']['id'];
                $allowed = [];
                foreach ($_SESSION['glpiprofiles'][$pid]['entities'] ?? [] as $e) {
                    $allowed[] = (int) $e['id'];
                    if ($e['is_recursive']) {
                        $allowed = array_merge($allowed, array_map('intval', getSonsOf('glpi_entities', $e['id'])));
                    }
                }
                if (!in_array($eid, $allowed, true)) {
                    throw new ToolException("L'entité #{$eid} n'est pas accessible avec ce profil.");
                }
                $update['entities_id'] = $eid;
            }
            $update['is_recursive'] = isset($args['is_recursive']) ? (int) (bool) $args['is_recursive'] : 1;
        }

        (new Token())->update($update);

        return [
            'statut'  => 'contexte enregistré pour ce jeton (effectif dès le prochain appel)',
            'profil'  => isset($update['profiles_id']) ? Dropdown::getDropdownName('glpi_profiles', $update['profiles_id']) : 'inchangé',
            'entité'  => isset($update['entities_id']) ? ($update['entities_id'] === 'NULL' ? 'toutes' : Dropdown::getDropdownName('glpi_entities', $update['entities_id'])) : 'inchangée',
        ];
    }
}
