<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp;

use Html;
use Session;

/**
 * Différences entre GLPI 11 et GLPI 12.
 */
final class Compat
{
    public static function isGlpi12(): bool
    {
        return defined('GLPI_VERSION') && version_compare(GLPI_VERSION, '12.0.0-dev', '>=');
    }

    /**
     * GLPI 12 : ré-authentification récente exigée (mot de passe, LDAP, CAS ou TOTP), comme pour la
     * configuration et les profils de GLPI ; la demande est rejouée ensuite. Sans effet avec GLPI 11.
     */
    public static function requireReauth(): void
    {
        if (self::isGlpi12()) {
            \Glpi\Security\ReAuth\ReAuthManager::getInstance()->checkReAuthenticationOrRedirect();
        }
    }

    /**
     * Champ anti-CSRF d'un formulaire. GLPI 11 exige un jeton ; GLPI 12 protège par les en-têtes
     * Sec-Fetch-Site / Origin et n'utilise plus de jeton (l'appel est même déprécié) : champ vide.
     */
    public static function csrfField(): string
    {
        if (self::isGlpi12()) {
            return '';
        }
        return Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    }
}
