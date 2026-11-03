<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\TokenScope;
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
        return __('Change working entity', 'glpimcp');
    }

    public function description(): string
    {
        return "Choisit l'entité de travail de l'assistant pour les prochains appels, À L'INTÉRIEUR du périmètre accordé au jeton "
            . "(voir glpi_whoami → périmètre_du_jeton). entities_id = -1 pour revenir à tout le périmètre. Le profil est fixé par le "
            . "jeton : pour un autre profil, l'utilisateur crée un autre jeton (ou reconnecte le connecteur en choisissant ce profil).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'entities_id'  => self::int('Entité à utiliser, -1 pour tout le périmètre du jeton'),
            'is_recursive' => self::bool('Inclure les sous-entités (défaut : oui, si elles sont dans le périmètre)'),
            'profiles_id'  => self::int('Profil : seul le profil accordé au jeton est accepté'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $token = $ctx->token;
        if (isset($args['profiles_id']) && (int) $args['profiles_id'] !== (int) $token['profiles_id']) {
            throw new ToolException(sprintf(
                'Ce jeton est limité au profil « %s » : changer de profil exige un autre jeton (ou une nouvelle autorisation du connecteur).',
                Dropdown::getDropdownName('glpi_profiles', (int) $token['profiles_id'])
            ));
        }
        if (!isset($args['entities_id'])) {
            return ['statut' => 'inchangé', 'profil' => Dropdown::getDropdownName('glpi_profiles', (int) $token['profiles_id'])];
        }

        $update = ['id' => (int) $token['id']];
        $eid = (int) $args['entities_id'];
        if ($eid === -1) {
            $update['context_entities_id']  = 'NULL';
            $update['context_is_recursive'] = 'NULL';
        } else {
            if (!in_array($eid, TokenScope::allowedEntities($token), true)) {
                throw new ToolException("L'entité #{$eid} est hors du périmètre accordé à ce jeton.");
            }
            $update['context_entities_id']  = $eid;
            $update['context_is_recursive'] = isset($args['is_recursive']) ? (int) (bool) $args['is_recursive'] : 1;
        }
        (new Token())->update($update);

        return [
            'statut' => 'entité de travail enregistrée pour ce jeton (effective dès le prochain appel)',
            'profil' => Dropdown::getDropdownName('glpi_profiles', (int) $token['profiles_id']),
            'entité' => $eid === -1 ? 'tout le périmètre du jeton' : Dropdown::getDropdownName('glpi_entities', $eid),
        ];
    }
}
