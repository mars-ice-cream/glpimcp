<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Ticket;

class TicketCreate extends Tool
{
    public function name(): string
    {
        return 'glpi_ticket_create';
    }

    public function title(): string
    {
        return __('Open a ticket', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return Ticket::canCreate();
    }

    public function description(): string
    {
        return "Prépare l'ouverture d'un ticket (incident ou demande) à partir d'une description en langage naturel. "
            . "Reformule un titre court et clair, garde la description complète. Les règles métier GLPI (catégorie, attribution, SLA…) s'appliquent à la création. "
            . "Renvoie un aperçu à confirmer avec glpi_confirm_action. Astuce : vérifie d'abord avec glpi_form_list s'il existe un formulaire dédié.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'title'             => self::str('Titre court (max ~80 caractères)'),
            'description'       => self::str('Description détaillée (texte ou HTML)'),
            'type'              => self::str('incident ou demande', ['incident', 'demande']),
            'urgency'           => self::int('Urgence 1 (très basse) à 5 (très haute), défaut 3'),
            'itilcategories_id' => self::int('Catégorie ITIL (voir glpi_search itemtype=ITILCategory)'),
            'locations_id'      => self::int('Lieu'),
            'requester_users_id' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Demandeurs (défaut : moi)'],
            'observer_users_id' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Observateurs'],
            'assign_users_id'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Techniciens attribués (si autorisé)'],
            'assign_groups_id'  => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Groupes attribués (si autorisé)'],
            'entities_id'       => self::int('Entité (défaut : entité active)'),
            'items'             => ['type' => 'array', 'items' => self::obj(['itemtype' => self::str('Type'), 'id' => self::int('Id')]), 'description' => 'Matériels concernés'],
        ], ['title', 'description']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $input = [
            'name'    => mb_substr(trim((string) self::requireArg($args, 'title')), 0, 255),
            'content' => Items::toHtml((string) self::requireArg($args, 'description')),
            'type'    => ($args['type'] ?? 'incident') === 'demande' ? Ticket::DEMAND_TYPE : Ticket::INCIDENT_TYPE,
            'urgency' => max(1, min(5, (int) ($args['urgency'] ?? 3))),
        ];
        foreach (['itilcategories_id', 'locations_id', 'entities_id'] as $f) {
            if (!empty($args[$f])) {
                $input[$f] = (int) $args[$f];
            }
        }
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        }
        $input['_users_id_requester'] = array_map('intval', $args['requester_users_id'] ?? [$ctx->users_id]);
        if (!empty($args['observer_users_id'])) {
            $input['_users_id_observer'] = array_map('intval', $args['observer_users_id']);
        }
        if (!empty($args['assign_users_id'])) {
            $input['_users_id_assign'] = array_map('intval', $args['assign_users_id']);
        }
        if (!empty($args['assign_groups_id'])) {
            $input['_groups_id_assign'] = array_map('intval', $args['assign_groups_id']);
        }
        if (!empty($args['items'])) {
            $input['items_id'] = [];
            foreach ($args['items'] as $it) {
                $input['items_id'][Items::resolveType((string) $it['itemtype'])][] = (int) $it['id'];
            }
        }

        return PendingActions::propose($ctx, $this->name(), sprintf(__('Opening of ticket « %s »', 'glpimcp'), $input['name']), [
            ['op' => 'add', 'itemtype' => 'Ticket', 'input' => $input],
        ]);
    }
}
