<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

/** Débit dépassé : réponse HTTP 429 avec Retry-After. */
final class RateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retry_after)
    {
        parent::__construct($message);
    }
}
