<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Forms;

use Glpi\Form\Destination\CommonITILField as F;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\ToolException;

/**
 * Traduit une description simple de la « destination » d'un formulaire (le ticket créé
 * à la soumission) en configuration native GLPI 11, et inversement pour l'audit.
 *
 * Spécification acceptée (toutes les clés sont optionnelles) :
 *  - title       : titre du ticket, ex. "Arrivée de {Nom et prénom}" (texte)
 *  - entity      : "demandeur" | "utilisateur" (entité active de celui qui remplit) | "formulaire" | <id d'entité>
 *  - category    : <id de catégorie ITIL> | "question:<intitulé>"
 *  - location    : <id de lieu> | "question:<intitulé>"
 *  - urgency     : 1..5 | "question:<intitulé>"
 *  - type        : "incident" | "demande" | "question:<intitulé>"
 *  - requesters  : {"strategies": ["form_filler", ...], "users": [ids], "groups": [ids], "questions": [intitulés]}
 *  - observers, assignees : idem
 *  - validation  : {"users": [ids], "groups": [ids]} | {"questions": [intitulés]} | "aucune"
 */
final class DestinationBuilder
{
    public const OPERATOR_LABELS = [
        'equals' => '=', 'not_equals' => '≠', 'contains' => 'contient', 'not_contains' => 'ne contient pas',
        'greater_than' => '>', 'greater_than_or_equals' => '≥', 'less_than' => '<', 'less_than_or_equals' => '≤',
        'empty' => 'est vide', 'not_empty' => "n'est pas vide", 'visible' => 'est affichée', 'not_visible' => "n'est pas affichée",
        'match_regex' => 'correspond à', 'not_match_regex' => 'ne correspond pas à',
    ];

    /**
     * @param array<string, int> $question_ids intitulé (minuscules) → id de question
     */
    public static function buildConfig(array $spec, array $question_ids): array
    {
        $cfg = [];
        $q = function (string $ref) use ($question_ids): int {
            $name = mb_strtolower(trim(substr($ref, strlen('question:'))));
            if (!isset($question_ids[$name])) {
                throw new ToolException("Destination : question « {$name} » introuvable dans le formulaire.");
            }
            return $question_ids[$name];
        };
        $isQ = fn($v) => is_string($v) && str_starts_with($v, 'question:');

        if (isset($spec['title']) && trim((string) $spec['title']) !== '') {
            $cfg[F\TitleField::getKey()] = (new F\SimpleValueConfig(self::withAnswerTags((string) $spec['title'], $question_ids)))->jsonSerialize();
        }

        if (isset($spec['entity'])) {
            $e = $spec['entity'];
            $cfg[F\EntityField::getKey()] = match (true) {
                $e === 'demandeur'   => (new F\EntityFieldConfig(F\EntityFieldStrategy::REQUESTER_ENTITY))->jsonSerialize(),
                $e === 'utilisateur' => (new F\EntityFieldConfig(F\EntityFieldStrategy::FORM_FILLER))->jsonSerialize(),
                $e === 'formulaire'  => (new F\EntityFieldConfig(F\EntityFieldStrategy::FROM_FORM))->jsonSerialize(),
                is_numeric($e)       => (new F\EntityFieldConfig(F\EntityFieldStrategy::SPECIFIC_VALUE, specific_entity_id: (int) $e))->jsonSerialize(),
                $isQ($e)             => (new F\EntityFieldConfig(F\EntityFieldStrategy::SPECIFIC_ANSWER, specific_question_id: $q($e)))->jsonSerialize(),
                default              => throw new ToolException('Destination : entity doit valoir demandeur, utilisateur, formulaire, un id ou question:<intitulé>.'),
            };
        }

        if (isset($spec['category'])) {
            $c = $spec['category'];
            $cfg[F\ITILCategoryField::getKey()] = $isQ($c)
                ? (new F\ITILCategoryFieldConfig(F\ITILCategoryFieldStrategy::SPECIFIC_ANSWER, specific_question_id: $q($c)))->jsonSerialize()
                : (new F\ITILCategoryFieldConfig(F\ITILCategoryFieldStrategy::SPECIFIC_VALUE, specific_itilcategory_id: (int) $c))->jsonSerialize();
        }

        if (isset($spec['location'])) {
            $l = $spec['location'];
            $cfg[F\LocationField::getKey()] = $isQ($l)
                ? (new F\LocationFieldConfig(F\LocationFieldStrategy::SPECIFIC_ANSWER, specific_question_id: $q($l)))->jsonSerialize()
                : (new F\LocationFieldConfig(F\LocationFieldStrategy::SPECIFIC_VALUE, specific_location_id: (int) $l))->jsonSerialize();
        }

        if (isset($spec['urgency'])) {
            $u = $spec['urgency'];
            $cfg[F\UrgencyField::getKey()] = $isQ($u)
                ? (new F\UrgencyFieldConfig(F\UrgencyFieldStrategy::SPECIFIC_ANSWER, specific_question_id: $q($u)))->jsonSerialize()
                : (new F\UrgencyFieldConfig(F\UrgencyFieldStrategy::SPECIFIC_VALUE, specific_urgency_value: max(1, min(5, (int) $u))))->jsonSerialize();
        }

        if (isset($spec['request_type']) || isset($spec['type'])) {
            $t = $spec['request_type'] ?? $spec['type'];
            $cfg[F\RequestTypeField::getKey()] = $isQ($t)
                ? (new F\RequestTypeFieldConfig(F\RequestTypeFieldStrategy::SPECIFIC_ANSWER, specific_question_id: $q($t)))->jsonSerialize()
                : (new F\RequestTypeFieldConfig(F\RequestTypeFieldStrategy::SPECIFIC_VALUE, specific_request_type: $t === 'incident' ? \Ticket::INCIDENT_TYPE : \Ticket::DEMAND_TYPE))->jsonSerialize();
        }

        foreach (['requesters' => [F\RequesterField::class, F\RequesterFieldConfig::class], 'observers' => [F\ObserverField::class, F\ObserverFieldConfig::class], 'assignees' => [F\AssigneeField::class, F\AssigneeFieldConfig::class]] as $key => [$field, $config]) {
            if (!isset($spec[$key]) || !is_array($spec[$key])) {
                continue;
            }
            $a = $spec[$key];
            $strategies = [];
            $actors = [];
            $qids = [];
            foreach ((array) ($a['strategies'] ?? []) as $s) {
                $strategies[] = F\ITILActorFieldStrategy::tryFrom((string) $s) ?? throw new ToolException("Destination : stratégie d'acteur « {$s} » inconnue.");
            }
            if (!empty($a['users'])) {
                $actors['User'] = array_map('intval', (array) $a['users']);
            }
            if (!empty($a['groups'])) {
                $actors['Group'] = array_map('intval', (array) $a['groups']);
            }
            if ($actors !== []) {
                $strategies[] = F\ITILActorFieldStrategy::SPECIFIC_VALUES;
            }
            foreach ((array) ($a['questions'] ?? []) as $qn) {
                $qids[] = $q('question:' . $qn);
            }
            if ($qids !== []) {
                $strategies[] = F\ITILActorFieldStrategy::SPECIFIC_ANSWERS;
            }
            if ($strategies === []) {
                continue;
            }
            $cfg[$field::getKey()] = (new $config(array_values(array_unique($strategies, SORT_REGULAR)), $actors, $qids))->jsonSerialize();
        }

        if (isset($spec['validation'])) {
            $v = $spec['validation'];
            if ($v === 'aucune' || $v === [] || $v === null) {
                $sc = new F\ValidationFieldStrategyConfig(F\ValidationFieldStrategy::NO_VALIDATION);
            } elseif (!empty($v['questions'])) {
                $sc = new F\ValidationFieldStrategyConfig(F\ValidationFieldStrategy::SPECIFIC_ANSWERS, specific_question_ids: array_map(fn($n) => $q('question:' . $n), (array) $v['questions']));
            } else {
                $actors = [];
                if (!empty($v['users'])) {
                    $actors['User'] = array_map('intval', (array) $v['users']);
                }
                if (!empty($v['groups'])) {
                    $actors['Group'] = array_map('intval', (array) $v['groups']);
                }
                if ($actors === []) {
                    throw new ToolException('Destination : validation sans valideur.');
                }
                $sc = new F\ValidationFieldStrategyConfig(F\ValidationFieldStrategy::SPECIFIC_ACTORS, specific_actors: $actors);
            }
            $cfg[F\ValidationField::getKey()] = (new F\ValidationFieldConfig([$sc]))->jsonSerialize();
        }

        return $cfg;
    }

    /**
     * Applique la configuration à la destination « Ticket » du formulaire (créée par défaut par GLPI).
     */
    /**
     * Remplace {{Intitulé}} (ou {Intitulé}) par une balise « Réponse : … » native GLPI,
     * remplacée par la réponse à la soumission. Le reste du texte est échappé.
     *
     * @param array<string, int> $question_ids intitulé (minuscules) → id de question
     */
    public static function withAnswerTags(string $text, array $question_ids): string
    {
        $provider = new \Glpi\Form\Tag\AnswerTagProvider();
        $parts = preg_split('/(\{\{[^{}]+\}\}|\{[^{}]+\})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{\{?([^{}]+)\}?\}$/u', $part, $m)) {
                $name = mb_strtolower(trim($m[1]));
                if (!isset($question_ids[$name])) {
                    throw new ToolException("Titre : question « {$m[1]} » introuvable dans le formulaire.");
                }
                $question = \Glpi\Form\Question::getById($question_ids[$name]);
                if ($question) {
                    $out .= $provider->getTagForQuestion($question)->html;
                    continue;
                }
            }
            $out .= htmlescape($part);
        }
        return $out;
    }

    public static function apply(Form $form, array $config): void
    {
        global $DB;
        if ($config === []) {
            return;
        }
        $dest = new FormDestination();
        $rows = $dest->find(['forms_forms_id' => $form->getID(), 'itemtype' => FormDestinationTicket::class], ['id ASC'], 1);
        if ($rows === []) {
            $id = $dest->add(['forms_forms_id' => $form->getID(), 'itemtype' => FormDestinationTicket::class, 'name' => 'Ticket']);
            if (!$id) {
                throw new ToolException('Impossible de créer la destination Ticket du formulaire.');
            }
            $row = ['id' => $id, 'config' => '[]'];
        } else {
            $row = reset($rows);
        }
        $current = json_decode((string) $row['config'], true) ?: [];
        // Écriture directe du JSON natif (les objets de config ont été construits/validés via les classes GLPI)
        $DB->update(FormDestination::getTable(), ['config' => json_encode(array_merge($current, $config))], ['id' => $row['id']]);
    }

    /** Écrit la configuration d'une destination précise. */
    public static function applyTo(int $destination_id, array $config): void
    {
        global $DB;
        if ($config === []) {
            return;
        }
        $row = $DB->request(['FROM' => FormDestination::getTable(), 'WHERE' => ['id' => $destination_id]])->current();
        $current = json_decode((string) ($row['config'] ?? '[]'), true) ?: [];
        $DB->update(FormDestination::getTable(), ['config' => json_encode(array_merge($current, $config))], ['id' => $destination_id]);
    }

        /**
     * Résumé lisible des destinations d'un formulaire (pour l'audit et la consolidation).
     */
    public static function describe(Form $form): array
    {
        $out = [];
        $names = [];
        foreach ($form->getQuestions() as $question) {
            $names[$question->getID()] = $question->fields['name'];
        }
        $qn = fn($ids) => array_values(array_map(fn($id) => $names[$id] ?? "#{$id}", (array) $ids));
        $actor = function (array $map): array {
            $res = [];
            foreach ($map as $itemtype => $ids) {
                foreach ((array) $ids as $id) {
                    $res[] = match ($itemtype) {
                        'User'     => ['utilisateur' => getUserName($id), 'id' => (int) $id],
                        'Group'    => ['groupe' => \Dropdown::getDropdownName('glpi_groups', $id), 'id' => (int) $id],
                        'Supplier' => ['fournisseur' => \Dropdown::getDropdownName('glpi_suppliers', $id), 'id' => (int) $id],
                        default    => [$itemtype => (int) $id],
                    };
                }
            }
            return $res;
        };

        foreach ((new FormDestination())->find(['forms_forms_id' => $form->getID()]) as $row) {
            $c = json_decode((string) $row['config'], true) ?: [];
            $d = ['id' => (int) $row['id'], 'nom' => $row['name'], 'type' => $row['itemtype']];
            $strategy = $row['creation_strategy'] ?: 'always_created';
            $d['création'] = match ($strategy) {
                'created_if'     => 'si',
                'created_unless' => 'sauf si',
                default          => 'toujours',
            };
            if ($strategy !== 'always_created') {
                $uuid_names = [];
                foreach ($form->getQuestions() as $question) {
                    $extra = json_decode((string) $question->fields['extra_data'], true) ?: [];
                    $uuid_names[$question->fields['uuid']] = [$question->fields['name'], $extra['options'] ?? []];
                }
                foreach (json_decode((string) $row['conditions'], true) ?: [] as $cond) {
                    [$qname, $opts] = $uuid_names[$cond['item_uuid'] ?? ''] ?? ['?', []];
                    $val = $cond['value'] ?? '';
                    $val = is_array($val) ? implode('/', array_map(fn($v) => $opts[$v] ?? $v, $val)) : ($opts[$val] ?? $val);
                    $d['conditions'][] = sprintf('%s« %s » %s %s', isset($d['conditions']) ? ($cond['logic_operator'] ?? 'and') === 'or' ? 'OU ' : 'ET ' : '', $qname, self::OPERATOR_LABELS[$cond['value_operator'] ?? ''] ?? ($cond['value_operator'] ?? ''), $val);
                }
            }
            if ($v = $c[F\TitleField::getKey()]['value'] ?? null) {
                $d['titre'] = Items::text(html_entity_decode((string) $v), 200);
            }
            if ($e = $c[F\EntityField::getKey()] ?? null) {
                $d['entité'] = ($e['strategy'] ?? '') . (!empty($e['specific_entity_id']) ? ' : ' . \Dropdown::getDropdownName('glpi_entities', $e['specific_entity_id']) : '');
            } else {
                $d['entité'] = 'défaut GLPI (entité du formulaire)';
            }
            if ($cat = $c[F\ITILCategoryField::getKey()] ?? null) {
                $d['catégorie'] = !empty($cat['specific_itilcategory_id'])
                    ? ['id' => (int) $cat['specific_itilcategory_id'], 'nom' => \Dropdown::getDropdownName('glpi_itilcategories', $cat['specific_itilcategory_id'])]
                    : ($cat['strategy'] ?? '') . (!empty($cat['specific_question_id']) ? ' : ' . implode('', $qn([$cat['specific_question_id']])) : '');
            }
            if ($val = $c[F\ValidationField::getKey()] ?? null) {
                $list = [];
                foreach ((array) ($val['strategy_configs'] ?? []) as $sc) {
                    $entry = ['stratégie' => $sc['strategy'] ?? ''];
                    if (!empty($sc['specific_actors'])) {
                        $entry['valideurs'] = $actor($sc['specific_actors']);
                    }
                    if (!empty($sc['specific_question_ids'])) {
                        $entry['questions'] = $qn($sc['specific_question_ids']);
                    }
                    $list[] = $entry;
                }
                $d['validation'] = $list;
            }
            foreach (['demandeurs' => F\RequesterField::getKey(), 'observateurs' => F\ObserverField::getKey(), 'attribués' => F\AssigneeField::getKey()] as $label => $key) {
                if ($a = $c[$key] ?? null) {
                    $d[$label] = ['stratégies' => $a['strategies'] ?? [], 'acteurs' => $actor($a['specific_itilactors_ids'] ?? [])];
                }
            }
            $out[] = $d;
        }
        return $out;
    }
}
