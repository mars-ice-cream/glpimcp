<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp;

use CommonGLPI;
use Session;

/** Entrée « Administration > Assistant IA (MCP) » */
class Menu extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getMenuName()
    {
        return __('AI assistant (MCP)', 'glpimcp');
    }

    public static function getIcon()
    {
        return 'ti ti-robot';
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/glpimcp/front/config.form.php',
            'icon'  => self::getIcon(),
        ];
    }
}
