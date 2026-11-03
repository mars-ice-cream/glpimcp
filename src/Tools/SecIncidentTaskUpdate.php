<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Integrations\SecIncident;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use GlpiPlugin\Secincident\Incident;
use GlpiPlugin\Secincident\RemediationTask;
use GlpiPlugin\Secincident\TimelineEvent;

class SecIncidentTaskUpdate extends Tool
{
    public function name(): string
    {
        return 'glpi_secincident_task_update';
    }

    public function title(): string
    {
        return 'Avancer une tâche de remédiation';
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return SecIncident::canView() && !$ctx->isHelpdeskInterface();
    }

    public function description(): string
    {
        return "Prépare la mise à jour d'une tâche du plan de remédiation d'un incident de sécurité : statut (à faire, en cours, faite, "
            . "sans objet), compte rendu, et en option un événement « action » dans la chronologie. La définition de la tâche (intitulé, "
            . "consignes, phase) ne se change pas ici. Une tâche « faite » est signée par l'utilisateur. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'      => self::int('Tâche de remédiation'),
            'status'  => self::str('Nouveau statut', ['todo', 'in_progress', 'done', 'na']),
            'comment' => self::str('Compte rendu (remplace le précédent)'),
            'log_event' => self::bool('Ajouter aussi l\'action à la chronologie (défaut : oui si statut « faite »)'),
        ], ['id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $task = SecIncident::loadTask((int) self::requireArg($args, 'id'));
        $fk = Incident::getForeignKeyField();
        $input = [];
        if (!empty($args['status'])) {
            if (!array_key_exists($args['status'], RemediationTask::getStatuses())) {
                throw new ToolException("Statut inconnu « {$args['status']} ».");
            }
            $input['status'] = (string) $args['status'];
        }
        if (isset($args['comment'])) {
            $input['comment'] = (string) $args['comment'];
        }
        if ($input === []) {
            throw new ToolException('Rien à modifier : donne un statut ou un compte rendu.');
        }
        $ops = [['op' => 'update', 'itemtype' => RemediationTask::class, 'id' => $task->getID(), 'input' => $input]];
        $log = $args['log_event'] ?? (($input['status'] ?? '') === RemediationTask::STATUS_DONE);
        if ($log) {
            $label = RemediationTask::getStatuses()[$input['status'] ?? $task->fields['status']] ?? '';
            // Événement de la phase de la tâche (une « action » exigerait une cible précise)
            $phase = (string) $task->fields['phase'];
            $etype = array_key_exists($phase, TimelineEvent::getEventTypes()) && $phase !== 'action' ? $phase : 'note';
            $ops[] = ['op' => 'add', 'itemtype' => TimelineEvent::class, 'input' => [
                $fk => (int) $task->fields[$fk], 'event_type' => $etype, 'source' => 'mcp',
                'name' => 'Tâche « ' . $task->fields['name'] . ' » : ' . $label,
                'content' => Items::toHtml((string) ($input['comment'] ?? '')),
                'plugin_secincident_remediationtasks_id' => $task->getID(),
                'result' => ($input['status'] ?? '') === RemediationTask::STATUS_DONE ? 'ok' : 'to_check',
            ]];
        }
        return PendingActions::propose($ctx, $this->name(), 'Tâche de remédiation « ' . $task->fields['name'] . ' »', $ops);
    }
}
