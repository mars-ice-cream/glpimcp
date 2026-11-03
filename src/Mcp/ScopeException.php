<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

/** Contexte demandé hors du périmètre accordé au jeton (refus explicite, HTTP 403). */
final class ScopeException extends \RuntimeException
{
}
