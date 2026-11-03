<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

/**
 * Primitive d'écriture : un lot d'opérations génériques, avec exactement les mêmes garanties que tous les
 * outils (droits GLPI, interrupteurs, aperçu → confirmation, transaction tout ou rien, journal, annulation).
 * C'est par elle que les programmes qui s'appuient sur le socle (Dokore, scripts) modifient GLPI.
 */
class ApplyOperations extends Tool
{
    public const KINDS = [
        'add'                     => 'créer : {itemtype, input, ref?} ; "@ref:<ref>" utilisable dans les opérations suivantes',
        'update'                  => 'modifier : {itemtype, id, input}',
        'delete'                  => 'corbeille ou suppression : {itemtype, id, purge?}',
        'restore'                 => 'restaurer depuis la corbeille : {itemtype, id}',
        'config'                  => 'configuration : {context, values}',
        'profile_rights'          => 'droits d\'un profil : {profiles_id, rights}',
        'form_build'              => 'formulaire natif : {spec, ref?} (format de glpi_form_question_types)',
        'form_destination_add'    => 'destination de formulaire : {forms_id, spec}',
        'form_destination_update' => 'modifier une destination : {forms_id, destination_id, spec}',
        'form_submit'             => 'soumettre un formulaire : {forms_id, answers}',
        'software_merge'          => 'fusionner des logiciels : {target_id, source_ids}',
    ];

    public function name(): string
    {
        return 'glpi_apply_operations';
    }

    public function title(): string
    {
        return __('Apply a batch of operations (primitive)', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        $kinds = [];
        foreach (self::KINDS as $k => $d) {
            $kinds[] = "{$k} ({$d})";
        }
        return "Primitive d'écriture pour les programmes : prépare un lot d'opérations exécuté en une transaction (tout ou rien), "
            . "avec aperçu, confirmation (glpi_confirm_action), droits GLPI, interrupteurs, journal et annulation. Opérations : " . implode(' ; ', $kinds)
            . ". Un assistant qui agit pour un utilisateur doit préférer les outils dédiés (glpi_create_items, glpi_update_items…).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'summary'    => self::str('Résumé en une phrase (affiché à l\'utilisateur et au journal)'),
            'operations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => self::anyObj('{"op": "<type>", …}')],
            'client'     => self::str('Programme à l\'origine du lot (ex. « Dokore — nettoyage logiciels »), inscrit au journal'),
        ], ['summary', 'operations']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $ops = [];
        foreach ((array) $args['operations'] as $i => $op) {
            $op = (array) $op;
            $kind = (string) ($op['op'] ?? '');
            if (!isset(self::KINDS[$kind])) {
                throw new ToolException(sprintf('Opération %d : type « %s » inconnu (%s).', $i + 1, $kind, implode(', ', array_keys(self::KINDS))));
            }
            // L'annulation d'une fusion n'est possible que depuis le journal
            unset($op['_label']);
            $ops[] = $op;
        }
        $summary = trim((string) $args['summary']);
        if (!empty($args['client'])) {
            $summary .= ' [' . mb_substr((string) $args['client'], 0, 80) . ']';
        }
        return PendingActions::propose($ctx, $this->name(), $summary, $ops);
    }
}
