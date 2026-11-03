<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Forms\FormBuilder;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Operations;
use GlpiPlugin\Glpimcp\Mcp\PendingActions;
use GlpiPlugin\Glpimcp\Mcp\Tool;

class FormSubmit extends Tool
{
    public function name(): string
    {
        return 'glpi_form_submit';
    }

    public function title(): string
    {
        return __('Fill in and submit a form', 'glpimcp');
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function description(): string
    {
        return "Prépare l'envoi d'un formulaire du catalogue avec les réponses recueillies auprès de l'utilisateur. "
            . "answers : { \"<id ou intitulé de la question>\": réponse }. Pour les choix, donner le libellé ; pour les acteurs, des id utilisateurs. "
            . "Les réponses sont validées par GLPI (obligatoires, formats) ; aperçu puis glpi_confirm_action.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'      => self::int('Identifiant du formulaire'),
            'answers' => self::anyObj('Réponses par question'),
        ], ['id', 'answers']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $form = Operations::loadAnswerableForm((int) self::requireArg($args, 'id'));
        $answers = FormBuilder::normalizeAnswers($form, (array) ($args['answers'] ?? []));
        return PendingActions::propose($ctx, $this->name(), sprintf(__('Submission of form « %s »', 'glpimcp'), $form->getName()), [[
            'op'       => 'form_submit',
            'forms_id' => $form->getID(),
            'answers'  => $answers,
        ]]);
    }
}
