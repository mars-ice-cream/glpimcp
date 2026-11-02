<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Software;

use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Software;

/**
 * Fusion de logiciels (comme l'action de masse « Fusionner » de GLPI) mais en gardant
 * de quoi tout défaire : versions déplacées ou supprimées, installations, licences, corbeille.
 */
final class SoftwareMerge
{
    /** @return array{0: Software, 1: Software[]} */
    public static function load(int $target_id, array $source_ids): array
    {
        /** @var Software $target */
        $target = Items::load(Software::class, $target_id, UPDATE);
        $sources = [];
        foreach ($source_ids as $sid) {
            /** @var Software $s */
            $s = Items::load(Software::class, (int) $sid, UPDATE);
            if (!$s->can((int) $sid, DELETE)) {
                throw new ToolException("Tu n'as pas le droit de mettre à la corbeille le logiciel « {$s->getName()} » (nécessaire pour le fusionner).");
            }
            if ((int) $sid === $target_id) {
                continue;
            }
            if ((int) $s->fields['is_deleted'] === 1) {
                throw new ToolException("Le logiciel « {$s->getName()} » (#{$sid}) est déjà à la corbeille.");
            }
            $sources[] = $s;
        }
        if ($sources === []) {
            throw new ToolException('Aucun logiciel à fusionner.');
        }
        return [$target, $sources];
    }

    public static function preview(int $target_id, array $source_ids): array
    {
        global $DB;
        [$target, $sources] = self::load($target_id, $source_ids);
        $ids = array_map(fn($s) => $s->getID(), $sources);
        $versions = iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_softwareversions', 'WHERE' => ['softwares_id' => $ids]]));
        $vids = array_column($versions, 'id') ?: [0];
        $installs = count($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_items_softwareversions', 'WHERE' => ['softwareversions_id' => $vids]]));
        $licenses = count($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_softwarelicenses', 'WHERE' => ['softwares_id' => $ids]]));
        return [
            'action'        => 'fusionner des logiciels',
            'garder'        => sprintf('%s (#%d)', $target->getName(), $target->getID()),
            'fusionner'     => array_map(fn($s) => sprintf('%s (#%d)', $s->getName(), $s->getID()), $sources),
            'déplacés'      => sprintf('%d version(s), %d installation(s), %d licence(s)', count($versions), $installs, $licenses),
            'puis'          => 'les logiciels fusionnés vont à la corbeille',
        ];
    }

    /**
     * @return array{undo: array, résultat: array}
     */
    public static function execute(int $target_id, array $source_ids): array
    {
        global $DB;
        [$target, $sources] = self::load($target_id, $source_ids);
        $ids = array_map(fn($s) => $s->getID(), $sources);

        // Instantané avant fusion (pour l'annulation)
        $versions = iterator_to_array($DB->request(['FROM' => 'glpi_softwareversions', 'WHERE' => ['softwares_id' => $ids]]), false);
        $vids = array_map(fn($v) => (int) $v['id'], $versions) ?: [0];
        $installs = iterator_to_array($DB->request(['SELECT' => ['id', 'softwareversions_id'], 'FROM' => 'glpi_items_softwareversions', 'WHERE' => ['softwareversions_id' => $vids]]), false);
        $licenses = iterator_to_array($DB->request([
            'SELECT' => ['id', 'softwares_id', 'softwareversions_id_buy', 'softwareversions_id_use'],
            'FROM'   => 'glpi_softwarelicenses',
            'WHERE'  => ['OR' => ['softwares_id' => $ids, 'softwareversions_id_buy' => $vids, 'softwareversions_id_use' => $vids]],
        ]), false);

        // Versions de la cible, par nom
        $target_versions = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_softwareversions', 'WHERE' => ['softwares_id' => $target->getID()]]) as $tv) {
            $target_versions[mb_strtolower((string) $tv['name'])] ??= (int) $tv['id'];
        }

        $moved = $merged = 0;
        $deleted_versions = [];
        foreach ($versions as $v) {
            $dest = $target_versions[mb_strtolower((string) $v['name'])] ?? null;
            if ($dest !== null) {
                // Même version déjà présente côté cible : on y rattache installations et licences, puis on supprime le doublon
                $DB->update('glpi_items_softwareversions', ['softwareversions_id' => $dest], ['softwareversions_id' => $v['id']]);
                $DB->update('glpi_softwarelicenses', ['softwareversions_id_buy' => $dest], ['softwareversions_id_buy' => $v['id']]);
                $DB->update('glpi_softwarelicenses', ['softwareversions_id_use' => $dest], ['softwareversions_id_use' => $v['id']]);
                $DB->delete('glpi_softwareversions', ['id' => $v['id']]);
                $deleted_versions[] = $v;
                $merged++;
            } else {
                $DB->update('glpi_softwareversions', [
                    'softwares_id' => $target->getID(),
                    'entities_id'  => $target->fields['entities_id'],
                ], ['id' => $v['id']]);
                $target_versions[mb_strtolower((string) $v['name'])] = (int) $v['id'];
                $moved++;
            }
        }
        $DB->update('glpi_softwarelicenses', ['softwares_id' => $target->getID()], ['softwares_id' => $ids]);

        foreach ($sources as $s) {
            if (!$s->delete(['id' => $s->getID()])) {
                throw new ToolException("GLPI a refusé de mettre « {$s->getName()} » à la corbeille.", ['messages_glpi' => Items::flushMessages()]);
            }
        }

        return [
            'undo' => [
                'op'        => 'software_unmerge',
                'target_id' => $target->getID(),
                'sources'   => $ids,
                'versions'  => array_map(fn($v) => ['id' => (int) $v['id'], 'softwares_id' => (int) $v['softwares_id'], 'entities_id' => (int) $v['entities_id']], $versions),
                'deleted_versions' => $deleted_versions,
                'installs'  => array_map(fn($i) => ['id' => (int) $i['id'], 'v' => (int) $i['softwareversions_id']], $installs),
                'licenses'  => $licenses,
                '_label'    => sprintf('séparer à nouveau %s de %s', implode(', ', array_map(fn($s) => '« ' . $s->getName() . ' »', $sources)), '« ' . $target->getName() . ' »'),
            ],
            'résultat' => [
                'résultat'  => 'fusionné',
                'type'      => 'Logiciel',
                'id'        => $target->getID(),
                'nom'       => $target->getName(),
                'url'       => Items::url($target),
                'détail'    => sprintf('%d logiciel(s) fusionné(s) : %d version(s) déplacée(s), %d version(s) en double regroupée(s), %d installation(s) rattachée(s)', count($sources), $moved, $merged, count($installs)),
            ],
        ];
    }

    /** Annulation d'une fusion */
    public static function unmerge(array $u): array
    {
        global $DB;
        Items::load(Software::class, (int) $u['target_id'], UPDATE);
        $soft = new Software();
        foreach ($u['sources'] as $sid) {
            if ($soft->getFromDB((int) $sid) && (int) $soft->fields['is_deleted'] === 1) {
                if (!$soft->can((int) $sid, DELETE) || !$soft->restore(['id' => (int) $sid])) {
                    throw new ToolException("Impossible de restaurer le logiciel #{$sid} depuis la corbeille.");
                }
            }
        }
        foreach ($u['deleted_versions'] as $row) {
            if (!countElementsInTable('glpi_softwareversions', ['id' => $row['id']])) {
                $DB->insert('glpi_softwareversions', $row);
            }
        }
        foreach ($u['versions'] as $v) {
            $DB->update('glpi_softwareversions', ['softwares_id' => $v['softwares_id'], 'entities_id' => $v['entities_id']], ['id' => $v['id']]);
        }
        foreach ($u['installs'] as $i) {
            $DB->update('glpi_items_softwareversions', ['softwareversions_id' => $i['v']], ['id' => $i['id']]);
        }
        foreach ($u['licenses'] as $l) {
            $DB->update('glpi_softwarelicenses', [
                'softwares_id'            => $l['softwares_id'],
                'softwareversions_id_buy' => $l['softwareversions_id_buy'],
                'softwareversions_id_use' => $l['softwareversions_id_use'],
            ], ['id' => $l['id']]);
        }
        return ['résultat' => 'fusion annulée', 'type' => 'Logiciel', 'id' => (int) $u['target_id'], 'nom' => count($u['sources']) . ' logiciel(s) séparé(s) à nouveau'];
    }
}
