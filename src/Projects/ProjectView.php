<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Projects;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Group_User;
use Project;
use ProjectState;
use ProjectTask;

/**
 * Lecture des projets et tâches de projet : lignes compactes, équipes, retards.
 * Toute ligne renvoyée a passé canViewItem() avec les droits de l'utilisateur.
 */
final class ProjectView
{
    /** @var array<int, array{name: string, finished: bool}>|null */
    private static ?array $states = null;

    /** @return array<int, array{name: string, finished: bool}> */
    public static function states(): array
    {
        if (self::$states === null) {
            self::$states = [];
            foreach ((new ProjectState())->find([], ['id']) as $row) {
                self::$states[(int) $row['id']] = ['name' => (string) $row['name'], 'finished' => (bool) $row['is_finished']];
            }
        }
        return self::$states;
    }

    /** @return int[] identifiants des états « terminé » */
    public static function finishedStates(): array
    {
        return array_keys(array_filter(self::states(), fn($s) => $s['finished']));
    }

    public static function stateLabel(int $id): ?string
    {
        return $id > 0 ? (self::states()[$id]['name'] ?? null) : null;
    }

    /** État donné par id ou par nom (insensible à la casse) */
    public static function resolveState(mixed $value): int
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            $id = (int) $value;
            if ($id === 0 || isset(self::states()[$id])) {
                return $id;
            }
        }
        $want = mb_strtolower(trim((string) $value));
        foreach (self::states() as $id => $s) {
            if (mb_strtolower($s['name']) === $want) {
                return $id;
            }
        }
        $names = implode(', ', array_map(fn($s) => $s['name'], self::states()));
        throw new ToolException("État de projet inconnu : « {$value} ». États existants : {$names}.");
    }

    public static function date(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            throw new ToolException("Date invalide pour {$label} : « {$value} » (format AAAA-MM-JJ ou AAAA-MM-JJ HH:MM).");
        }
        if ($ts > 2145916799) {
            // Colonnes TIMESTAMP de GLPI : rien au-delà du 19/01/2038
            throw new ToolException("Date trop lointaine pour {$label} : GLPI n'accepte pas de date après le 31/12/2037.");
        }
        return date(strlen(trim((string) $value)) <= 10 ? 'Y-m-d 00:00:00' : 'Y-m-d H:i:s', $ts);
    }

    /** @return int[] */
    public static function myGroups(int $users_id): array
    {
        return array_map(fn($g) => (int) $g['id'], Group_User::getUserGroups($users_id));
    }

    public static function isFinished(array $row): bool
    {
        return (int) $row['percent_done'] >= 100 || in_array((int) $row['projectstates_id'], self::finishedStates(), true);
    }

    public static function isLate(array $row): bool
    {
        return !self::isFinished($row) && !empty($row['plan_end_date']) && strtotime((string) $row['plan_end_date']) < time();
    }

    /** @return list<array{type: string, id: int, nom: string}> */
    public static function team(string $team_class, string $fk, int $id): array
    {
        $out = [];
        foreach ((new $team_class())->find([$fk => $id]) as $row) {
            $type = (string) $row['itemtype'];
            $nom = match ($type) {
                'User'  => getUserName((int) $row['items_id']),
                default => Dropdown::getDropdownName(getTableForItemType($type), (int) $row['items_id']),
            };
            $out[] = ['type' => Items::typeLabel($type), 'itemtype' => $type, 'id' => (int) $row['items_id'], 'nom' => $nom];
        }
        return $out;
    }

    public static function projectRow(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'nom'         => $row['name'],
            'code'        => $row['code'] ?: null,
            'état'        => self::stateLabel((int) $row['projectstates_id']),
            'avancement_%' => (int) $row['percent_done'],
            'priorité'    => \CommonITILObject::getPriorityName((int) $row['priority']),
            'chef_de_projet' => $row['users_id'] ? getUserName((int) $row['users_id']) : null,
            'groupe_responsable' => $row['groups_id'] ? Dropdown::getDropdownName('glpi_groups', (int) $row['groups_id']) : null,
            'début_prévu' => $row['plan_start_date'],
            'fin_prévue'  => $row['plan_end_date'],
            'en_retard'   => self::isLate($row),
            'entité'      => Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            'url'         => Project::getFormURLWithID((int) $row['id'], false),
        ];
    }

    public static function taskRow(array $row, bool $with_team = true): array
    {
        $out = [
            'id'           => (int) $row['id'],
            'nom'          => $row['name'],
            'projet'       => Dropdown::getDropdownName('glpi_projects', (int) $row['projects_id']),
            'projects_id'  => (int) $row['projects_id'],
            'tâche_parente' => $row['projecttasks_id'] ? (int) $row['projecttasks_id'] : null,
            'état'         => self::stateLabel((int) $row['projectstates_id']),
            'avancement_%' => (int) $row['percent_done'],
            'jalon'        => (bool) $row['is_milestone'],
            'début_prévu'  => $row['plan_start_date'],
            'fin_prévue'   => $row['plan_end_date'],
            'début_réel'   => $row['real_start_date'],
            'fin_réelle'   => $row['real_end_date'],
            'durée_prévue_h' => $row['planned_duration'] ? round((int) $row['planned_duration'] / 3600, 2) : null,
            'en_retard'    => self::isLate($row),
        ];
        if ($with_team) {
            $out['équipe'] = self::team(\ProjectTaskTeam::class, 'projecttasks_id', (int) $row['id']);
        }
        $out['url'] = ProjectTask::getFormURLWithID((int) $row['id'], false);
        return $out;
    }

    /** Tâches visibles d'un projet, parentes avant enfants */
    public static function tasksOf(int $projects_id): array
    {
        $rows = [];
        foreach ((new ProjectTask())->find(['projects_id' => $projects_id, 'is_template' => 0], ['plan_start_date', 'id']) as $row) {
            $t = new ProjectTask();
            if ($t->getFromDB((int) $row['id']) && $t->canViewItem()) {
                $rows[(int) $row['id']] = $row;
            }
        }
        // Ordre arborescent : chaque tâche suivie de ses sous-tâches
        $children = [];
        foreach ($rows as $id => $row) {
            $parent = (int) $row['projecttasks_id'];
            $children[isset($rows[$parent]) ? $parent : 0][] = $id;
        }
        $out = [];
        $walk = function (int $parent, int $level) use (&$walk, &$out, $children, $rows) {
            foreach ($children[$parent] ?? [] as $id) {
                $out[] = ['niveau' => $level] + self::taskRow($rows[$id]);
                $walk($id, $level + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }
}
