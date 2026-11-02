<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Forms;

use Dropdown;
use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\Form;
use Glpi\Form\FormTranslation;
use GlpiPlugin\Glpimcp\Mcp\Items;

/**
 * Analyse des formulaires : repère les « jumeaux » (même formulaire décliné par entité,
 * site ou langue) et prépare les éléments d'une consolidation.
 */
final class FormAuditor
{
    private const STOPWORDS = [
        'fr_FR' => ['le', 'la', 'les', 'de', 'des', 'du', 'et', 'pour', 'votre', 'vous', 'une', 'un', 'demande', 'nom', 'prénom', 'date'],
        'en_GB' => ['the', 'and', 'for', 'your', 'you', 'of', 'request', 'name', 'first', 'date', 'with'],
        'it_IT' => ['il', 'la', 'di', 'per', 'e', 'del', 'della', 'richiesta', 'nome', 'cognome', 'data', 'vostro'],
        'es_ES' => ['el', 'la', 'los', 'de', 'para', 'y', 'su', 'solicitud', 'nombre', 'apellido', 'fecha'],
        'de_DE' => ['der', 'die', 'das', 'und', 'für', 'ihr', 'anfrage', 'antrag', 'name', 'vorname', 'datum'],
    ];

    /**
     * Fiche synthétique d'un formulaire.
     */
    public static function summarize(Form $form, bool $with_questions = true): array
    {
        $questions = [];
        foreach ($form->getQuestions() as $q) {
            $extra = json_decode((string) $q->fields['extra_data'], true) ?: [];
            $entry = [
                'id'          => $q->getID(),
                'intitulé'    => $q->fields['name'],
                'type'        => FormBuilder::aliasForClass($q->fields['type'], $q->fields['extra_data']),
                'obligatoire' => (bool) $q->fields['is_mandatory'],
            ];
            if (!empty($extra['options'])) {
                $entry['options'] = array_values($extra['options']);
            }
            if (($q->fields['visibility_strategy'] ?? '') !== '' && $q->fields['visibility_strategy'] !== 'always_visible') {
                $entry['condition'] = $q->fields['visibility_strategy'];
            }
            $questions[] = $entry;
        }

        $languages = [];
        foreach (FormTranslation::getTranslationsForForm($form) as $tr) {
            $languages[$tr->fields['language']] = true;
        }

        $access = [];
        foreach ($form->getAccessControls() as $control) {
            if (!$control->fields['is_active']) {
                continue;
            }
            $cfg = json_decode((string) $control->fields['config'], true) ?: [];
            if ($control->fields['strategy'] === AllowList::class) {
                $users = $cfg['user_ids'] ?? [];
                $access[] = in_array(\AbstractRightsDropdown::ALL_USERS, $users, true)
                    ? 'tous les utilisateurs'
                    : sprintf('%d utilisateur(s), %d groupe(s), %d profil(s)', count($users), count($cfg['group_ids'] ?? []), count($cfg['profile_ids'] ?? []));
            } else {
                $access[] = basename(str_replace('\\', '/', $control->fields['strategy']));
            }
        }

        $out = [
            'id'          => $form->getID(),
            'nom'         => $form->getName(),
            'entité'      => ['id' => (int) $form->fields['entities_id'], 'nom' => Dropdown::getDropdownName('glpi_entities', $form->fields['entities_id']), 'récursif' => (bool) $form->fields['is_recursive']],
            'actif'       => (bool) $form->fields['is_active'],
            'brouillon'   => (bool) $form->fields['is_draft'],
            'langue_principale_estimée' => self::guessLanguage($form),
            'traductions' => array_keys($languages),
            'accès'       => $access,
            'nb_questions' => count($questions),
            'destinations' => DestinationBuilder::describe($form),
            'url'         => Items::url($form),
        ];
        if ($with_questions) {
            $out['questions'] = $questions;
        }
        return $out;
    }



    public static function guessLanguage(Form $form): string
    {
        $text = ' ' . self::norm($form->getName() . ' ' . strip_tags((string) $form->fields['description'])) . ' ';
        foreach ($form->getQuestions() as $q) {
            $text .= ' ' . self::norm($q->fields['name']) . ' ';
        }
        $best = 'indéterminée';
        $score = 0;
        foreach (self::STOPWORDS as $lang => $words) {
            $s = 0;
            foreach ($words as $w) {
                $s += substr_count($text, ' ' . self::norm($w) . ' ');
            }
            if ($s > $score) {
                [$best, $score] = [$lang, $s];
            }
        }
        return $best;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }


}
