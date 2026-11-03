<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use CommonDBTM;
use CommonDropdown;
use CommonITILObject;
use CommonTreeDropdown;
use Dropdown;
use Html;
use Search;
use Session;
use Toolbox;

/**
 * Outils communs pour manipuler n'importe quel objet GLPI de façon sûre.
 */
final class Items
{
    /** Alias lisibles → classes GLPI */
    public const ALIASES = [
        'ticket'          => 'Ticket',
        'tickets'         => 'Ticket',
        'incident'        => 'Ticket',
        'demande'         => 'Ticket',
        'problem'         => 'Problem',
        'probleme'        => 'Problem',
        'change'          => 'Change',
        'changement'      => 'Change',
        'project'         => 'Project',
        'projet'          => 'Project',
        'projecttask'     => 'ProjectTask',
        'followup'        => 'ITILFollowup',
        'suivi'           => 'ITILFollowup',
        'solution'        => 'ITILSolution',
        'computer'        => 'Computer',
        'ordinateur'      => 'Computer',
        'pc'              => 'Computer',
        'monitor'         => 'Monitor',
        'ecran'           => 'Monitor',
        'printer'         => 'Printer',
        'imprimante'      => 'Printer',
        'phone'           => 'Phone',
        'telephone'       => 'Phone',
        'networkequipment' => 'NetworkEquipment',
        'switch'          => 'NetworkEquipment',
        'peripheral'      => 'Peripheral',
        'software'        => 'Software',
        'logiciel'        => 'Software',
        'softwarelicense' => 'SoftwareLicense',
        'licence'         => 'SoftwareLicense',
        'contract'        => 'Contract',
        'contrat'         => 'Contract',
        'supplier'        => 'Supplier',
        'fournisseur'     => 'Supplier',
        'contact'         => 'Contact',
        'budget'          => 'Budget',
        'document'        => 'Document',
        'user'            => 'User',
        'utilisateur'     => 'User',
        'group'           => 'Group',
        'groupe'          => 'Group',
        'entity'          => 'Entity',
        'entite'          => 'Entity',
        'profile'         => 'Profile',
        'profil'          => 'Profile',
        'location'        => 'Location',
        'lieu'            => 'Location',
        'category'        => 'ITILCategory',
        'categorie'       => 'ITILCategory',
        'itilcategory'    => 'ITILCategory',
        'calendar'        => 'Calendar',
        'calendrier'      => 'Calendar',
        'slm'             => 'SLM',
        'sla'             => 'SLA',
        'ola'             => 'OLA',
        'kb'              => 'KnowbaseItem',
        'knowbaseitem'    => 'KnowbaseItem',
        'faq'             => 'KnowbaseItem',
        'article'         => 'KnowbaseItem',
        'form'            => 'Glpi\\Form\\Form',
        'formulaire'      => 'Glpi\\Form\\Form',
        'formcategory'    => 'Glpi\\Form\\Category',
        'mailcollector'   => 'MailCollector',
        'collecteur'      => 'MailCollector',
        'notification'    => 'Notification',
        'notificationtemplate' => 'NotificationTemplate',
        'state'           => 'State',
        'statut'          => 'State',
        'manufacturer'    => 'Manufacturer',
        'fabricant'       => 'Manufacturer',
        'requesttype'     => 'RequestType',
        'solutiontype'    => 'SolutionType',
        'taskcategory'    => 'TaskCategory',
        'rule'            => 'RuleTicket',
        'ruleticket'      => 'RuleTicket',
        'regle'           => 'RuleTicket',
    ];

    /** Types jamais manipulables via l'IA, même avec les droits. */
    private const FORBIDDEN_TYPES = [
        'Plugin', 'Log', 'Config', 'CronTaskLog', 'APIClient', 'AuthLDAP', 'AuthMail',
        'Glpi\\OAuth\\AccessToken', 'Glpi\\OAuth\\Client', 'OAuthClient', 'Glpi\\System\\Requirement',
        // SecIncident : journal d'audit chaîné et registre des preuves, jamais touchés par l'IA
        'GlpiPlugin\\Secincident\\AuditLog', 'GlpiPlugin\\Secincident\\Evidence',
    ];

    /** Champs jamais lisibles ni modifiables via l'IA. */
    public const SECRET_FIELDS = [
        'password', 'password2', 'api_token', 'api_token_date', 'personal_token', 'personal_token_date',
        'cookie_token', 'cookie_token_date', 'password_forget_token', 'password_forget_token_date',
        'passwd', 'rootdn_passwd', 'token_hash', '2fa', 'secret', 'client_secret', 'smtp_passwd',
        'proxy_passwd', 'smtp_oauth_client_secret', 'smtp_oauth_refresh_token', 'nonce', 'sid',
    ];

    /**
     * Paramètre de configuration contenant un secret (mot de passe, jeton, clé privée…).
     * Les règles de politique de mot de passe (password_min_length…) ne sont pas des secrets.
     */
    public static function isSecretConfigKey(string $key): bool
    {
        return (bool) preg_match('/passwd|^password$|_password$|secret|token|private_?key|_key$|^glpikey|_cert$/i', $key);
    }

    public static function resolveType(string $itemtype): string
    {
        $raw = trim($itemtype);
        $key = strtolower(stripslashes(str_replace(['é', 'è', 'ê', ' ', '-', '_'], ['e', 'e', 'e', '', '', ''], $raw)));
        $class = self::ALIASES[$key] ?? $raw;

        if (!class_exists($class) || !is_a($class, CommonDBTM::class, true)) {
            // Essayer en ignorant la casse parmi les alias connus
            foreach (self::ALIASES as $c) {
                if (strcasecmp($c, $raw) === 0) {
                    $class = $c;
                    break;
                }
            }
        }
        if (!class_exists($class) || !is_a($class, CommonDBTM::class, true)) {
            throw new ToolException("Type d'objet inconnu : « {$itemtype} ». Utilise glpi_list_itemtypes pour voir les types disponibles.");
        }
        if (in_array($class, self::FORBIDDEN_TYPES, true) || str_starts_with($class, 'GlpiPlugin\\Glpimcp\\')) {
            throw new ToolException("Le type « {$class} » n'est pas manipulable via l'assistant IA.");
        }
        return $class;
    }

    public static function newItem(string $itemtype): CommonDBTM
    {
        $class = self::resolveType($itemtype);
        /** @var CommonDBTM $item */
        $item = new $class();
        return $item;
    }

    /** Charge un objet et vérifie le droit demandé. */
    public static function load(string $itemtype, int $id, int $right = READ): CommonDBTM
    {
        $item = self::newItem($itemtype);
        if (!$item->getFromDB($id)) {
            throw new ToolException(sprintf("%s #%d introuvable.", $item::getTypeName(1), $id));
        }
        if (!$item->can($id, $right)) {
            throw new ToolException(sprintf(
                "Droits insuffisants : ton profil « %s » ne permet pas %s sur %s #%d.",
                $_SESSION['glpiactiveprofile']['name'] ?? '?',
                self::rightLabel($right),
                $item::getTypeName(1),
                $id
            ));
        }
        return $item;
    }

    public static function rightLabel(int $right): string
    {
        return match ($right) {
            READ   => 'la lecture',
            CREATE => 'la création',
            UPDATE => 'la modification',
            DELETE => 'la mise à la corbeille',
            PURGE  => 'la suppression définitive',
            default => 'cette action',
        };
    }

    /** Colonnes réelles de la table */
    public static function columns(CommonDBTM $item): array
    {
        global $DB;
        static $cache = [];
        $table = $item::getTable();
        return $cache[$table] ??= $DB->listFields($table);
    }

    /**
     * Nettoie un input envoyé par l'IA : retire les champs secrets / inconnus,
     * garde les champs spéciaux GLPI préfixés par « _ » (ex : _actors, _users_id_requester).
     */
    public static function cleanInput(CommonDBTM $item, array $input, array &$ignored = []): array
    {
        $columns = self::columns($item);
        $clean = [];
        foreach ($input as $key => $value) {
            $lk = strtolower((string) $key);
            if (in_array($lk, self::SECRET_FIELDS, true) || str_contains($lk, 'passw') || str_ends_with($lk, '_token')) {
                $ignored[] = $key . ' (champ sensible interdit)';
                continue;
            }
            if (in_array($key, ['id', 'date_creation', 'date_mod'], true)) {
                $ignored[] = $key . ' (géré par GLPI)';
                continue;
            }
            $special = $item instanceof \CommonITILObject && $key === 'items_id';
            if (!$special && !isset($columns[$key]) && !str_starts_with((string) $key, '_') && !str_starts_with((string) $key, '@')) {
                $ignored[] = $key . ' (champ inexistant)';
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /** Nom lisible d'un objet lié par une clé étrangère */
    public static function fkLabel(string $field, mixed $value): ?string
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }
        if (in_array($field, ['users_id', 'users_id_tech', 'users_id_recipient', 'users_id_lastupdater', 'users_id_validate', 'users_id_editor'], true)
            || str_starts_with($field, 'users_id')) {
            return getUserName((int) $value);
        }
        if (!str_ends_with($field, '_id') && !preg_match('/_id_/', $field)) {
            return null;
        }
        $table = getTableNameForForeignKeyField($field);
        if ($table === '' || !$GLOBALS['DB']->tableExists($table)) {
            return null;
        }
        $name = Dropdown::getDropdownName($table, (int) $value, false, true, false, '');
        return $name !== '' && $name !== '&nbsp;' ? $name : null;
    }

    /**
     * Représentation « IA-friendly » d'un objet : champs utiles, valeurs liées lisibles,
     * sans champs secrets.
     */
    public static function export(CommonDBTM $item, ?array $only = null, bool $with_text = true): array
    {
        $out = [];
        foreach ($item->fields as $field => $value) {
            if (in_array($field, self::SECRET_FIELDS, true) || str_ends_with($field, '_token') || str_contains($field, 'passw')) {
                continue;
            }
            if ($only !== null && !in_array($field, $only, true) && $field !== 'id') {
                continue;
            }
            if (is_string($value) && in_array($field, ['content', 'answer', 'comment', 'description', 'header', 'solution'], true)) {
                $value = $with_text ? self::text($value, 4000) : self::text($value, 200);
            }
            $out[$field] = $value;
            $label = self::fkLabel($field, $value);
            if ($label !== null) {
                $out[$field . '__label'] = $label;
            }
        }
        if ($item instanceof CommonITILObject) {
            if (isset($item->fields['status'])) {
                $out['status__label'] = $item::getStatus($item->fields['status']);
            }
            if (isset($item->fields['urgency'])) {
                $out['urgency__label'] = CommonITILObject::getUrgencyName($item->fields['urgency']);
            }
            if (isset($item->fields['priority'])) {
                $out['priority__label'] = CommonITILObject::getPriorityName($item->fields['priority']);
            }
            if ($item instanceof \Ticket && isset($item->fields['type'])) {
                $out['type__label'] = \Ticket::getTicketTypeName($item->fields['type']);
            }
        }
        $out['_itemtype'] = $item::class;
        $out['_url'] = self::url($item);
        return $out;
    }

    public static function url(CommonDBTM $item): string
    {
        global $CFG_GLPI;
        $base = rtrim($CFG_GLPI['url_base'] ?? '', '/');
        try {
            $link = $item::getFormURLWithID($item->getID(), false);
        } catch (\Throwable) {
            return '';
        }
        return $base . $link;
    }

    /** Texte brut (HTML retiré) tronqué */
    public static function text(?string $html, int $max = 2000): string
    {
        $txt = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $txt)));
        if (mb_strlen($txt) > $max) {
            $txt = mb_substr($txt, 0, $max) . '…';
        }
        return $txt;
    }

    /** Convertit du texte brut de l'IA en HTML simple pour les champs riches GLPI */
    public static function toHtml(string $text): string
    {
        if (preg_match('/<(p|br|ul|ol|li|strong|em|h[1-6]|table|div|a)\b/i', $text)) {
            return $text;
        }
        $paras = preg_split("/\n{2,}/", trim($text));
        return implode('', array_map(fn($p) => '<p>' . nl2br(htmlescape($p)) . '</p>', $paras));
    }

    /**
     * Récupère les messages GLPI (règles, erreurs de validation…) générés pendant une action
     * pour les remonter à l'IA au lieu d'échouer silencieusement.
     */
    public static function flushMessages(): array
    {
        $out = [];
        foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $type => $messages) {
            $level = match ((int) $type) {
                ERROR   => 'erreur',
                WARNING => 'avertissement',
                default => 'info',
            };
            foreach ((array) $messages as $m) {
                $out[] = $level . ' : ' . self::text((string) $m, 500);
            }
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
        return $out;
    }

    /** Nom lisible d'un type (certaines classes de liaison GLPI n'ont pas de nom parlant) */
    public static function typeLabel(CommonDBTM|string $item): string
    {
        $class = is_string($item) ? $item : $item::class;
        return match ($class) {
            'Group_User'        => 'Membre de groupe',
            'Profile_User'      => 'Habilitation (profil + entité)',
            'Calendar_Holiday'  => 'Fermeture rattachée au calendrier',
            'RuleCriteria'      => 'Critère de règle',
            'RuleAction'        => 'Action de règle',
            'Ticket_User'       => 'Acteur du ticket (utilisateur)',
            'Group_Ticket'      => 'Acteur du ticket (groupe)',
            'Glpi\\Form\\FormTranslation' => 'Traduction de formulaire',
            default             => $class::getTypeName(1),
        };
    }

        public static function isDropdown(string $class): bool
    {
        return is_a($class, CommonDropdown::class, true);
    }

    public static function isTree(string $class): bool
    {
        return is_a($class, CommonTreeDropdown::class, true);
    }
}
