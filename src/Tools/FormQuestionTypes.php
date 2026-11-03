<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Forms\FormBuilder;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class FormQuestionTypes extends Tool
{
    public function name(): string
    {
        return 'glpi_form_question_types';
    }

    public function title(): string
    {
        return __('Form question types', 'glpimcp');
    }

    public function description(): string
    {
        return "Liste les types de questions utilisables dans les formulaires (opération form_build de glpi_apply_operations), avec leur usage et le format de réponse.";
    }

    public function inputSchema(): array
    {
        return self::obj([]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $out = [];
        foreach (FormBuilder::TYPES as $alias => $def) {
            $out[] = ['type' => $alias, 'usage' => $def[1], 'format_réponse' => $def[2]];
        }
        return [
            'types' => $out,
            'conseils' => [
                'Mettre « demandeur » en première question uniquement si on remplit pour quelqu\'un d\'autre ; sinon le demandeur est l\'utilisateur connecté.',
                'Les questions urgence / categorie / lieu alimentent directement les champs du ticket créé.',
                'Préférer choix_unique (≤ 5 options) à liste ; liste pour les listes longues.',
                'Une section par étape logique ; 3 à 8 questions par section.',
            ],
        ];
    }
}
