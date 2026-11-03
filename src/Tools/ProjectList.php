<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Projects\ProjectView;
use Project;
use ProjectTeam;

class ProjectList extends Tool
{
    public function name(): string
    {
        return 'glpi_project_list';
    }

    public function title(): string
    {
        return __('List projects', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return Project::canView();
    }

    public function description(): string
    {
        return "Liste les projets visibles avec état, avancement, chef de projet, échéances et retard. Filtres : texte (nom ou code), état, "
            . "mes projets (chef, groupe responsable ou membre de l'équipe), en retard. Les projets terminés sont exclus sauf demande. "
            . "Pour le détail (tâches, équipe, tickets liés) : glpi_project_get.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'text'             => self::str('Texte cherché dans le nom ou le code'),
            'state'            => self::str("État (nom ou id), ex. « En cours »"),
            'mine'             => self::bool("Seulement les projets où je suis chef, groupe responsable ou dans l'équipe"),
            'late_only'        => self::bool('Seulement les projets en retard (fin prévue dépassée, non terminés)'),
            'include_finished' => self::bool('Inclure les projets terminés (défaut : non)'),
            'limit'            => self::int('Nombre maximum (défaut 30, max 100)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;

        $where = ['glpi_projects.is_deleted' => 0, 'glpi_projects.is_template' => 0]
            + getEntitiesRestrictCriteria('glpi_projects', '', '', true);
        if (!empty($args['text'])) {
            $like = '%' . trim((string) $args['text']) . '%';
            $where[] = ['OR' => ['glpi_projects.name' => ['LIKE', $like], 'glpi_projects.code' => ['LIKE', $like]]];
        }
        if (isset($args['state']) && $args['state'] !== '') {
            $where['glpi_projects.projectstates_id'] = ProjectView::resolveState($args['state']);
        } elseif (empty($args['include_finished'])) {
            $where['glpi_projects.percent_done'] = ['<', 100];
            if ($finished = ProjectView::finishedStates()) {
                $where[] = ['NOT' => ['glpi_projects.projectstates_id' => $finished]];
            }
        }
        if (!empty($args['late_only'])) {
            $where['glpi_projects.plan_end_date'] = ['<', date('Y-m-d H:i:s')];
        }
        if (!empty($args['mine'])) {
            $groups = ProjectView::myGroups($ctx->users_id);
            $team_or = [['itemtype' => 'User', 'items_id' => $ctx->users_id]];
            if ($groups) {
                $team_or[] = ['itemtype' => 'Group', 'items_id' => $groups];
            }
            $team_ids = array_map(fn($r) => (int) $r['projects_id'], (new ProjectTeam())->find(['OR' => $team_or]));
            $mine = ['glpi_projects.users_id' => $ctx->users_id];
            if ($groups) {
                $mine['glpi_projects.groups_id'] = $groups;
            }
            if ($team_ids) {
                $mine['glpi_projects.id'] = array_values(array_unique($team_ids));
            }
            $where[] = ['OR' => $mine];
        }

        $limit = min(100, max(1, (int) ($args['limit'] ?? 30)));
        $out = [];
        $total_visible = 0;
        foreach ($DB->request(['FROM' => 'glpi_projects', 'WHERE' => $where, 'ORDER' => ['glpi_projects.plan_end_date', 'glpi_projects.name']]) as $row) {
            $p = new Project();
            if (!$p->getFromDB((int) $row['id']) || !$p->canViewItem()) {
                continue;
            }
            $total_visible++;
            if (count($out) < $limit) {
                $out[] = ProjectView::projectRow($row);
            }
        }
        return [
            'nombre'   => $total_visible,
            'affichés' => count($out),
            'en_retard' => count(array_filter($out, fn($p) => $p['en_retard'])),
            'projets'  => $out,
        ];
    }
}
