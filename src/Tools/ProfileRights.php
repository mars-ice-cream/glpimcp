<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Profile;
use ProfileRight;

class ProfileRights extends Tool
{
    private const FLAGS = [
        'lire'      => READ,
        'modifier'  => UPDATE,
        'créer'     => CREATE,
        'supprimer' => DELETE,
        'purger'    => PURGE,
        'lire_notes'     => READNOTE,
        'modifier_notes' => UPDATENOTE,
    ];

    public function name(): string
    {
        return 'glpi_profile_rights';
    }

    public function title(): string
    {
        return 'Droits des profils';
    }

    public function isAvailable(Context $ctx): bool
    {
        return Profile::canView();
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "action=lire : droits d'un profil, module par module, en clair (lire/créer/modifier/supprimer…) + valeur numérique brute. "
            . "action=modifier : prépare un changement de droits (rights = { \"computer\": [\"lire\",\"modifier\"], \"ticket\": 31 }). "
            . "GLPI interdit de donner plus de droits que les siens. Toujours soumis à confirmation. "
            . "Attention : les droits spéciaux (tickets, suivis, validations…) ont des bits spécifiques — pour eux, préférer une valeur numérique lue sur un profil modèle.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'action'      => self::str('lire ou modifier', ['lire', 'modifier']),
            'profiles_id' => self::int('Profil'),
            'modules'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Lire seulement ces modules (ex : ticket, computer, config)'],
            'rights'      => self::anyObj('Pour modifier : module → liste de droits ou entier'),
        ], ['action', 'profiles_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $pid = (int) self::requireArg($args, 'profiles_id');
        $profile = Items::load('Profile', $pid, ($args['action'] ?? 'lire') === 'modifier' ? UPDATE : READ);

        if (($args['action'] ?? 'lire') === 'lire') {
            $modules = !empty($args['modules']) ? array_map('strval', $args['modules']) : [];
            $rights = ProfileRight::getProfileRights($pid, $modules);
            $out = [];
            foreach ($rights as $module => $value) {
                $value = (int) $value;
                if ($value === 0 && $modules === []) {
                    continue;
                }
                $named = [];
                foreach (self::FLAGS as $label => $bit) {
                    if ($value & $bit) {
                        $named[] = $label;
                    }
                }
                $out[$module] = ['valeur' => $value, 'droits_standards' => $named];
            }
            ksort($out);
            return [
                'profil'    => $profile->getName(),
                'interface' => $profile->fields['interface'],
                'droits'    => $out,
                'note'      => 'Modules absents = aucun droit.',
            ];
        }

        $changes = [];
        foreach ((array) self::requireArg($args, 'rights') as $module => $spec) {
            if (is_numeric($spec)) {
                $changes[$module] = (int) $spec;
                continue;
            }
            $v = 0;
            foreach ((array) $spec as $flag) {
                $flag = mb_strtolower((string) $flag);
                if (!isset(self::FLAGS[$flag])) {
                    throw new ToolException("Droit « {$flag} » inconnu. Possibles : " . implode(', ', array_keys(self::FLAGS)));
                }
                $v |= self::FLAGS[$flag];
            }
            $changes[$module] = $v;
        }
        $known = ProfileRight::getAllPossibleRights();
        foreach (array_keys($changes) as $module) {
            if (!array_key_exists($module, $known)) {
                throw new ToolException("Module de droits « {$module} » inconnu.");
            }
        }
        return PendingActions::propose($ctx, $this->name(), 'Modification des droits du profil « ' . $profile->getName() . ' »', [[
            'op' => 'profile_rights', 'profiles_id' => $pid, 'rights' => $changes,
        ]]);
    }
}
