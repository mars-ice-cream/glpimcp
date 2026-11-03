<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use CommonDBTM;
use Session;

/**
 * Construction de l'annulation d'une action IA à partir du journal.
 *
 * L'annulation est elle-même une action normale : aperçu, confirmation, droits
 * revérifiés, tout ou rien, et journalisée.
 */
final class Rollback
{
    /**
     * @return array{log: array, ops: array, étapes: string[], conflits: string[], irréversible: string[], avertissements: string[]}
     */
    public static function plan(int $log_id, bool $force = false): array
    {
        $log = AuditLog::get($log_id);
        if ($log === null) {
            throw new ToolException("Entrée de journal #{$log_id} introuvable.");
        }
        $me = (int) Session::getLoginUserID();
        if ((int) $log['users_id'] !== $me && !Session::haveRight('config', UPDATE)) {
            throw new ToolException("Cette action a été faite par " . getUserName($log['users_id']) . " : seul cet utilisateur ou un administrateur GLPI peut l'annuler.");
        }
        if ($log['status'] !== 'exécuté') {
            throw new ToolException("L'action #{$log_id} a le statut « {$log['status']} » : rien n'a été modifié, il n'y a rien à annuler.");
        }
        if ((int) $log['rolled_back_by'] > 0) {
            throw new ToolException("L'action #{$log_id} a déjà été annulée (journal #{$log['rolled_back_by']}).");
        }
        $undo = json_decode((string) ($log['undo'] ?? ''), true);
        if (!is_array($undo) || $undo === []) {
            throw new ToolException("L'action #{$log_id} ne contient aucune opération annulable (action antérieure à la version 0.2 ou purement irréversible).");
        }

        $state        = json_decode((string) ($log['state'] ?? ''), true) ?: [];
        $irreversible = json_decode((string) ($log['irreversible'] ?? ''), true) ?: [];
        $conflicts    = [];
        $warnings     = [];
        $steps        = [];
        $ops          = [];

        // Détection des conflits : objet supprimé ou modifié depuis l'action
        foreach ($state as $key => $date_mod_after) {
            [$itemtype, $id] = explode('#', $key, 2) + [null, null];
            if (!$itemtype || !class_exists($itemtype)) {
                continue;
            }
            /** @var CommonDBTM $item */
            $item = new $itemtype();
            if (!$item->getFromDB((int) $id)) {
                $conflicts[] = $itemtype::getTypeName(1) . " #{$id} n'existe plus.";
                continue;
            }
            if (is_array($date_mod_after)) {
                // Comparaison des valeurs : un objet modifié puis remis en l'état n'est pas un conflit
                $changed = [];
                foreach ($date_mod_after as $field => $value) {
                    if (array_key_exists($field, $item->fields) && self::scalar($item->fields[$field]) !== self::scalar($value)) {
                        $changed[] = $field;
                    }
                }
                if ($changed !== []) {
                    $conflicts[] = Operations::display($item) . ' a été modifié depuis l\'action IA (champs : ' . implode(', ', array_slice($changed, 0, 8))
                        . '). L\'annuler écraserait ces modifications.';
                }
            } else {
                $now = $item->fields['date_mod'] ?? null;
                if ($now !== null && $date_mod_after !== null && strtotime((string) $now) > strtotime((string) $date_mod_after)) {
                    $conflicts[] = Operations::display($item) . " a été modifié depuis l'action IA (le {$now}). L'annuler écraserait ces modifications.";
                }
            }
        }

        foreach ($undo as $op) {
            $label = $op['_label'] ?? $op['op'];
            unset($op['_label']);

            // Objet déjà supprimé : rien à défaire
            if (in_array($op['op'], ['delete', 'update', 'restore'], true)) {
                $item = Items::newItem($op['itemtype']);
                if (!$item->getFromDB((int) $op['id'])) {
                    $warnings[] = "Étape ignorée (objet déjà supprimé) : {$label}";
                    continue;
                }
                // Pas le droit de purger ? On se rabat sur la corbeille quand c'est possible.
                if ($op['op'] === 'delete' && !empty($op['purge']) && !$item->can((int) $op['id'], PURGE)) {
                    if ($item->maybeDeleted() && $item->can((int) $op['id'], DELETE)) {
                        $op['purge'] = false;
                        $label .= ' (corbeille : droit de suppression définitive absent)';
                    }
                }
            }
            $ops[]   = $op;
            $steps[] = $label;
        }

        if ($conflicts !== [] && !$force) {
            throw new ToolException(
                "Conflit : des éléments ont changé depuis l'action IA #{$log_id}. Montre ces conflits à l'utilisateur ; "
                . "s'il veut quand même annuler, rappelle l'outil avec force=true.",
                ['conflits' => $conflicts]
            );
        }
        if ($ops === []) {
            throw new ToolException("Plus rien à annuler pour l'action #{$log_id} (les éléments concernés n'existent plus).", ['avertissements' => $warnings]);
        }

        return [
            'log'            => $log,
            'ops'            => $ops,
            'étapes'         => $steps,
            'conflits'       => $conflicts,
            'irréversible'   => $irreversible,
            'avertissements' => $warnings,
        ];
    }

    private static function scalar(mixed $v): string
    {
        return is_array($v) || is_object($v) ? (string) json_encode($v) : (string) $v;
    }

    /**
     * Revérification au moment de l'exécution d'une annulation : un technicien a pu modifier un élément entre
     * l'aperçu de l'annulation et sa confirmation. Tout conflit qui n'a pas été montré (et accepté) à l'aperçu,
     * ou tout changement des opérations prévues, bloque l'exécution.
     */
    public static function recheck(int $log_id, array $proposed_ops, array $accepted_conflicts): void
    {
        $plan = self::plan($log_id, true);
        $new = array_values(array_diff($plan['conflits'], $accepted_conflicts));
        if ($new !== []) {
            throw new ToolException(
                "Annulation bloquée : des éléments ont été modifiés depuis l'aperçu de l'annulation. Rien n'a été modifié. "
                . "Montre ces nouveaux conflits à l'utilisateur et refais l'aperçu (force=true seulement s'il l'accepte).",
                ['nouveaux_conflits' => $new]
            );
        }
        if (json_encode($plan['ops']) !== json_encode($proposed_ops)) {
            throw new ToolException("Annulation bloquée : les éléments à rétablir ont changé depuis l'aperçu (élément supprimé entre-temps…). Rien n'a été modifié ; refais l'aperçu.");
        }
    }

    /**
     * Ligne de journal présentée de façon lisible.
     */
    public static function describe(array $row, bool $details = false): array
    {
        $undo = json_decode((string) ($row['undo'] ?? ''), true) ?: [];
        $out = [
            'log_id'      => (int) $row['id'],
            'date'        => $row['date_creation'],
            'utilisateur' => getUserName($row['users_id']),
            'assistant'   => $row['client'] ?: '—',
            'profil'      => $row['profiles_id'] ? \Dropdown::getDropdownName('glpi_profiles', $row['profiles_id']) : null,
            'outil'       => $row['tool'],
            'statut'      => $row['status'],
            'résumé'      => $row['summary'],
            'annulable'   => $row['status'] === 'exécuté' && $undo !== [] && !(int) $row['rolled_back_by'],
        ];
        $irr = json_decode((string) ($row['irreversible'] ?? ''), true) ?: [];
        if ($row['status'] === 'exécuté') {
            $out['annulabilité'] = $undo === [] ? 'aucune' : ($irr === [] ? 'totale' : 'partielle');
        }
        $det = json_decode((string) ($row['details'] ?? ''), true) ?: [];
        if (!empty($det['approbation'])) {
            $out['approuvé_dans_glpi_par'] = $det['approbation'];
        }
        if ((int) $row['rolled_back_by']) {
            $out['annulée_par_journal'] = (int) $row['rolled_back_by'];
        }
        if ((int) $row['rollback_of']) {
            $out['annule_le_journal'] = (int) $row['rollback_of'];
        }
        if ($details) {
            $out['détails'] = json_decode((string) $row['details'], true);
            $out['opérations_inverses'] = array_map(fn($o) => $o['_label'] ?? $o['op'], $undo);
            $out['non_annulable'] = json_decode((string) ($row['irreversible'] ?? ''), true) ?: [];
        }
        return $out;
    }
}
