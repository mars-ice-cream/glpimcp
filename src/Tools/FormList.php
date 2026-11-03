<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\Form;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use Session;

class FormList extends Tool
{
    public function name(): string
    {
        return 'glpi_form_list';
    }

    public function title(): string
    {
        return __('Available forms', 'glpimcp');
    }

    public function description(): string
    {
        return "Liste les formulaires du catalogue de services que l'utilisateur peut remplir (mode=remplir, défaut), "
            . "ou tous les formulaires pour un administrateur (mode=gerer). Filtre texte optionnel.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'query' => self::str('Filtre sur le nom / la description'),
            'mode'  => self::str('remplir ou gerer', ['remplir', 'gerer']),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        $mode = ($args['mode'] ?? 'remplir') === 'gerer' && Form::canView() ? 'gerer' : 'remplir';
        $q = mb_strtolower(trim((string) ($args['query'] ?? '')));

        $where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Form::getTable(), '', '', true);
        if ($mode === 'remplir') {
            $where['is_active'] = 1;
            $where['is_draft'] = 0;
        }

        $params = new FormAccessParameters(Session::getCurrentSessionInfo());
        $manager = FormAccessControlManager::getInstance();

        $out = [];
        foreach ((new Form())->find($where, ['name ASC']) as $row) {
            if ($q !== '' && !str_contains(mb_strtolower($row['name'] . ' ' . strip_tags((string) $row['description'])), $q)) {
                continue;
            }
            $form = new Form();
            $form->getFromDB($row['id']);
            $can_answer = $manager->canAnswerForm($form, $params);
            if ($mode === 'remplir' && !$can_answer) {
                continue;
            }
            $entry = [
                'id'          => (int) $row['id'],
                'nom'         => $row['name'],
                'description' => Items::text($row['description'] ?? '', 200),
                'catégorie'   => Items::fkLabel('forms_categories_id', $row['forms_categories_id']),
            ];
            if ($mode === 'gerer') {
                $entry['actif'] = (bool) $row['is_active'];
                $entry['brouillon'] = (bool) $row['is_draft'];
                $entry['je_peux_le_remplir'] = $can_answer;
                $entry['url_édition'] = Items::url($form);
            }
            $out[] = $entry;
        }
        return ['mode' => $mode, 'nb' => count($out), 'formulaires' => $out];
    }
}
