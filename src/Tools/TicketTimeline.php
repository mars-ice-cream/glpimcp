<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use CommonITILObject;
use CommonITILValidation;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use ITILFollowup;
use ITILSolution;

class TicketTimeline extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_get';
    }

    public function title(): string
    {
        return 'Ticket complet avec historique';
    }

    public function description(): string
    {
        return "Renvoie un ticket (ou problème/changement) avec ses acteurs et sa chronologie : suivis, tâches, solutions, validations, "
            . "dans l'ordre. Les éléments privés n'apparaissent que si l'utilisateur a le droit de les voir. Idéal pour résumer un ticket.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'       => self::int('Numéro du ticket'),
            'itemtype' => self::str('Ticket (défaut), Problem ou Change', ['Ticket', 'Problem', 'Change']),
        ], ['id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $item = Items::load((string) ($args['itemtype'] ?? 'Ticket'), (int) self::requireArg($args, 'id'), READ);
        if (!$item instanceof CommonITILObject) {
            throw new ToolException('Type non ITIL.');
        }
        $out = Items::export($item);
        $out['acteurs'] = GetItem::actors($item);

        $events = [];
        // Suivis
        $fup = new ITILFollowup();
        foreach ($fup->find(['itemtype' => $item::class, 'items_id' => $item->getID()], ['date ASC']) as $row) {
            $f = new ITILFollowup();
            if ($f->getFromDB($row['id']) && $f->canViewItem()) {
                $events[] = ['date' => $row['date'], 'type' => 'suivi', 'id' => (int) $row['id'], 'auteur' => getUserName($row['users_id']), 'privé' => (bool) $row['is_private'], 'contenu' => Items::text($row['content'], 3000)];
            }
        }
        // Tâches
        $task_class = $item::getTaskClass();
        if ($task_class) {
            $task = new $task_class();
            foreach ($task->find([$item::getForeignKeyField() => $item->getID()], ['date ASC']) as $row) {
                $t = new $task_class();
                if ($t->getFromDB($row['id']) && $t->canViewItem()) {
                    $events[] = [
                        'date' => $row['date'], 'type' => 'tâche', 'id' => (int) $row['id'], 'auteur' => getUserName($row['users_id']),
                        'technicien' => $row['users_id_tech'] ? getUserName($row['users_id_tech']) : null,
                        'état' => [0 => 'information', 1 => 'à faire', 2 => 'fait'][$row['state']] ?? $row['state'],
                        'durée_minutes' => (int) ($row['actiontime'] / 60), 'privé' => (bool) $row['is_private'],
                        'contenu' => Items::text($row['content'], 3000),
                    ];
                }
            }
        }
        // Solutions
        $sol = new ITILSolution();
        foreach ($sol->find(['itemtype' => $item::class, 'items_id' => $item->getID()], ['date_creation ASC']) as $row) {
            $events[] = [
                'date' => $row['date_creation'], 'type' => 'solution', 'id' => (int) $row['id'], 'auteur' => getUserName($row['users_id']),
                'statut' => [1 => 'aucun', 2 => 'en attente d\'approbation', 3 => 'acceptée', 4 => 'refusée'][$row['status']] ?? $row['status'],
                'contenu' => Items::text($row['content'], 3000),
            ];
        }
        // Validations
        $val_class = $item::getValidationClassName();
        if ($val_class && class_exists($val_class) && $val_class::canView()) {
            $val = new $val_class();
            foreach ($val->find([$item::getForeignKeyField() => $item->getID()], ['submission_date ASC']) as $row) {
                $events[] = [
                    'date' => $row['submission_date'], 'type' => 'validation', 'id' => (int) $row['id'],
                    'demandée_par' => getUserName($row['users_id']),
                    'statut' => CommonITILValidation::getStatus($row['status']),
                    'commentaire_demande' => Items::text($row['comment_submission'] ?? '', 1000),
                    'commentaire_réponse' => Items::text($row['comment_validation'] ?? '', 1000),
                ];
            }
        }
        usort($events, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        $out['chronologie'] = $events;
        $fup_input = ['itemtype' => $item::class, 'items_id' => $item->getID()];
        $out['droits'] = [
            'modifier'          => $item->can($item->getID(), UPDATE),
            'ajouter_suivi'     => (new ITILFollowup())->can(-1, CREATE, $fup_input),
        ];
        return $out;
    }
}
