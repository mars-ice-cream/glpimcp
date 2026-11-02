<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use RuntimeException;

/**
 * Erreur « métier » renvoyée proprement à l'IA (isError=true), pas une erreur protocole.
 */
class ToolException extends RuntimeException
{
    public function __construct(string $message, private array $details = [])
    {
        parent::__construct($message);
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
