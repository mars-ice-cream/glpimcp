<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Tools;

use Config;
use GlpiPlugin\Glpimcp\Mcp\Context;
use GlpiPlugin\Glpimcp\Mcp\Tool;
use GlpiPlugin\Glpimcp\Mcp\ToolException;
use Session;

class ConfigGet extends Tool
{
    /** Paramètres « essentiels » regroupés comme dans les menus GLPI */
    public const KEY_SETTINGS = [
        'Général' => [
            'url_base'        => 'URL de l\'application',
            'language'        => 'Langue par défaut',
            'text_login'      => 'Texte sur la page de connexion',
            'use_public_faq'  => 'FAQ publique (sans connexion)',
            'list_limit_max'  => 'Nombre max d\'éléments par page',
            'cut'             => 'Longueur des textes dans les listes',
        ],
        'Notifications / e-mails' => [
            'use_notifications'      => 'Notifications activées',
            'notifications_mailing'  => 'Notifications par e-mail',
            'admin_email'            => 'E-mail de l\'administrateur',
            'admin_email_name'       => 'Nom de l\'administrateur',
            'from_email'             => 'E-mail expéditeur',
            'from_email_name'        => 'Nom de l\'expéditeur',
            'noreply_email'          => 'E-mail no-reply',
            'replyto_email'          => 'E-mail de réponse',
            'mailing_signature'      => 'Signature des e-mails',
            'smtp_mode'              => 'Mode d\'envoi (0=PHP mail, 1=SMTP, 2=SMTP+SSL, 3=SMTP+TLS, 4=OAuth)',
            'smtp_host'              => 'Serveur SMTP',
            'smtp_port'              => 'Port SMTP',
            'smtp_username'          => 'Utilisateur SMTP',
            'smtp_check_certificate' => 'Vérifier le certificat SMTP',
        ],
        'Assistance' => [
            'default_mailcollector_filesize_max' => 'Taille max des pièces jointes collectées',
            'use_anonymous_helpdesk' => 'Ticket anonyme autorisé',
            'use_anonymous_followups' => 'Suivi anonyme autorisé',
            'urgency_mask'           => 'Urgences utilisables (masque)',
            'impact_mask'            => 'Impacts utilisables (masque)',
            'priority_matrix'        => 'Matrice de priorité',
            'set_followup_tech'      => 'Technicien ajouté automatiquement au suivi',
            'set_solution_tech'      => 'Technicien ajouté automatiquement à la solution',
            'planning_begin'         => 'Début du planning',
            'planning_end'           => 'Fin du planning',
            'keep_tickets_on_delete' => 'Conserver les tickets à la suppression d\'un élément',
        ],
        'Sécurité' => [
            'password_min_length'          => 'Longueur min. mot de passe',
            'password_need_number'         => 'Mot de passe : chiffre obligatoire',
            'password_need_letter'         => 'Mot de passe : lettre obligatoire',
            'password_need_caps'           => 'Mot de passe : majuscule obligatoire',
            'password_need_symbol'         => 'Mot de passe : symbole obligatoire',
            'use_password_security'        => 'Politique de mot de passe activée',
            'password_expiration_delay'    => 'Expiration des mots de passe (jours, -1 = jamais)',
            'login_remember_time'          => 'Durée « se souvenir de moi »',
            'is_users_auto_add'            => 'Création auto des utilisateurs (sources externes)',
            '2fa_enforced'                 => 'Double authentification imposée',
            'enable_api'                   => 'API REST activée',
        ],
        'Inventaire / parc' => [
            'auto_create_infocoms'       => 'Créer automatiquement les infos financières',
            'use_flat_dropdowntree'      => 'Afficher les intitulés arborescents à plat',
            'transfers_id_auto'          => 'Transfert automatique entre entités',
        ],
        'Maintenance' => [
            'maintenance_mode'  => 'Mode maintenance',
            'use_log_in_files'  => 'Journaux dans des fichiers',
            'cron_limit'        => 'Nb max de tâches automatiques par exécution',
        ],
    ];

    public function name(): string
    {
        return 'glpi_config_get';
    }

    public function title(): string
    {
        return __('Read the general setup', 'glpimcp');
    }

    public function isAvailable(Context $ctx): bool
    {
        return Session::haveRight('config', READ);
    }

    public function description(): string
    {
        return "Lit la configuration générale de GLPI (Configuration > Générale, Notifications, Authentification…). "
            . "Sans argument : paramètres essentiels groupés et commentés. keys=[…] pour des clés précises ; context pour un plugin. Les secrets ne sont jamais renvoyés.";
    }

    public function inputSchema(): array
    {
        return self::obj([
            'keys'    => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Clés précises'],
            'context' => self::str('Contexte (core par défaut, inventory pour l\'inventaire natif…)'),
        ]);
    }

    public function call(array $args, Context $ctx): mixed
    {
        if (!Session::haveRight('config', READ)) {
            throw new ToolException('Droit « Configuration : lire » manquant.');
        }
        $context = (string) ($args['context'] ?? 'core');

        if (!empty($args['keys'])) {
            $values = Config::getConfigurationValues($context, array_map('strval', $args['keys']));
            return ['contexte' => $context, 'valeurs' => self::mask($values)];
        }
        if ($context !== 'core') {
            return ['contexte' => $context, 'valeurs' => self::mask(Config::getConfigurationValues($context))];
        }

        $all = Config::getConfigurationValues('core');
        $out = [];
        foreach (self::KEY_SETTINGS as $group => $keys) {
            foreach ($keys as $k => $label) {
                if (array_key_exists($k, $all)) {
                    $out[$group][$k] = ['libellé' => $label, 'valeur' => self::mask([$k => $all[$k]])[$k]];
                }
            }
        }
        $inv = Config::getConfigurationValues('inventory', ['enabled_inventory']);
        $out['Inventaire / parc']['enabled_inventory'] = ['libellé' => 'Inventaire natif activé (contexte inventory)', 'valeur' => $inv['enabled_inventory'] ?? null];
        return $out;
    }

    private static function mask(array $values): array
    {
        foreach ($values as $k => $v) {
            if (\GlpiPlugin\Glpimcp\Mcp\Items::isSecretConfigKey((string) $k)) {
                $values[$k] = $v === '' || $v === null ? '(vide)' : '(défini — masqué)';
            }
        }
        return $values;
    }
}
