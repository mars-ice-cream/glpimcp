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
use GlpiPlugin\Secincident\Incident_Item;
use GlpiPlugin\Secincident\Ioc;
use GlpiPlugin\Secincident\TimelineEvent;

class SecIncidentDeclare extends Tool
{
    public function name(): string
    {
        return 'glpi_secincident_declare';
    }

    public function title(): string
    {
        return __('Declare a security incident', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function isAvailable(Context $ctx): bool
    {
        return SecIncident::active() && !$ctx->isHelpdeskInterface() && Incident::canCreate();
    }

    public function description(): string
    {
        return "Prépare la déclaration d'un incident de sécurité (SecIncident) : titre, description, catégorie, gravité, source, dates de "
            . "détection et de prise de connaissance (point de départ des délais CNIL 72 h / ANSSI 24 h), impacts, données personnelles, NIS2, "
            . "responsable, éléments touchés (postes, comptes, tickets…), premiers IOC. Avant d'appeler, pose les questions qui manquent "
            . "(surtout la date de prise de connaissance et la présence de données personnelles). Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        $cats = SecIncident::active() ? array_keys(Incident::getCategories()) : null;
        return self::obj([
            'name'           => self::str('Titre court'),
            'content'        => self::str('Description factuelle'),
            'category'       => self::str('Catégorie (code)', $cats ?: null),
            'severity'       => self::int('Gravité : 1 informatif, 2 faible, 3 moyenne, 4 élevée, 5 critique'),
            'source'         => self::str('Origine du signalement', ['manual', 'edr', 'user_report', 'siem', 'edr_other', 'third_party', 'other']),
            'date_detection' => self::str('Détection (AAAA-MM-JJ HH:MM, défaut : maintenant)'),
            'date_awareness' => self::str('Prise de connaissance (AAAA-MM-JJ HH:MM) : départ des délais réglementaires'),
            'impact_confidentiality' => self::int('Impact confidentialité 0-4'),
            'impact_integrity'       => self::int('Impact intégrité 0-4'),
            'impact_availability'    => self::int('Impact disponibilité 0-4'),
            'is_personal_data'       => self::bool('Des données personnelles sont concernées'),
            'personal_data_categories' => self::str('Catégories de données personnelles'),
            'persons_affected'       => self::int('Nombre de personnes concernées'),
            'gdpr_risk'              => self::int('Risque pour les personnes : 0 aucun, 1 risque, 2 risque élevé'),
            'is_nis2'                => self::bool('Entité soumise à NIS2'),
            'is_significant'         => self::bool('Incident important au sens NIS2'),
            'users_id_tech'          => self::int('Responsable (utilisateur)'),
            'groups_id_tech'         => self::int('Groupe en charge'),
            'entities_id'            => self::int('Entité (défaut : entité active)'),
            'items'                  => ['type' => 'array', 'description' => 'Éléments touchés', 'items' => self::obj(['itemtype' => self::str('Computer, User, Ticket, NetworkEquipment…'), 'id' => self::int('Identifiant')], ['itemtype', 'id'])],
            'iocs'                   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Premiers indicateurs (hash, IP, domaine, URL, mail…), type deviné'],
        ], ['name', 'content']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $input = ['name' => trim((string) $args['name']), 'content' => Items::toHtml((string) self::requireArg($args, 'content'))];
        foreach (['category', 'source', 'personal_data_categories'] as $k) {
            if (!empty($args[$k])) {
                $input[$k] = (string) $args[$k];
            }
        }
        foreach (['severity', 'impact_confidentiality', 'impact_integrity', 'impact_availability', 'persons_affected', 'gdpr_risk', 'users_id_tech', 'groups_id_tech'] as $k) {
            if (isset($args[$k])) {
                $input[$k] = (int) $args[$k];
            }
        }
        foreach (['is_personal_data', 'is_nis2', 'is_significant'] as $k) {
            if (isset($args[$k])) {
                $input[$k] = $args[$k] ? 1 : 0;
            }
        }
        foreach (['date_detection', 'date_awareness'] as $k) {
            if (!empty($args[$k])) {
                $ts = strtotime((string) $args[$k]);
                if ($ts === false || $ts > time() + 300) {
                    throw new ToolException("Date invalide ou dans le futur pour {$k} : « {$args[$k]} ».");
                }
                $input[$k] = date('Y-m-d H:i:s', $ts);
            }
        }
        $input['entities_id'] = isset($args['entities_id']) ? (int) $args['entities_id'] : (int) $_SESSION['glpiactive_entity'];
        if (empty($input['date_awareness'])) {
            $warn = "Date de prise de connaissance non fournie : les délais CNIL/ANSSI ne pourront pas être calculés tant qu'elle n'est pas renseignée.";
        }

        $fk = Incident::getForeignKeyField();
        $ops = [['op' => 'add', 'itemtype' => Incident::class, 'ref' => 'inc', 'input' => $input]];
        foreach ((array) ($args['items'] ?? []) as $it) {
            $obj = Items::load((string) ($it['itemtype'] ?? ''), (int) ($it['id'] ?? 0), READ);
            $ops[] = ['op' => 'add', 'itemtype' => Incident_Item::class, 'input' => [$fk => '@ref:inc', 'itemtype' => $obj::class, 'items_id' => $obj->getID()]];
        }
        foreach (array_unique(array_filter(array_map('trim', (array) ($args['iocs'] ?? [])))) as $v) {
            $ops[] = ['op' => 'add', 'itemtype' => Ioc::class, 'input' => [$fk => '@ref:inc', 'value' => $v, 'ioc_type' => Ioc::guessType($v), 'source' => 'mcp']];
        }
        $ops[] = ['op' => 'add', 'itemtype' => TimelineEvent::class, 'input' => [
            $fk => '@ref:inc', 'event_type' => 'note', 'name' => 'Incident déclaré via l\'assistant IA', 'source' => 'mcp',
            'content' => Items::toHtml('Déclaration préparée par un assistant IA (GLPI MCP) et confirmée par ' . getUserName($ctx->users_id) . '.'),
        ]];

        $out = PendingActions::propose($ctx, $this->name(), sprintf(__('Declaration of security incident « %s »', 'glpimcp'), $input['name']), $ops);
        if (isset($warn)) {
            $out['avertissements'][] = $warn;
        }
        return $out;
    }
}
