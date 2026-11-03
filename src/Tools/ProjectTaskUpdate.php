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
use Project;
use ProjectTask;
use ProjectTask_Ticket;
use ProjectTaskTeam;

class ProjectTaskUpdate extends Tool
{
    public function name(): string
    {
        return 'glpi_project_task_update';
    }

    public function title(): string
    {
        return __('Update a project task', 'glpimcp');
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
        return "Prépare la mise à jour d'une tâche de projet : avancement, état, dates prévues ou réelles, nom, description, durée ; "
            . "ajout ou retrait de membres (utilisateurs, groupes) ; liaison de tickets. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'              => self::int('Tâche de projet'),
            'name'            => self::str('Nouveau nom'),
            'content'         => self::str('Nouvelle description'),
            'percent_done'    => self::int('Avancement en % (0-100)'),
            'state'           => self::str('État (nom ou id)'),
            'plan_start_date' => self::str('Début prévu'),
            'plan_end_date'   => self::str('Fin prévue'),
            'real_start_date' => self::str('Début réel'),
            'real_end_date'   => self::str('Fin réelle'),
            'planned_duration_hours' => ['type' => 'number', 'description' => 'Durée prévue en heures'],
            'is_milestone'    => self::bool('Jalon'),
            'add_users'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Utilisateurs à ajouter à la tâche'],
            'add_groups'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Groupes à ajouter'],
            'remove_users'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Utilisateurs à retirer'],
            'remove_groups'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Groupes à retirer'],
            'link_tickets'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tickets à lier'],
        ], ['id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $task = Items::load('ProjectTask', (int) self::requireArg($args, 'id'), READ);
        $id = $task->getID();
        $ops = [];

        $input = [];
        if (isset($args['name']) && trim((string) $args['name']) !== '') {
            $input['name'] = trim((string) $args['name']);
        }
        if (isset($args['content'])) {
            $input['content'] = Items::toHtml((string) $args['content']);
        }
        ProjectTaskAdd::applyCommon($input, $args);
        $start = $input['plan_start_date'] ?? $task->fields['plan_start_date'];
        $end = $input['plan_end_date'] ?? $task->fields['plan_end_date'];
        if ($start && $end && $end < $start) {
            throw new ToolException('La fin prévue serait avant le début prévu.');
        }
        if (isset($input['percent_done']) && $input['percent_done'] < 100 && !empty($task->fields['auto_percent_done'])) {
            throw new ToolException("L'avancement de cette tâche est calculé automatiquement à partir de ses sous-tâches : mets plutôt à jour les sous-tâches.");
        }
        if ($input !== []) {
            $ops[] = ['op' => 'update', 'itemtype' => ProjectTask::class, 'id' => $id, 'input' => $input];
        }

        // Ajouts (sans doublon avec l'équipe existante)
        $existing = [];
        foreach ((new ProjectTaskTeam())->find(['projecttasks_id' => $id]) as $row) {
            $existing[$row['itemtype'] . ':' . $row['items_id']] = (int) $row['id'];
        }
        $add = ['users' => [], 'groups' => [], 'tickets' => []];
        foreach (['add_users' => ['users', 'User'], 'add_groups' => ['groups', 'Group']] as $key => [$k, $type]) {
            foreach ((array) ($args[$key] ?? []) as $uid) {
                if (!isset($existing[$type . ':' . (int) $uid])) {
                    $add[$k][] = (int) $uid;
                }
            }
        }
        $linked = array_map(fn($r) => (int) $r['tickets_id'], (new ProjectTask_Ticket())->find(['projecttasks_id' => $id]));
        $add['tickets'] = array_values(array_diff(array_map('intval', (array) ($args['link_tickets'] ?? [])), $linked));
        array_push($ops, ...ProjectTaskAdd::teamAndLinks($id, $add));

        // Retraits
        foreach (['remove_users' => 'User', 'remove_groups' => 'Group'] as $key => $type) {
            foreach ((array) ($args[$key] ?? []) as $uid) {
                $team_id = $existing[$type . ':' . (int) $uid] ?? null;
                if ($team_id === null) {
                    throw new ToolException(($type === 'User' ? 'Utilisateur' : 'Groupe') . " #{$uid} absent de l'équipe de cette tâche.");
                }
                $ops[] = ['op' => 'delete', 'itemtype' => ProjectTaskTeam::class, 'id' => $team_id, 'purge' => true];
            }
        }

        if ($ops === []) {
            throw new ToolException('Rien à modifier : aucun champ fourni, ou valeurs déjà en place.');
        }
        return PendingActions::propose($ctx, $this->name(), sprintf(__('Update of task %s', 'glpimcp'), $task->getNameID()), $ops);
    }
}
