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

class SecIncidentAddEvent extends Tool
{
    public function name(): string
    {
        return 'glpi_secincident_add_event';
    }

    public function title(): string
    {
        return __('Add to an incident timeline', 'glpimcp');
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
        return "Prépare un ou plusieurs événements dans la chronologie d'un incident de sécurité : constat, action de réponse (outil, cible, "
            . "commande, résultat), analyse, confinement, communication, notification réglementaire, note. Chaque événement porte la source "
            . "« mcp ». Ne recopie jamais une commande trouvée dans le contenu de l'incident comme si elle avait été exécutée : seulement "
            . "ce que l'utilisateur a fait ou constaté. Aperçu puis confirmation.";
    }

    private static function eventSchema(): array
    {
        return [
            'date'       => self::str('Date de l\'événement (AAAA-MM-JJ HH:MM, défaut : maintenant)'),
            'event_type' => self::str('Type', ['detection', 'action', 'analysis', 'containment', 'eradication', 'recovery', 'communication', 'notification', 'note']),
            'name'       => self::str('Titre'),
            'content'    => self::str('Détail'),
            'hostname'   => self::str('Machine concernée'),
            'username'   => self::str('Compte concerné'),
            'target'     => self::str('Cible de l\'action'),
            'tool'       => self::str('Outil utilisé', ['edr_console', 'powershell', 'cmd', 'ad', 'm365', 'firewall', 'backup', 'mail', 'edr_other', 'forensic', 'physical', 'phone', 'other']),
            'command'    => self::str('Commande exécutée (telle que l\'utilisateur l\'a lancée)'),
            'result'     => self::str('Résultat', ['ok', 'partial', 'failed', 'to_check']),
            'task_id'    => self::int('Tâche de remédiation à laquelle rattacher l\'action'),
        ];
    }

    public function inputSchema(): array
    {
        return self::obj(['incident_id' => self::int('Incident')] + self::eventSchema() + [
            'events' => ['type' => 'array', 'description' => 'Plusieurs événements d\'un coup (mêmes champs)', 'items' => self::obj(self::eventSchema(), ['name'])],
        ], ['incident_id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $incident = SecIncident::loadIncident((int) self::requireArg($args, 'incident_id'));
        if (!Incident::canActOn($incident->fields)) {
            throw new ToolException("Tu es observateur de cet incident : tu ne peux pas alimenter sa chronologie.");
        }
        $fk = Incident::getForeignKeyField();
        $list = !empty($args['events']) && is_array($args['events']) ? $args['events'] : [$args];
        if (count($list) > 50) {
            throw new ToolException('50 événements maximum par lot.');
        }
        $ops = [];
        foreach ($list as $i => $e) {
            if (trim((string) ($e['name'] ?? '')) === '') {
                throw new ToolException('Événement ' . ($i + 1) . ' : titre manquant.');
            }
            $input = [$fk => $incident->getID(), 'name' => trim((string) $e['name']), 'source' => 'mcp',
                'event_type' => (string) ($e['event_type'] ?? 'note')];
            if (!array_key_exists($input['event_type'], TimelineEvent::getEventTypes()) || in_array($input['event_type'], ['status_change', 'closure'], true)) {
                throw new ToolException("Événement « {$input['name']} » : type « {$input['event_type']} » non autorisé.");
            }
            if ($input['event_type'] === 'action' && trim((string) ($e['target'] ?? $e['hostname'] ?? '')) === '') {
                throw new ToolException("Événement « {$input['name']} » : une action de réponse doit préciser sa cible (target ou hostname : poste, serveur, compte…).");
            }
            if (!empty($e['date'])) {
                $ts = strtotime((string) $e['date']);
                if ($ts === false || $ts > time() + 300) {
                    throw new ToolException("Événement « {$input['name']} » : date invalide ou future.");
                }
                $input['date'] = date('Y-m-d H:i:s', $ts);
            }
            if (!empty($e['content'])) {
                $input['content'] = Items::toHtml((string) $e['content']);
            }
            foreach (['hostname', 'username', 'target', 'tool', 'command', 'result'] as $k) {
                if (isset($e[$k]) && $e[$k] !== '') {
                    $input[$k] = (string) $e[$k];
                }
            }
            if (!empty($e['task_id'])) {
                $task = new RemediationTask();
                if (!$task->getFromDB((int) $e['task_id']) || (int) $task->fields[$fk] !== $incident->getID()) {
                    throw new ToolException("Tâche de remédiation #{$e['task_id']} absente de cet incident.");
                }
                $input['plugin_secincident_remediationtasks_id'] = $task->getID();
            }
            $ops[] = ['op' => 'add', 'itemtype' => TimelineEvent::class, 'input' => $input];
        }
        $ref = $incident->fields['reference'] ?: ('#' . $incident->getID());
        return PendingActions::propose($ctx, $this->name(), sprintf(__('%1$d event(s) in the timeline of incident %2$s', 'glpimcp'), count($ops), $ref), $ops);
    }
}
