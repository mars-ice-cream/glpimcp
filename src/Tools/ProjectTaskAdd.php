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
use GlpiPlugin\Glpimcp\Projects\ProjectView;
use Project;
use ProjectTask;

class ProjectTaskAdd extends Tool
{
    public function name(): string
    {
        return 'glpi_project_task_add';
    }

    public function title(): string
    {
        return __('Add a project task', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return !$ctx->isHelpdeskInterface() && Project::canView();
    }

    public function description(): string
    {
        return "Prépare une ou plusieurs tâches de projet (nom, description, tâche parente, dates prévues, durée, état, jalon), avec leur équipe "
            . "(utilisateurs, groupes) et les tickets liés. Plusieurs tâches d'un coup via « tasks ». Aperçu puis confirmation.";
    }

    private static function taskSchema(): array
    {
        return [
            'name'                 => self::str('Nom de la tâche'),
            'content'              => self::str('Description'),
            'parent_task_id'       => self::int('Tâche parente (id existant)'),
            'parent_ref'           => self::str('Tâche parente créée dans le même lot (valeur de « ref »)'),
            'ref'                  => self::str('Nom court pour désigner cette tâche comme parente dans le même lot'),
            'plan_start_date'      => self::str('Début prévu (AAAA-MM-JJ ou AAAA-MM-JJ HH:MM)'),
            'plan_end_date'        => self::str('Fin prévue'),
            'planned_duration_hours' => ['type' => 'number', 'description' => 'Durée prévue en heures'],
            'state'                => self::str('État (nom ou id)'),
            'percent_done'         => self::int('Avancement en % (0-100)'),
            'is_milestone'         => self::bool('Jalon'),
            'users'                => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Utilisateurs affectés (ids)'],
            'groups'               => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Groupes affectés (ids)'],
            'tickets'              => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tickets à lier (ids)'],
        ];
    }

    public function inputSchema(): array
    {
        return self::obj(['projects_id' => self::int('Projet')] + self::taskSchema() + [
            'tasks' => ['type' => 'array', 'description' => 'Plusieurs tâches (mêmes champs que ci-dessus, sans projects_id)', 'items' => self::obj(self::taskSchema(), ['name'])],
        ], ['projects_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $project = Items::load('Project', (int) self::requireArg($args, 'projects_id'), READ);
        $list = !empty($args['tasks']) && is_array($args['tasks']) ? $args['tasks'] : [$args];
        if (count($list) > 50) {
            throw new ToolException('50 tâches maximum par lot.');
        }

        $ops = [];
        $known_refs = [];
        foreach ($list as $i => $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                throw new ToolException('Tâche ' . ($i + 1) . ' : nom manquant.');
            }
            $ref = 'task' . $i;
            if (!empty($t['ref'])) {
                $ref = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $t['ref']);
            }
            $input = [
                'projects_id' => $project->getID(),
                'name'        => $name,
                'entities_id' => $project->fields['entities_id'],
                'is_recursive' => $project->fields['is_recursive'],
            ];
            if (!empty($t['content'])) {
                $input['content'] = Items::toHtml((string) $t['content']);
            }
            if (!empty($t['parent_ref'])) {
                $pref = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $t['parent_ref']);
                if (!isset($known_refs[$pref])) {
                    throw new ToolException("Tâche « {$name} » : parent_ref « {$t['parent_ref']} » inconnu (la tâche parente doit être listée avant).");
                }
                $input['projecttasks_id'] = '@ref:' . $pref;
            } elseif (!empty($t['parent_task_id'])) {
                $parent = Items::load('ProjectTask', (int) $t['parent_task_id'], READ);
                if ((int) $parent->fields['projects_id'] !== $project->getID()) {
                    throw new ToolException("La tâche parente #{$parent->getID()} appartient à un autre projet.");
                }
                $input['projecttasks_id'] = $parent->getID();
            }
            self::applyCommon($input, $t);
            $ops[] = ['op' => 'add', 'itemtype' => ProjectTask::class, 'ref' => $ref, 'input' => $input];
            $known_refs[$ref] = true;
            array_push($ops, ...self::teamAndLinks('@ref:' . $ref, $t));
        }
        $summary = count($list) === 1
            ? sprintf(__('Task « %1$s » in project %2$s', 'glpimcp'), $list[0]['name'], $project->getNameID())
            : sprintf(__('%1$d tasks in project %2$s', 'glpimcp'), count($list), $project->getNameID());
        return PendingActions::propose($ctx, $this->name(), $summary, $ops);
    }

    /** Champs communs à l'ajout et à la modification */
    public static function applyCommon(array &$input, array $t): void
    {
        foreach (['plan_start_date', 'plan_end_date', 'real_start_date', 'real_end_date'] as $f) {
            if (array_key_exists($f, $t)) {
                $input[$f] = ProjectView::date($t[$f], $f);
            }
        }
        if (!empty($input['plan_start_date']) && !empty($input['plan_end_date']) && $input['plan_end_date'] < $input['plan_start_date']) {
            throw new ToolException('La fin prévue est avant le début prévu.');
        }
        if (isset($t['planned_duration_hours'])) {
            $input['planned_duration'] = (int) round(((float) $t['planned_duration_hours']) * 3600);
        }
        if (isset($t['state']) && $t['state'] !== '') {
            $input['projectstates_id'] = ProjectView::resolveState($t['state']);
        }
        if (isset($t['percent_done'])) {
            $input['percent_done'] = max(0, min(100, (int) $t['percent_done']));
        }
        if (isset($t['is_milestone'])) {
            $input['is_milestone'] = $t['is_milestone'] ? 1 : 0;
        }
    }

    /** Opérations d'équipe et de liens tickets pour une tâche (id ou "@ref:x") */
    public static function teamAndLinks(int|string $task, array $t): array
    {
        $ops = [];
        foreach (['users' => 'User', 'groups' => 'Group'] as $key => $type) {
            foreach (array_unique(array_map('intval', (array) ($t[$key] ?? []))) as $id) {
                Items::load($type, $id, READ);
                $ops[] = ['op' => 'add', 'itemtype' => \ProjectTaskTeam::class, 'input' => ['projecttasks_id' => $task, 'itemtype' => $type, 'items_id' => $id]];
            }
        }
        foreach (array_unique(array_map('intval', (array) ($t['tickets'] ?? []))) as $id) {
            Items::load('Ticket', $id, READ);
            $ops[] = ['op' => 'add', 'itemtype' => \ProjectTask_Ticket::class, 'input' => ['projecttasks_id' => $task, 'tickets_id' => $id]];
        }
        return $ops;
    }
}
