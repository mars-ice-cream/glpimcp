<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Items;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use KnowbaseItem;

class KnowbaseSearch extends Tool
{
    public function name(): string
    {
        return 'glpi_kb_search';
    }

    public function title(): string
    {
        return __('Search the knowledge base', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return KnowbaseItem::canView() || \Session::haveRight('knowbase', KnowbaseItem::READFAQ);
    }

    public function description(): string
    {
        return "Cherche des articles de la base de connaissances / FAQ visibles par l'utilisateur. Plusieurs mots = tous doivent apparaître (titre ou contenu). "
            . "Renvoie titre, extrait et lien ; full=true renvoie le texte complet. Utilise-le AVANT d'ouvrir un ticket pour proposer une solution existante.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'query' => self::str('Mots-clés'),
            'id'    => self::int('Lire un article précis'),
            'full'  => self::bool('Renvoyer le contenu complet'),
            'limit' => self::int('Nombre max (défaut 10)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        global $DB;

        if (!empty($args['id'])) {
            $kb = Items::load('KnowbaseItem', (int) $args['id'], READ);
            return ['id' => $kb->getID(), 'titre' => $kb->fields['name'], 'contenu' => Items::text($kb->fields['answer'], 20000), 'faq' => (bool) $kb->fields['is_faq'], 'url' => Items::url($kb)];
        }

        $words = array_filter(preg_split('/\s+/', trim((string) ($args['query'] ?? ''))), fn($w) => mb_strlen($w) >= 2);
        $where = [];
        foreach (array_slice($words, 0, 8) as $w) {
            $where[] = ['OR' => [
                'glpi_knowbaseitems.name'   => ['LIKE', "%{$w}%"],
                'glpi_knowbaseitems.answer' => ['LIKE', "%{$w}%"],
            ]];
        }
        $limit = max(1, min(30, (int) ($args['limit'] ?? 10)));
        $results = [];
        $it = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_knowbaseitems',
            'WHERE'  => $where,
            'ORDER'  => ['view DESC', 'date_mod DESC'],
            'LIMIT'  => 300,
        ]);
        foreach ($it as $row) {
            $kb = new KnowbaseItem();
            if (!$kb->getFromDB($row['id']) || !$kb->canViewItem()) {
                continue;
            }
            $results[] = [
                'id'      => $kb->getID(),
                'titre'   => $kb->fields['name'],
                'extrait' => Items::text($kb->fields['answer'], !empty($args['full']) ? 20000 : 400),
                'faq'     => (bool) $kb->fields['is_faq'],
                'vues'    => (int) $kb->fields['view'],
                'url'     => Items::url($kb),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        return ['nb' => count($results), 'articles' => $results];
    }
}
