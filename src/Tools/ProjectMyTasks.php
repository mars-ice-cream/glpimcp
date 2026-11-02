<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Projects\ProjectView;
use Project;
use ProjectTask;
use ProjectTaskTeam;

class ProjectMyTasks extends Tool
{
    public function name(): string
    {
        return 'glpi_project_my_tasks';
    }

    public function title(): string
    {
        return 'Mes tâches de projet';
    }

    public function isAvailable(Context $ctx): bool
    {
        return Project::canView();
    }

    public function description(): string
    {
        return "Tâches de projet affectées à l'utilisateur (directement ou via ses groupes), non terminées, triées par échéance, avec le "
            . "retard signalé. users_id permet de regarder la charge d'un collègue (si les droits GLPI le permettent).";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'users_id'         => self::int('Utilisateur (défaut : moi)'),
            'include_groups'   => self::bool('Inclure les tâches affectées à ses groupes (défaut : oui)'),
            'include_finished' => self::bool('Inclure les tâches terminées (défaut : non)'),
            'late_only'        => self::bool('Seulement les tâches en retard'),
            'limit'            => self::int('Nombre maximum (défaut 50, max 200)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $uid = (int) ($args['users_id'] ?? $ctx->users_id);
        $or = [['itemtype' => 'User', 'items_id' => $uid]];
        if (($args['include_groups'] ?? true) && ($groups = ProjectView::myGroups($uid))) {
            $or[] = ['itemtype' => 'Group', 'items_id' => $groups];
        }
        $ids = array_values(array_unique(array_map(fn($r) => (int) $r['projecttasks_id'], (new ProjectTaskTeam())->find(['OR' => $or]))));
        $limit = min(200, max(1, (int) ($args['limit'] ?? 50)));
        $tasks = [];
        if ($ids) {
            foreach ((new ProjectTask())->find(['id' => $ids, 'is_template' => 0], ['plan_end_date', 'id']) as $row) {
                if (empty($args['include_finished']) && ProjectView::isFinished($row)) {
                    continue;
                }
                if (!empty($args['late_only']) && !ProjectView::isLate($row)) {
                    continue;
                }
                $t = new ProjectTask();
                if (!$t->getFromDB((int) $row['id']) || !$t->canViewItem()) {
                    continue;
                }
                $tasks[] = ProjectView::taskRow($row);
            }
        }
        // Sans échéance en dernier
        usort($tasks, fn($a, $b) => [$a['fin_prévue'] === null, $a['fin_prévue']] <=> [$b['fin_prévue'] === null, $b['fin_prévue']]);
        return [
            'utilisateur' => getUserName($uid),
            'nombre'      => count($tasks),
            'en_retard'   => count(array_filter($tasks, fn($t) => $t['en_retard'])),
            'tâches'      => array_slice($tasks, 0, $limit),
        ];
    }
}
