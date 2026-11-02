<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Config;
use Session;

/**
 * Contexte d'un appel MCP : utilisateur authentifié, jeton utilisé, configuration.
 */
final class Context
{
    public function __construct(
        public readonly int $users_id,
        public readonly array $token,
    ) {}

    public function config(string $key, mixed $default = null): mixed
    {
        static $config = null;
        $config ??= Config::getConfigurationValues('plugin:glpimcp');
        return $config[$key] ?? $default;
    }

    public function maxResults(): int
    {
        return max(1, min(200, (int) $this->config('max_search_results', 50)));
    }

    public function profileName(): string
    {
        return $_SESSION['glpiactiveprofile']['name'] ?? '';
    }

    public function isHelpdeskInterface(): bool
    {
        return Session::getCurrentInterface() === 'helpdesk';
    }
}
