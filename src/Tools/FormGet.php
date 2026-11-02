<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Form;
use GlpiPlugin\Glpimcp\Forms\FormBuilder;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Session;

class FormGet extends Tool
{
    public function name(): string
    {
        return 'glpi_form_get';
    }

    public function title(): string
    {
        return 'Questions d\'un formulaire';
    }

    public function description(): string
    {
        return "Renvoie la structure d'un formulaire (sections, questions, types, options, obligatoire) pour pouvoir le remplir "
            . "en posant les questions à l'utilisateur de façon conversationnelle, ou pour l'analyser.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'id'      => self::int('Identifiant du formulaire'),
            'details' => self::bool('Administrateurs : destinations (entité, validation, acteurs), traductions, accès, conditions'),
        ], ['id']);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $form = new Form();
        if (!$form->getFromDB((int) self::requireArg($args, 'id'))) {
            throw new ToolException('Formulaire introuvable.');
        }
        $can_answer = FormAccessControlManager::getInstance()->canAnswerForm($form, new FormAccessParameters(Session::getCurrentSessionInfo()));
        if (!$can_answer && !$form->can($form->getID(), READ)) {
            throw new ToolException("Tu n'as pas accès à ce formulaire.");
        }
        $schema = FormBuilder::schema($form);
        $schema['je_peux_le_remplir'] = $can_answer;
        if (!empty($args['details']) && $form->can($form->getID(), READ)) {
            $schema['administration'] = \GlpiPlugin\Glpimcp\Forms\FormAuditor::summarize($form, false);
        }
        return $schema;
    }
}
