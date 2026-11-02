<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

/**
 * Un outil MCP.
 *
 * Les outils de lecture renvoient directement des données.
 * Les outils d'écriture ne modifient JAMAIS GLPI eux-mêmes : ils construisent une
 * liste d'opérations, vérifient les droits, et la confient au PendingActionManager
 * qui renvoie un aperçu + un jeton de confirmation (ou exécute directement selon
 * la configuration du plugin).
 */
abstract class Tool
{
    abstract public function name(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** JSON Schema des arguments */
    abstract public function inputSchema(): array;

    abstract public function call(array $args, Context $ctx): mixed;

    public function isWrite(): bool
    {
        return false;
    }

    /** Outil visible pour l'utilisateur courant ? (filtrage par droits) */
    public function isAvailable(Context $ctx): bool
    {
        return true;
    }

    public function definition(): array
    {
        return [
            'name'        => $this->name(),
            'title'       => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $this->inputSchema(),
            'annotations' => [
                'title'           => $this->title(),
                'readOnlyHint'    => !$this->isWrite(),
                'destructiveHint' => $this->isWrite(),
                'idempotentHint'  => !$this->isWrite(),
                'openWorldHint'   => false,
            ],
        ];
    }

    // ---- helpers -------------------------------------------------------

    protected static function obj(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    protected static function str(string $description, ?array $enum = null): array
    {
        $s = ['type' => 'string', 'description' => $description];
        if ($enum !== null) {
            $s['enum'] = $enum;
        }
        return $s;
    }

    protected static function int(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    protected static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    protected static function anyObj(string $description): array
    {
        return ['type' => 'object', 'description' => $description, 'additionalProperties' => true];
    }

    protected static function requireArg(array $args, string $key): mixed
    {
        if (!array_key_exists($key, $args) || $args[$key] === '' || $args[$key] === null) {
            throw new ToolException(sprintf('Argument « %s » manquant.', $key));
        }
        return $args[$key];
    }
}
