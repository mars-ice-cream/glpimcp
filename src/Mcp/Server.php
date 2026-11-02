<?php

/**
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Glpimcp\Mcp;

use Glpi\Error\ErrorHandler;
use GlpiPlugin\Glpimcp\Tools;
use Throwable;

/**
 * Serveur MCP (JSON-RPC 2.0) — transport « Streamable HTTP » en réponses JSON.
 */
final class Server
{
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var Tool[] */
    private array $tools = [];

    public function __construct(private Context $ctx)
    {
        foreach (self::toolClasses() as $class) {
            if (!class_exists($class)) {
                continue;
            }
            /** @var Tool $tool */
            $tool = new $class();
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return class-string<Tool>[] */
    public static function toolClasses(): array
    {
        return [
            // Session & découverte
            Tools\WhoAmI::class,
            Tools\SwitchContext::class,
            Tools\ListItemtypes::class,
            Tools\DescribeItemtype::class,
            // Lecture
            Tools\SearchItems::class,
            Tools\GetItem::class,
            Tools\AssetSummary::class,
            // Écriture (aperçu → confirmation → journal → annulation)
            Tools\CreateItems::class,
            Tools\UpdateItem::class,
            Tools\DeleteItem::class,
            Tools\ConfirmAction::class,
            Tools\CancelAction::class,
            Tools\ListPendingActions::class,
            Tools\AiHistory::class,
            Tools\RollbackAction::class,
            // Assistance
            Tools\TicketCreate::class,
            Tools\TicketTimeline::class,
            Tools\TicketAddFollowup::class,
            Tools\TicketAddTask::class,
            Tools\TicketAddSolution::class,
            Tools\TicketAssign::class,
            Tools\TicketValidationAnswer::class,
            Tools\KnowbaseSearch::class,

            Tools\ProjectList::class,
            Tools\ProjectGet::class,
            Tools\ProjectMyTasks::class,
            Tools\ProjectTaskAdd::class,
            Tools\ProjectTaskUpdate::class,
            // Formulaires (catalogue de services)
            Tools\FormList::class,
            Tools\FormGet::class,
            Tools\FormQuestionTypes::class,
            Tools\FormSubmit::class,
            // Configuration (lecture et réglages simples)
            Tools\ConfigGet::class,
            Tools\ConfigSet::class,
            Tools\ProfileRights::class,
            Tools\RuleList::class,
            // Primitives pour les programmes (Dokore, scripts…)
            Tools\ExportItems::class,
            Tools\SystemFacts::class,
            Tools\ApplyOperations::class,
        ];
    }

    public function handle(array $msg): ?array
    {
        $id     = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $is_notification = !array_key_exists('id', $msg);

        if (($msg['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return $is_notification ? null : self::error($id, -32600, 'Invalid Request');
        }

        try {
            $result = match ($method) {
                'initialize'                => $this->initialize($params),
                'ping'                      => new \stdClass(),
                'tools/list'                => $this->listTools(),
                'tools/call'                => $this->callTool($params),
                'prompts/list'              => ['prompts' => Prompts::list()],
                'prompts/get'               => Prompts::get((string) ($params['name'] ?? ''), $params['arguments'] ?? []),
                'resources/list'            => ['resources' => []],
                'resources/templates/list'  => ['resourceTemplates' => []],
                'logging/setLevel'          => new \stdClass(),
                default                     => str_starts_with($method, 'notifications/') ? null : throw new RpcException(-32601, "Method not found: {$method}"),
            };
        } catch (RpcException $e) {
            return $is_notification ? null : self::error($id, $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            ErrorHandler::logCaughtException($e);
            return $is_notification ? null : self::error($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        if ($is_notification) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities'    => [
                'tools'   => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'glpi-mcp',
                'title'   => 'GLPI MCP',
                'version' => PLUGIN_GLPIMCP_VERSION,
            ],
            'instructions' => Prompts::serverInstructions($this->ctx),
        ];
    }

    private function listTools(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            if ($tool->isAvailable($this->ctx) && Permissions::toolAllowed($tool->name())) {
                $list[] = $tool->definition();
            }
        }
        return ['tools' => $list];
    }

    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        $tool = $this->tools[$name] ?? null;
        if ($tool === null || !$tool->isAvailable($this->ctx) || !Permissions::toolAllowed($tool->name())) {
            throw new RpcException(-32602, "Unknown tool: {$name}");
        }

        try {
            $data = $tool->call($args, $this->ctx);
            return self::toolResult($data, false);
        } catch (ToolException $e) {
            $payload = ['erreur' => $e->getMessage()];
            if ($e->getDetails()) {
                $payload['détails'] = $e->getDetails();
            }
            return self::toolResult($payload, true);
        } catch (Throwable $e) {
            ErrorHandler::logCaughtException($e);
            Items::flushMessages();
            return self::toolResult(['erreur' => 'Erreur GLPI : ' . $e->getMessage()], true);
        }
    }

    private static function toolResult(mixed $data, bool $is_error): array
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $result = [
            'content' => [['type' => 'text', 'text' => $json]],
            'isError' => $is_error,
        ];
        if (is_array($data) && !array_is_list($data)) {
            $result['structuredContent'] = $data;
        }
        return $result;
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
