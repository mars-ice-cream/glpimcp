<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Dropdown;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Projects\ProjectView;
use Item_Project;
use Itil_Project;
use Project;
use ProjectTask_Ticket;
use ProjectTeam;

class ProjectGet extends Tool
{
    public function name(): string
    {
        return 'glpi_project_get';
    }

    public function title(): string
    {
        return 'Projet complet';
    }

    public function isAvailable(Context $ctx): bool
    {
        return Project::canView();
    }

    public function description(): string
    {
        return "Renvoie un projet avec sa synthèse (avancement, tâches en retard, jalons), son équipe, ses tâches en arbre (état, avancement, "
            . "échéances, équipe), ses sous-projets, les tickets/problèmes/changements liés au projet ou à ses tâches, et les éléments "
            . "liés (matériels, logiciels…). Idéal pour un point d'avancement.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id' => self::int('Identifiant du projet'),
        ], ['id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        /** @var Project $project */
        $project = Items::load('Project', (int) self::requireArg($args, 'id'), READ);
        $id = $project->getID();

        $out = ProjectView::projectRow($project->fields);
        $out['description'] = Items::text($project->fields['content'] ?? '', 4000);
        $out['début_réel'] = $project->fields['real_start_date'];
        $out['fin_réelle'] = $project->fields['real_end_date'];
        $out['projet_parent'] = $project->fields['projects_id'] ? ['id' => (int) $project->fields['projects_id'], 'nom' => Dropdown::getDropdownName('glpi_projects', (int) $project->fields['projects_id'])] : null;
        $out['équipe'] = ProjectView::team(ProjectTeam::class, 'projects_id', $id);

        $tasks = ProjectView::tasksOf($id);
        $out['synthèse'] = [
            'tâches'          => count($tasks),
            'terminées'       => count(array_filter($tasks, fn($t) => $t['avancement_%'] >= 100)),
            'en_retard'       => array_values(array_map(fn($t) => $t['nom'] . ' (fin prévue ' . substr((string) $t['fin_prévue'], 0, 10) . ')', array_filter($tasks, fn($t) => $t['en_retard']))),
            'jalons'          => array_values(array_map(fn($t) => $t['nom'] . ' — ' . substr((string) $t['fin_prévue'], 0, 10) . ' — ' . $t['avancement_%'] . ' %', array_filter($tasks, fn($t) => $t['jalon']))),
        ];
        $out['tâches'] = $tasks;

        $subs = [];
        foreach ((new Project())->find(['projects_id' => $id, 'is_deleted' => 0, 'is_template' => 0], ['name']) as $row) {
            $p = new Project();
            if ($p->getFromDB((int) $row['id']) && $p->canViewItem()) {
                $subs[] = ProjectView::projectRow($row);
            }
        }
        $out['sous_projets'] = $subs;

        // Tickets, problèmes, changements liés au projet
        $itil = [];
        foreach ((new Itil_Project())->find(['projects_id' => $id]) as $row) {
            $obj = getItemForItemtype($row['itemtype']);
            if ($obj && $obj->getFromDB((int) $row['items_id']) && $obj->canViewItem()) {
                $itil[] = ['type' => Items::typeLabel($obj), 'id' => $obj->getID(), 'titre' => $obj->fields['name'], 'statut' => $obj::getStatus($obj->fields['status']), 'lié_à' => 'projet'];
            }
        }
        // Tickets liés aux tâches
        $task_ids = array_column($tasks, 'id');
        if ($task_ids) {
            foreach ((new ProjectTask_Ticket())->find(['projecttasks_id' => $task_ids]) as $row) {
                $t = new \Ticket();
                if ($t->getFromDB((int) $row['tickets_id']) && $t->canViewItem()) {
                    $itil[] = ['type' => Items::typeLabel($t), 'id' => $t->getID(), 'titre' => $t->fields['name'], 'statut' => $t::getStatus($t->fields['status']), 'lié_à' => 'tâche #' . $row['projecttasks_id']];
                }
            }
        }
        $out['tickets_liés'] = $itil;

        $items = [];
        foreach ((new Item_Project())->find(['projects_id' => $id]) as $row) {
            $obj = getItemForItemtype($row['itemtype']);
            if ($obj && $obj->getFromDB((int) $row['items_id']) && $obj->canViewItem()) {
                $items[] = ['type' => Items::typeLabel($obj), 'id' => $obj->getID(), 'nom' => $obj->getName()];
            }
        }
        $out['éléments_liés'] = $items;
        $out['droits'] = ['modifier' => $project->can($id, UPDATE)];
        return $out;
    }
}
