<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Forms;

use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Form\Comment;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType;
use Glpi\Form\Section;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Session;

/**
 * Conception de formulaires GLPI 11 (natifs) à partir d'une spécification simple,
 * produite par l'IA à partir d'une demande en langage naturel.
 */
final class FormBuilder
{
    /**
     * Types de questions exposés à l'IA → classe GLPI + aide.
     */
    public const TYPES = [
        'texte_court'  => [QuestionType\QuestionTypeShortText::class, 'Une ligne de texte', 'chaîne'],
        'texte_long'   => [QuestionType\QuestionTypeLongText::class, 'Zone de texte riche (plusieurs lignes)', 'chaîne'],
        'email'        => [QuestionType\QuestionTypeEmail::class, 'Adresse e-mail', 'chaîne e-mail'],
        'nombre'       => [QuestionType\QuestionTypeNumber::class, 'Nombre', 'nombre'],
        'date'         => [QuestionType\QuestionTypeDateTime::class, 'Date', 'AAAA-MM-JJ'],
        'date_heure'   => [QuestionType\QuestionTypeDateTime::class, 'Date et heure', 'AAAA-MM-JJ HH:MM'],
        'heure'        => [QuestionType\QuestionTypeDateTime::class, 'Heure', 'HH:MM'],
        'choix_unique' => [QuestionType\QuestionTypeRadio::class, 'Boutons radio (une seule réponse) — options obligatoires', 'libellé de l\'option'],
        'choix_multiple' => [QuestionType\QuestionTypeCheckbox::class, 'Cases à cocher (plusieurs réponses) — options obligatoires', 'liste de libellés'],
        'liste'        => [QuestionType\QuestionTypeDropdown::class, 'Liste déroulante — options obligatoires, multiple=true possible', 'libellé ou liste de libellés'],
        'demandeur'    => [QuestionType\QuestionTypeRequester::class, 'Demandeur(s) du ticket (utilisateurs/groupes GLPI)', 'id utilisateur ou liste'],
        'observateur'  => [QuestionType\QuestionTypeObserver::class, 'Observateur(s) du ticket', 'id utilisateur ou liste'],
        'technicien'   => [QuestionType\QuestionTypeAssignee::class, 'Technicien/groupe attribué', 'id utilisateur ou liste'],
        'urgence'      => [QuestionType\QuestionTypeUrgency::class, 'Urgence du ticket (1 à 5)', '1 à 5'],
        'type_demande' => [QuestionType\QuestionTypeRequestType::class, 'Type : incident ou demande', '1=incident, 2=demande'],
        'categorie'    => [QuestionType\QuestionTypeItemDropdown::class, 'Catégorie ITIL du ticket', 'id de catégorie', ['itemtype' => 'ITILCategory']],
        'lieu'         => [QuestionType\QuestionTypeItemDropdown::class, 'Lieu GLPI', 'id de lieu', ['itemtype' => 'Location']],
        'objet_glpi'   => [QuestionType\QuestionTypeItem::class, 'Sélection d\'un objet GLPI (préciser itemtype : Computer, Software, User…)', 'id de l\'objet'],
        'intitule_glpi' => [QuestionType\QuestionTypeItemDropdown::class, 'Sélection dans un intitulé GLPI (préciser itemtype : Manufacturer, State…)', 'id'],
        'mes_materiels' => [QuestionType\QuestionTypeUserDevice::class, 'Matériels de l\'utilisateur connecté', '"Computer_12"'],
        'fichier'      => [QuestionType\QuestionTypeFile::class, 'Pièce jointe (non remplissable via l\'IA)', '—'],
    ];

    /**
     * Valide la spec et renvoie un aperçu lisible.
     */
    public static function preview(array $spec): array
    {
        $norm = self::normalize($spec);
        if (!Form::canCreate()) {
            throw new ToolException('Ton profil ne permet pas de créer des formulaires (droit « Formulaires : créer »).');
        }
        $sections = [];
        foreach ($norm['sections'] as $s) {
            $qs = [];
            foreach ($s['questions'] as $q) {
                $line = sprintf('%s [%s]%s', $q['name'], $q['alias'], $q['is_mandatory'] ? ' *obligatoire*' : '');
                if (!empty($q['options'])) {
                    $line .= ' — options : ' . implode(' / ', $q['options']);
                }
                if ($q['condition'] !== null) {
                    $c = $q['condition'];
                    $line .= sprintf(' — %s si « %s » %s %s', $c['mode'] === 'visible_if' ? 'visible' : 'masquée', $c['question'], $c['operator'], is_array($c['value']) ? implode('/', $c['value']) : (string) $c['value']);
                }
                $qs[] = $line;
            }
            $sections[] = ['section' => $s['name'], 'questions' => $qs];
        }
        return [
            'action'      => 'créer un formulaire',
            'nom'         => $norm['name'],
            'description' => Items::text($norm['description'], 300),
            'catégorie_du_catalogue' => $norm['forms_categories_id'] ?: null,
            'actif'       => (bool) $norm['is_active'],
            'accès'       => $norm['access_label'],
            'sections'    => $sections,
            'brouillon'   => (bool) $norm['is_draft'],
            'à_la_soumission' => $norm['destinations'] !== []
                ? array_map(fn($d) => [
                    'crée'   => $d['label'] . ' « ' . $d['name'] . ' »',
                    'quand'  => self::humanWhen($d),
                ] + self::humanDestination($d['config']), $norm['destinations'])
                : ($norm['destination'] === [] ? 'un ticket avec la configuration par défaut de GLPI' : ['un ticket' => self::humanDestination($norm['destination'])]),
            'traductions' => array_keys($norm['translations']),
        ];
    }

    /**
     * Crée réellement le formulaire (appelé dans la transaction de confirmation).
     */
    public static function build(array $spec): array
    {
        $norm = self::normalize($spec);
        if (!Form::canCreate()) {
            throw new ToolException('Droit de création de formulaire manquant.');
        }

        $form = new Form();
        $form_id = $form->add([
            'name'                  => $norm['name'],
            'description'           => $norm['description'],
            'header'                => $norm['header'],
            'entities_id'           => $norm['entities_id'],
            'is_recursive'          => $norm['is_recursive'],
            'is_active'             => $norm['is_active'],
            'is_draft'              => $norm['is_draft'],
            'forms_categories_id'   => $norm['forms_categories_id'],
            '_init_sections'        => false,
            '_init_access_policies' => true,
            '_init_destinations'    => $norm['destinations'] === [],
        ]);
        if (!$form_id) {
            throw new ToolException('GLPI a refusé la création du formulaire.', ['messages_glpi' => Items::flushMessages()]);
        }

        $nb_questions = 0;
        $created = []; // intitulé (minuscules) → ['id', 'uuid', 'options', 'cond']
        foreach ($norm['sections'] as $rank => $s) {
            $section = new Section();
            $section_id = $section->add([
                'forms_forms_id' => $form_id,
                'name'           => $s['name'],
                'description'    => $s['description'],
                'rank'           => $rank,
            ]);
            if (!$section_id) {
                throw new ToolException("Échec de création de la section « {$s['name']} ».");
            }
            $vrank = 0;
            if ($s['comment'] !== '') {
                (new Comment())->add([
                    'forms_sections_id' => $section_id,
                    'name'              => $s['name'],
                    'description'       => Items::toHtml($s['comment']),
                    'vertical_rank'     => $vrank++,
                ]);
            }
            foreach ($s['questions'] as $q) {
                $question = new Question();
                $ok = $question->add([
                    'forms_sections_id' => $section_id,
                    'name'              => $q['name'],
                    'type'              => $q['class'],
                    'is_mandatory'      => $q['is_mandatory'],
                    'description'       => $q['description'],
                    'default_value'     => $q['default_value'],
                    'extra_data'        => $q['extra_data'],
                    'vertical_rank'     => $vrank++,
                ]);
                if (!$ok) {
                    throw new ToolException("Échec de création de la question « {$q['name']} ».", ['messages_glpi' => Items::flushMessages()]);
                }
                $nb_questions++;
                $created[mb_strtolower(trim($q['name']))] = [
                    'id'      => (int) $ok,
                    'uuid'    => $question->fields['uuid'],
                    'options' => $q['option_map'],
                    'cond'    => $q['condition'],
                ];
            }
        }

        // Restriction d'accès éventuelle
        if ($norm['access'] !== null) {
            $control = new FormAccessControl();
            $existing = $control->find(['forms_forms_id' => $form_id, 'strategy' => AllowList::class]);
            $config = new AllowListConfig(
                user_ids: $norm['access']['users'],
                group_ids: $norm['access']['groups'],
                profile_ids: $norm['access']['profiles'],
            );
            if ($existing) {
                $row = reset($existing);
                $control->update(['id' => $row['id'], '_config' => $config, 'is_active' => 1]);
            } else {
                $control->add(['forms_forms_id' => $form_id, 'strategy' => AllowList::class, '_config' => $config, 'is_active' => 1]);
            }
        }

        // Conditions d'affichage (après création de toutes les questions : il faut leurs uuid)
        foreach ($created as $name => $info) {
            if ($info['cond'] === null) {
                continue;
            }
            $c = $info['cond'];
            $question = new Question();
            $question->getFromDB($info['id']);
            $question->update([
                'id'                  => $info['id'],
                'visibility_strategy' => $c['mode'],
                '_conditions'         => [self::conditionRow($created, $c, "Condition de « {$name} »")],
            ]);
        }

        // Destination (ticket créé) : entité, titre, catégorie, validation, acteurs…
        $question_ids = array_map(fn($i) => $i['id'], $created);
        $nb_destinations = 1;
        if ($norm['destinations'] !== []) {
            // Destinations multiples, éventuellement conditionnelles (ex : un ticket toujours + un changement si « Serveur »)
            $nb_destinations = 0;
            foreach ($norm['destinations'] as $d) {
                self::addDestination($form, $d, $created);
                $nb_destinations++;
            }
        } elseif ($norm['destination'] !== []) {
            DestinationBuilder::apply($form, DestinationBuilder::buildConfig($norm['destination'], $question_ids));
        }

        // Traductions
        $nb_translations = 0;
        foreach ($norm['translations'] as $lang => $t) {
            $nb_translations += self::addTranslations($form, (string) $lang, (array) $t, $created);
        }

        $form->getFromDB($form_id);
        return [
            'id'          => (int) $form_id,
            'nom'         => $form->getName(),
            'nb_questions' => $nb_questions,
            'nb_traductions' => $nb_translations,
            'nb_destinations' => $nb_destinations,
            'brouillon'   => (bool) $form->fields['is_draft'],
            'actif'       => (bool) $form->fields['is_active'],
            'url_édition' => Items::url($form),
            'url_remplissage' => rtrim($GLOBALS['CFG_GLPI']['url_base'] ?? '', '/') . '/Form/Render/' . $form_id,
            'messages_glpi' => Items::flushMessages(),
        ];
    }

    /**
     * Normalise / valide la spécification fournie par l'IA.
     */
    public static function normalize(array $spec): array
    {
        $name = trim((string) ($spec['name'] ?? ''));
        if ($name === '') {
            throw new ToolException('Le formulaire doit avoir un nom.');
        }
        $sections_in = $spec['sections'] ?? null;
        if (!is_array($sections_in) || $sections_in === []) {
            // Autoriser une liste de questions à plat
            if (!empty($spec['questions']) && is_array($spec['questions'])) {
                $sections_in = [['name' => $name, 'questions' => $spec['questions']]];
            } else {
                throw new ToolException('Le formulaire doit contenir au moins une section avec des questions.');
            }
        }

        $sections = [];
        foreach ($sections_in as $si => $s) {
            $questions = [];
            foreach ((array) ($s['questions'] ?? []) as $qi => $q) {
                $questions[] = self::normalizeQuestion((array) $q, $si + 1, $qi + 1);
            }
            $sections[] = [
                'name'        => trim((string) ($s['name'] ?? ('Section ' . ($si + 1)))),
                'description' => Items::toHtml((string) ($s['description'] ?? '')),
                'comment'     => (string) ($s['comment'] ?? ''),
                'questions'   => $questions,
            ];
        }

        $access = null;
        $access_label = 'tous les utilisateurs connectés';
        if (!empty($spec['access']) && is_array($spec['access'])) {
            $access = [
                'users'    => array_map('intval', (array) ($spec['access']['users_id'] ?? [])),
                'groups'   => array_map('intval', (array) ($spec['access']['groups_id'] ?? [])),
                'profiles' => array_map('intval', (array) ($spec['access']['profiles_id'] ?? [])),
            ];
            if ($access['users'] === [] && $access['groups'] === [] && $access['profiles'] === []) {
                $access = null;
            } else {
                $parts = [];
                foreach ($access['users'] as $u) {
                    $parts[] = 'utilisateur ' . getUserName($u);
                }
                foreach ($access['groups'] as $g) {
                    $parts[] = 'groupe ' . \Dropdown::getDropdownName('glpi_groups', $g);
                }
                foreach ($access['profiles'] as $p) {
                    $parts[] = 'profil ' . \Dropdown::getDropdownName('glpi_profiles', $p);
                }
                $access_label = 'réservé à : ' . implode(', ', $parts);
            }
        }

        return [
            'name'                => mb_substr($name, 0, 255),
            'description'         => Items::toHtml((string) ($spec['description'] ?? '')),
            'header'              => Items::toHtml((string) ($spec['header'] ?? '')),
            'entities_id'         => isset($spec['entities_id']) ? (int) $spec['entities_id'] : (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'is_recursive'        => (int) ($spec['is_recursive'] ?? 1),
            'is_active'           => (int) ($spec['is_active'] ?? 1),
            'is_draft'            => (int) !empty($spec['is_draft']),
            'destination'         => is_array($spec['destination'] ?? null) ? $spec['destination'] : [],
            'destinations'        => self::normalizeDestinations($spec['destinations'] ?? []),
            'translations'        => is_array($spec['translations'] ?? null) ? $spec['translations'] : [],
            'forms_categories_id' => (int) ($spec['forms_categories_id'] ?? 0),
            'sections'            => $sections,
            'access'              => $access,
            'access_label'        => $access_label,
        ];
    }

    private static function normalizeQuestion(array $q, int $s, int $n): array
    {
        $alias = strtolower(trim((string) ($q['type'] ?? 'texte_court')));
        if (!isset(self::TYPES[$alias])) {
            throw new ToolException("Section {$s}, question {$n} : type « {$alias} » inconnu. Types possibles : " . implode(', ', array_keys(self::TYPES)));
        }
        $def   = self::TYPES[$alias];
        $class = $def[0];
        $name  = trim((string) ($q['name'] ?? ''));
        if ($name === '') {
            throw new ToolException("Section {$s}, question {$n} : intitulé manquant.");
        }

        $extra   = $def[3] ?? [];
        $default = $q['default'] ?? null;
        $options = [];

        if (in_array($alias, ['choix_unique', 'choix_multiple', 'liste'], true)) {
            $labels = array_values(array_filter(array_map(fn($o) => trim((string) $o), (array) ($q['options'] ?? [])), fn($o) => $o !== ''));
            if (count($labels) < 2) {
                throw new ToolException("Question « {$name} » : au moins 2 options sont nécessaires.");
            }
            $map = [];
            foreach ($labels as $i => $label) {
                $map[(string) ($i + 1)] = $label;
            }
            $extra = ['options' => $map];
            if ($alias === 'liste') {
                $extra['is_multiple_dropdown'] = !empty($q['multiple']);
            }
            $options = $labels;
            if ($default !== null) {
                $keys = [];
                foreach ((array) $default as $d) {
                    $k = array_search((string) $d, $map, true);
                    if ($k !== false) {
                        $keys[] = $k;
                    }
                }
                $default = $alias === 'choix_unique' ? ($keys[0] ?? null) : $keys;
            }
        } elseif (in_array($alias, ['date', 'date_heure', 'heure'], true)) {
            $extra = [
                'is_default_value_current_time' => !empty($q['default_now']),
                'is_date_enabled'               => $alias !== 'heure',
                'is_time_enabled'               => $alias !== 'date',
            ];
        } elseif (in_array($alias, ['objet_glpi', 'intitule_glpi', 'categorie', 'lieu'], true)) {
            $itemtype = (string) ($q['itemtype'] ?? ($extra['itemtype'] ?? ''));
            if ($itemtype === '') {
                throw new ToolException("Question « {$name} » : préciser itemtype (ex : Computer, Software, Manufacturer).");
            }
            $extra = [
                'itemtype'             => Items::resolveType($itemtype),
                'root_items_id'        => (int) ($q['root_id'] ?? 0),
                'subtree_depth'        => (int) ($q['subtree_depth'] ?? 0),
                'selectable_tree_root' => false,
            ];
            if ($alias !== 'objet_glpi') {
                $extra['categories_filter'] = [];
            }
            if ($default !== null) {
                $default = ['itemtype' => $extra['itemtype'], 'items_id' => (int) $default];
            }
        } elseif (in_array($alias, ['demandeur', 'observateur', 'technicien'], true)) {
            $extra = ['is_multiple_actors' => !empty($q['multiple'])];
            if ($default !== null) {
                $default = array_map(fn($u) => 'users_id-' . (int) $u, (array) $default);
            } elseif ($alias === 'demandeur') {
                $default = null;
            }
        }

        return [
            'alias'         => $alias,
            'class'         => $class,
            'name'          => mb_substr($name, 0, 255),
            'description'   => Items::toHtml((string) ($q['description'] ?? '')),
            'is_mandatory'  => !empty($q['mandatory']) ? 1 : 0,
            'extra_data'    => $extra === [] ? null : json_encode($extra),
            'default_value' => $default,
            'options'       => $options,
            'option_map'    => isset($map) ? $map : [],
            'condition'     => self::normalizeCondition($q, $name),
        ];
    }

    /** Libellé traduit dans la langue de l'utilisateur (traductions GLPI 11), sinon l'original */
    public static function tr(object $item, string $key, string $fallback): string
    {
        try {
            $t = \Glpi\Form\FormTranslation::translate($item, $key);
        } catch (\Throwable) {
            $t = null;
        }
        return $t !== null && $t !== '' ? (string) $t : $fallback;
    }

    private static function humanDestination(array $d): array
    {
        $out = [];
        $label = fn($v, string $table) => is_numeric($v) ? \Dropdown::getDropdownName($table, (int) $v) . " (#{$v})" : (string) $v;
        if (isset($d['title'])) {
            $out['titre'] = $d['title'];
        }
        if (isset($d['entity'])) {
            $out['entité'] = match ($d['entity']) {
                'demandeur'   => 'entité du demandeur',
                'utilisateur' => 'entité active de la personne qui remplit',
                'formulaire'  => 'entité du formulaire',
                default       => $label($d['entity'], 'glpi_entities'),
            };
        }
        if (isset($d['category'])) {
            $out['catégorie'] = $label($d['category'], 'glpi_itilcategories');
        }
        if (isset($d['location'])) {
            $out['lieu'] = $label($d['location'], 'glpi_locations');
        }
        foreach (['requesters' => 'demandeurs', 'observers' => 'observateurs', 'assignees' => 'attribués', 'validation' => 'validation'] as $k => $l) {
            if (!isset($d[$k])) {
                continue;
            }
            if (!is_array($d[$k])) {
                $out[$l] = $d[$k];
                continue;
            }
            $parts = array_merge(
                $d[$k]['strategies'] ?? [],
                array_map(fn($u) => 'utilisateur ' . getUserName((int) $u), (array) ($d[$k]['users'] ?? [])),
                array_map(fn($g) => is_numeric($g) ? 'groupe ' . \Dropdown::getDropdownName('glpi_groups', (int) $g) : 'groupe ' . $g, (array) ($d[$k]['groups'] ?? [])),
                array_map(fn($q) => 'réponse à « ' . $q . ' »', (array) ($d[$k]['questions'] ?? []))
            );
            $out[$l] = implode(', ', $parts);
        }
        return $out;
    }

    public const DESTINATION_TYPES = [
        'ticket'     => [\Glpi\Form\Destination\FormDestinationTicket::class, 'un ticket'],
        'changement' => [\Glpi\Form\Destination\FormDestinationChange::class, 'un changement'],
        'probleme'   => [\Glpi\Form\Destination\FormDestinationProblem::class, 'un problème'],
    ];

    /**
     * destinations = [{ "type": "ticket|changement|probleme", "name": "…",
     *                   "when": "toujours" | {"mode": "si|sauf_si", "conditions": [{"question","operator","value","logic":"et|ou"}]},
     *                   …champs de destination (title, entity, category, validation, assignees…) }]
     */
    private static function normalizeDestinations(mixed $list): array
    {
        if (!is_array($list) || $list === []) {
            return [];
        }
        $out = [];
        foreach (array_values($list) as $i => $d) {
            if (!is_array($d)) {
                throw new ToolException('Destination ' . ($i + 1) . ' invalide.');
            }
            $type = strtolower(str_replace(['è', 'é'], 'e', (string) ($d['type'] ?? 'ticket')));
            $def = self::DESTINATION_TYPES[$type] ?? throw new ToolException("Destination " . ($i + 1) . " : type « {$type} » inconnu (ticket, changement, probleme).");
            $when = $d['when'] ?? 'toujours';
            $strategy = 'always_created';
            $conditions = [];
            if (is_array($when)) {
                $mode = (string) ($when['mode'] ?? 'si');
                $strategy = in_array($mode, ['sauf_si', 'unless', 'created_unless'], true) ? 'created_unless' : 'created_if';
                foreach ((array) ($when['conditions'] ?? []) as $c) {
                    if (empty($c['question'])) {
                        throw new ToolException("Destination " . ($i + 1) . " : chaque condition doit préciser la question.");
                    }
                    $op = (string) ($c['operator'] ?? 'equals');
                    if (\Glpi\Form\Condition\ValueOperator::tryFrom($op) === null) {
                        throw new ToolException("Destination " . ($i + 1) . " : opérateur « {$op} » inconnu.");
                    }
                    $conditions[] = [
                        'question' => (string) $c['question'],
                        'operator' => $op,
                        'value'    => $c['value'] ?? '',
                        'logic'    => in_array(strtolower((string) ($c['logic'] ?? 'et')), ['ou', 'or'], true) ? 'or' : 'and',
                    ];
                }
                if ($conditions === []) {
                    throw new ToolException("Destination " . ($i + 1) . " : « when » conditionnel sans condition.");
                }
            }
            $config = $d;
            unset($config['type'], $config['name'], $config['when']);
            if (isset($config['type_ticket'])) {
                $config['request_type'] = $config['type_ticket'];
                unset($config['type_ticket']);
            }
            $out[] = [
                'class'      => $def[0],
                'label'      => $def[1],
                'name'       => mb_substr(trim((string) ($d['name'] ?? ucfirst($type))), 0, 255),
                'strategy'   => $strategy,
                'conditions' => $conditions,
                'config'     => $config,
            ];
        }
        return $out;
    }

    /** Carte intitulé → infos de question, pour un formulaire existant */
    public static function questionMap(Form $form): array
    {
        $map = [];
        foreach ($form->getQuestions() as $q) {
            $extra = json_decode((string) $q->fields['extra_data'], true) ?: [];
            $map[mb_strtolower(trim($q->fields['name']))] = ['id' => $q->getID(), 'uuid' => $q->fields['uuid'], 'options' => $extra['options'] ?? [], 'cond' => null];
        }
        return $map;
    }

    /**
     * Ajoute une destination (normalisée) à un formulaire. Renvoie son id.
     */
    public static function addDestination(Form $form, array $d, ?array $created = null): int
    {
        $created ??= self::questionMap($form);
        $did = (new \Glpi\Form\Destination\FormDestination())->add([
            'forms_forms_id'    => $form->getID(),
            'itemtype'          => $d['class'],
            'name'              => $d['name'],
            'creation_strategy' => $d['strategy'],
            '_conditions'       => self::destinationConditions($d, $created),
        ]);
        if (!$did) {
            throw new ToolException("Échec de création de la destination « {$d['name']} ».", ['messages_glpi' => Items::flushMessages()]);
        }
        DestinationBuilder::applyTo((int) $did, DestinationBuilder::buildConfig($d['config'], array_map(fn($i) => $i['id'], $created)));
        return (int) $did;
    }

    /**
     * Met à jour une destination existante (quand / type de création, champs).
     */
    public static function updateDestination(Form $form, int $destination_id, array $d): void
    {
        $created = self::questionMap($form);
        $dest = new \Glpi\Form\Destination\FormDestination();
        if (!$dest->getFromDB($destination_id) || (int) $dest->fields['forms_forms_id'] !== $form->getID()) {
            throw new ToolException("Destination #{$destination_id} introuvable dans ce formulaire.");
        }
        $input = ['id' => $destination_id, 'name' => $d['name'], 'creation_strategy' => $d['strategy']];
        $input['_conditions'] = self::destinationConditions($d, $created);
        if (!$dest->update($input)) {
            throw new ToolException("Échec de mise à jour de la destination #{$destination_id}.");
        }
        DestinationBuilder::applyTo($destination_id, DestinationBuilder::buildConfig($d['config'], array_map(fn($i) => $i['id'], $created)));
    }

    private static function destinationConditions(array $d, array $created): array
    {
        $conditions = [];
        foreach ($d['conditions'] as $j => $c) {
            $row = self::conditionRow($created, $c, "Destination « {$d['name']} »");
            $row['logic_operator'] = $j === 0 ? 'and' : $c['logic'];
            $conditions[] = $row;
        }
        return $conditions;
    }

    /** Version publique de la normalisation (outil de gestion des destinations) */
    public static function normalizeDestinationList(array $list): array
    {
        return self::normalizeDestinations($list);
    }

    public static function previewDestinations(array $norm_list): array
    {
        return array_map(fn($d) => ['crée' => $d['label'] . ' « ' . $d['name'] . ' »', 'quand' => self::humanWhen($d)] + self::humanDestination($d['config']), $norm_list);
    }

    private static function humanWhen(array $d): string
    {
        if ($d['strategy'] === 'always_created') {
            return 'toujours';
        }
        $parts = [];
        foreach ($d['conditions'] as $j => $c) {
            $parts[] = ($j > 0 ? ($c['logic'] === 'or' ? 'OU ' : 'ET ') : '')
                . sprintf('« %s » %s %s', $c['question'], DestinationBuilder::OPERATOR_LABELS[$c['operator']] ?? $c['operator'], is_array($c['value']) ? implode('/', $c['value']) : (string) $c['value']);
        }
        return ($d['strategy'] === 'created_if' ? 'si ' : 'sauf si ') . implode(' ', $parts);
    }

    /**
     * Ligne de condition GLPI (question de référence + valeur, libellés d'options convertis en clés).
     */
    private static function conditionRow(array $created, array $c, string $context): array
    {
        $target = $created[mb_strtolower(trim($c['question']))] ?? null;
        if ($target === null) {
            throw new ToolException("{$context} : question « {$c['question']} » introuvable.");
        }
        $value = $c['value'];
        if ($target['options'] !== [] && !in_array($c['operator'], ['empty', 'not_empty', 'visible', 'not_visible'], true)) {
            $map = array_map(fn($l) => mb_strtolower(trim($l)), $target['options']);
            $vals = [];
            foreach ((array) $value as $v) {
                $k = array_search(mb_strtolower(trim((string) $v)), $map, true);
                if ($k === false) {
                    throw new ToolException("{$context} : option « {$v} » inconnue dans « {$c['question']} ».");
                }
                $vals[] = (string) $k;
            }
            $value = is_array($c['value']) ? $vals : $vals[0];
        }
        return [
            'item'           => 'question-' . $target['uuid'],
            'item_type'      => 'question',
            'item_uuid'      => $target['uuid'],
            'value'          => $value,
            'value_operator' => $c['operator'],
            'logic_operator' => 'and',
        ];
    }

    private static function normalizeCondition(array $q, string $name): ?array
    {
        foreach (['show_if' => 'visible_if', 'hide_if' => 'hidden_if'] as $key => $mode) {
            if (empty($q[$key]) || !is_array($q[$key])) {
                continue;
            }
            $c = $q[$key];
            if (empty($c['question'])) {
                throw new ToolException("Question « {$name} » : {$key} doit préciser la question de référence.");
            }
            $op = (string) ($c['operator'] ?? 'equals');
            if (\Glpi\Form\Condition\ValueOperator::tryFrom($op) === null) {
                throw new ToolException("Question « {$name} » : opérateur « {$op} » inconnu (equals, not_equals, contains, not_contains, empty, not_empty, greater_than, less_than…).");
            }
            return ['mode' => $mode, 'question' => (string) $c['question'], 'operator' => $op, 'value' => $c['value'] ?? ''];
        }
        return null;
    }

    /**
     * Ajoute les traductions d'une langue.
     * $t = ['name' => …, 'description' => …, 'header' => …,
     *       'sections' => ['<section>' => ['name' => …, 'description' => …]],
     *       'questions' => ['<question>' => ['name' => …, 'description' => …, 'options' => ['<libellé>' => …]]]]
     */
    public static function addTranslations(Form $form, string $lang, array $t, array $created = []): int
    {
        if (!isset($GLOBALS['CFG_GLPI']['languages'][$lang])) {
            throw new ToolException("Langue « {$lang} » inconnue (format attendu : fr_FR, en_GB, it_IT, de_DE, es_ES…).");
        }
        $plural = (new \Glpi\ItemTranslation\CldrLanguage($lang))->getPluralKey(1);
        $count = 0;
        $add = function ($item, string $key, ?string $text) use ($lang, $plural, &$count) {
            if ($text === null || trim($text) === '') {
                return;
            }
            $tr = new \Glpi\Form\FormTranslation();
            $existing = $tr->find(['itemtype' => $item::class, 'items_id' => $item->getID(), 'key' => $key, 'language' => $lang]);
            $input = ['itemtype' => $item::class, 'items_id' => $item->getID(), 'key' => $key, 'language' => $lang, 'translations' => [$plural => $text]];
            if ($existing) {
                $ok = $tr->update(['id' => array_key_first($existing)] + $input);
            } else {
                $ok = $tr->add($input);
            }
            if (!$ok) {
                throw new ToolException("Échec de la traduction {$lang} ({$key}).");
            }
            $count++;
        };

        $add($form, Form::TRANSLATION_KEY_NAME, $t['name'] ?? null);
        $add($form, Form::TRANSLATION_KEY_DESCRIPTION, isset($t['description']) ? Items::toHtml((string) $t['description']) : null);
        $add($form, Form::TRANSLATION_KEY_HEADER, isset($t['header']) ? Items::toHtml((string) $t['header']) : null);

        $sections = [];
        foreach ($form->getSections() as $section) {
            $sections[mb_strtolower(trim($section->fields['name']))] = $section;
        }
        foreach ((array) ($t['sections'] ?? []) as $sname => $st) {
            $section = $sections[mb_strtolower(trim((string) $sname))] ?? throw new ToolException("Traduction {$lang} : section « {$sname} » introuvable.");
            $add($section, Section::TRANSLATION_KEY_NAME, $st['name'] ?? null);
            $add($section, Section::TRANSLATION_KEY_DESCRIPTION, isset($st['description']) ? Items::toHtml((string) $st['description']) : null);
        }

        $questions = [];
        foreach ($form->getQuestions() as $question) {
            $questions[mb_strtolower(trim($question->fields['name']))] = $question;
        }
        foreach ((array) ($t['questions'] ?? []) as $qname => $qt) {
            $question = $questions[mb_strtolower(trim((string) $qname))] ?? throw new ToolException("Traduction {$lang} : question « {$qname} » introuvable.");
            $add($question, Question::TRANSLATION_KEY_NAME, $qt['name'] ?? null);
            $add($question, Question::TRANSLATION_KEY_DESCRIPTION, isset($qt['description']) ? Items::toHtml((string) $qt['description']) : null);
            if (!empty($qt['options'])) {
                $extra = json_decode((string) $question->fields['extra_data'], true) ?: [];
                $map = array_map(fn($l) => mb_strtolower(trim((string) $l)), $extra['options'] ?? []);
                foreach ((array) $qt['options'] as $label => $translated) {
                    $k = array_search(mb_strtolower(trim((string) $label)), $map, true);
                    if ($k === false) {
                        throw new ToolException("Traduction {$lang} : option « {$label} » inconnue dans « {$qname} ».");
                    }
                    $add($question, 'option-' . $k, (string) $translated);
                }
            }
        }
        return $count;
    }

    /**
     * Schéma d'un formulaire existant, pensé pour qu'une IA puisse le remplir.
     */
    public static function schema(Form $form): array
    {
        $sections = [];
        foreach ($form->getSections() as $section) {
            $questions = [];
            foreach ($section->getQuestions() as $question) {
                $type = $question->getQuestionType();
                $alias = self::aliasForClass($question->fields['type'], $question->fields['extra_data']);
                $q = [
                    'id'          => $question->getID(),
                    'intitulé'    => self::tr($question, Question::TRANSLATION_KEY_NAME, $question->fields['name']),
                    'type'        => $alias,
                    'obligatoire' => (bool) $question->fields['is_mandatory'],
                    'aide'        => Items::text($question->fields['description'] ?? '', 300),
                    'format_réponse' => self::TYPES[$alias][2] ?? 'chaîne',
                ];
                $extra = json_decode((string) $question->fields['extra_data'], true) ?: [];
                if (!empty($extra['options'])) {
                    $q['options'] = array_values(array_map(fn($k, $l) => self::tr($question, 'option-' . $k, $l), array_keys($extra['options']), $extra['options']));
                }
                if (!empty($extra['itemtype'])) {
                    $q['objet'] = $extra['itemtype'];
                }
                if (($question->fields['visibility_strategy'] ?? '') !== '' && $question->fields['visibility_strategy'] !== 'always_visible') {
                    $q['affichage_conditionnel'] = true;
                }
                $questions[] = $q;
            }
            $sections[] = ['section' => self::tr($section, Section::TRANSLATION_KEY_NAME, $section->fields['name']), 'questions' => $questions];
        }
        return [
            'id'          => $form->getID(),
            'nom'         => self::tr($form, Form::TRANSLATION_KEY_NAME, $form->getName()),
            'langue'      => $_SESSION['glpilanguage'] ?? '',
            'description' => Items::text(self::tr($form, Form::TRANSLATION_KEY_DESCRIPTION, (string) ($form->fields['description'] ?? '')), 1000),
            'actif'       => (bool) $form->fields['is_active'],
            'sections'    => $sections,
            'consigne'    => 'Pour remplir : glpi_form_submit avec answers = { "<id ou intitulé de question>": <réponse> }. Pour les choix, donner le libellé de l\'option.',
        ];
    }

    public static function aliasForClass(string $class, ?string $extra_json): string
    {
        $extra = json_decode((string) $extra_json, true) ?: [];
        if ($class === QuestionType\QuestionTypeDateTime::class) {
            $d = $extra['is_date_enabled'] ?? true;
            $t = $extra['is_time_enabled'] ?? false;
            return $d && $t ? 'date_heure' : ($t ? 'heure' : 'date');
        }
        if ($class === QuestionType\QuestionTypeItemDropdown::class) {
            return match ($extra['itemtype'] ?? '') {
                'ITILCategory' => 'categorie',
                'Location'     => 'lieu',
                default        => 'intitule_glpi',
            };
        }
        foreach (self::TYPES as $alias => $def) {
            if ($def[0] === $class) {
                return $alias;
            }
        }
        return 'autre';
    }

    /**
     * Convertit les réponses « IA » (par intitulé, libellés d'options, ids simples)
     * au format attendu par le moteur de formulaires GLPI (par id de question).
     */
    public static function normalizeAnswers(Form $form, array $answers): array
    {
        $by_id = [];
        $by_name = [];
        foreach ($form->getQuestions() as $question) {
            $by_id[$question->getID()] = $question;
            $by_name[mb_strtolower(trim($question->fields['name']))] = $question;
            $by_name[mb_strtolower(trim(self::tr($question, Question::TRANSLATION_KEY_NAME, $question->fields['name'])))] = $question;
        }

        $out = [];
        foreach ($answers as $key => $value) {
            $question = is_numeric($key) ? ($by_id[(int) $key] ?? null) : ($by_name[mb_strtolower(trim((string) $key))] ?? null);
            if ($question === null) {
                throw new ToolException("Question « {$key} » introuvable dans le formulaire « {$form->getName()} ». Utilise glpi_form_get.");
            }
            $alias = self::aliasForClass($question->fields['type'], $question->fields['extra_data']);
            $extra = json_decode((string) $question->fields['extra_data'], true) ?: [];

            switch ($alias) {
                case 'choix_unique':
                case 'choix_multiple':
                case 'liste':
                    $map = $extra['options'] ?? [];
                    $translated = [];
                    foreach ($map as $ok => $ol) {
                        $translated[$ok] = mb_strtolower(trim(self::tr($question, 'option-' . $ok, $ol)));
                    }
                    $keys = [];
                    foreach ((array) $value as $v) {
                        $needle = mb_strtolower(trim((string) $v));
                        $k = array_key_exists((string) $v, $map) ? (string) $v : array_search($needle, array_map(fn($l) => mb_strtolower(trim($l)), $map), true);
                        if ($k === false) {
                            $k = array_search($needle, $translated, true);
                        }
                        if ($k === false) {
                            throw new ToolException("Question « {$question->fields['name']} » : option « {$v} » inconnue. Options : " . implode(' / ', $map));
                        }
                        $keys[] = (string) $k;
                    }
                    $value = $alias === 'choix_unique' || ($alias === 'liste' && empty($extra['is_multiple_dropdown'])) ? ($keys[0] ?? '') : $keys;
                    break;
                case 'demandeur':
                case 'observateur':
                case 'technicien':
                    $actors = [];
                    foreach ((array) $value as $v) {
                        if (is_array($v) && isset($v['groups_id'])) {
                            $actors[] = 'groups_id-' . (int) $v['groups_id'];
                        } elseif (is_string($v) && str_contains($v, '-')) {
                            $actors[] = $v;
                        } else {
                            $actors[] = 'users_id-' . (int) $v;
                        }
                    }
                    $value = $actors;
                    break;
                case 'categorie':
                case 'lieu':
                case 'objet_glpi':
                case 'intitule_glpi':
                    $value = [
                        'itemtype' => $extra['itemtype'] ?? '',
                        'items_id' => (int) (is_array($value) ? ($value['id'] ?? $value['items_id'] ?? 0) : $value),
                    ];
                    break;
                case 'urgence':
                case 'type_demande':
                case 'nombre':
                    $value = is_numeric($value) ? $value + 0 : $value;
                    break;
                case 'texte_long':
                    $value = Items::toHtml((string) $value);
                    break;
                default:
                    $value = is_scalar($value) ? (string) $value : $value;
            }
            $out[$question->getID()] = $value;
        }
        return $out;
    }

    public static function humanAnswers(Form $form, array $answers): array
    {
        $out = [];
        foreach ($form->getQuestions() as $question) {
            if (!array_key_exists($question->getID(), $answers)) {
                continue;
            }
            $v = $answers[$question->getID()];
            $extra = json_decode((string) $question->fields['extra_data'], true) ?: [];
            if (!empty($extra['options'])) {
                $opt = fn($k) => isset($extra['options'][$k]) ? self::tr($question, 'option-' . $k, $extra['options'][$k]) : $k;
                $v = is_array($v) ? array_map($opt, $v) : $opt($v);
            }
            if (is_array($v) && isset($v['items_id'])) {
                $v = $v['itemtype'] . ' #' . $v['items_id'] . ' ' . (Items::fkLabel(getForeignKeyFieldForItemType($v['itemtype']), $v['items_id']) ?? '');
            }
            if (is_string($v)) {
                $v = Items::text($v, 300);
            }
            $out[self::tr($question, Question::TRANSLATION_KEY_NAME, $question->fields['name'])] = $v;
        }
        return $out;
    }
}
