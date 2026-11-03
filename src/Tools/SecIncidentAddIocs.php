<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Integrations\SecIncident;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use GlpiPlugin\Secincident\Incident;
use GlpiPlugin\Secincident\Ioc;

class SecIncidentAddIocs extends Tool
{
    public function name(): string
    {
        return 'glpi_secincident_add_iocs';
    }

    public function title(): string
    {
        return __('Add IOCs to an incident', 'glpimcp');
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
        return "Prépare l'ajout d'indicateurs de compromission à un incident (hash, IP, domaine, URL, mail, chemin, commande, clé de "
            . "registre, compte…). Le type est deviné si absent ; les doublons de l'incident sont écartés dans l'aperçu. "
            . "Verdict : 0 non évalué, 1 malveillant, 2 suspect, 3 légitime. Aperçu puis confirmation.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'incident_id' => self::int('Incident'),
            'iocs' => ['type' => 'array', 'description' => 'Indicateurs (chaîne seule, ou objet détaillé)', 'items' => [
                'anyOf' => [
                    ['type' => 'string'],
                    self::obj([
                        'value'    => self::str('Valeur'),
                        'type'     => self::str('Type (sha256, ipv4, domain, url, email, filepath, cmdline, registry, account…)'),
                        'verdict'  => self::int('0 non évalué, 1 malveillant, 2 suspect, 3 légitime'),
                        'comment'  => self::str('Commentaire'),
                        'first_seen' => self::str('Vu pour la première fois (AAAA-MM-JJ HH:MM)'),
                    ], ['value']),
                ],
            ]],
            'verdict' => self::int('Verdict par défaut pour les indicateurs sans verdict'),
        ], ['incident_id', 'iocs']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $incident = SecIncident::loadIncident((int) self::requireArg($args, 'incident_id'));
        if (!Incident::canActOn($incident->fields)) {
            throw new ToolException("Tu es observateur de cet incident : tu ne peux pas y ajouter d'indicateurs.");
        }
        $fk = Incident::getForeignKeyField();
        $types = Ioc::getIocTypes();
        $existing = array_flip(array_column((new Ioc())->find([$fk => $incident->getID()]), 'value_hash'));
        $ops = [];
        $skipped = [];
        $list = (array) self::requireArg($args, 'iocs');
        if (count($list) > 500) {
            throw new ToolException('500 indicateurs maximum par lot.');
        }
        foreach ($list as $raw) {
            $ioc = is_array($raw) ? $raw : ['value' => (string) $raw];
            $value = trim((string) ($ioc['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $type = (string) ($ioc['type'] ?? '') ?: Ioc::guessType($value);
            if (!isset($types[$type])) {
                throw new ToolException("Type d'IOC inconnu « {$type} ». Types : " . implode(', ', array_keys($types)) . '.');
            }
            $hash = Ioc::computeHash($type, $value);
            if (isset($existing[$hash])) {
                $skipped[] = $value;
                continue;
            }
            $existing[$hash] = true;
            $input = [$fk => $incident->getID(), 'value' => $value, 'ioc_type' => $type, 'source' => 'mcp',
                'verdict' => max(0, min(3, (int) ($ioc['verdict'] ?? $args['verdict'] ?? 0)))];
            if (!empty($ioc['comment'])) {
                $input['comment'] = (string) $ioc['comment'];
            }
            if (!empty($ioc['first_seen']) && ($ts = strtotime((string) $ioc['first_seen'])) !== false) {
                $input['date_first_seen'] = date('Y-m-d H:i:s', $ts);
            }
            $ops[] = ['op' => 'add', 'itemtype' => Ioc::class, 'input' => $input];
        }
        if ($ops === []) {
            throw new ToolException('Aucun nouvel indicateur : ' . ($skipped ? 'tous sont déjà dans l\'incident.' : 'liste vide.'));
        }
        $ref = $incident->fields['reference'] ?: ('#' . $incident->getID());
        $out = PendingActions::propose($ctx, $this->name(), sprintf(__('%1$d IOC in incident %2$s', 'glpimcp'), count($ops), $ref), $ops);
        if ($skipped) {
            $out['déjà_présents_ignorés'] = $skipped;
        }
        return $out;
    }
}
