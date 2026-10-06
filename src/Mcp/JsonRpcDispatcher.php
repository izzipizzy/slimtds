<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tool\ToolError;

/**
 * Stateless JSON-RPC 2.0 for the MCP Streamable HTTP transport. Nothing is
 * remembered between requests, so `initialize` is never a precondition.
 */
final class JsonRpcDispatcher
{
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    public const DEFAULT_VERSION = '2025-06-18';

    private readonly \Closure $guard;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly PromptRegistry $prompts,
        private readonly string $serverVersion,
        ?\Closure $guard = null,
    ) {
        $this->guard = $guard ?? static fn (callable $fn): mixed => $fn();
    }

    /** @return array<mixed>|null null = only notifications came in; answer 202 without a body */
    public function handle(string $raw): ?array
    {
        try {
            $msg = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error(null, -32700, 'Parse error');
        }
        if (!is_array($msg) || $msg === []) {
            return self::error(null, -32600, 'Invalid Request');
        }
        if (!array_is_list($msg)) {
            return $this->one($msg);
        }
        $out = [];
        foreach ($msg as $m) {
            $r = is_array($m) ? $this->one($m) : self::error(null, -32600, 'Invalid Request');
            if ($r !== null) {
                $out[] = $r;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param array<mixed> $m
     * @return array<string,mixed>|null
     */
    private function one(array $m): ?array
    {
        $id = $m['id'] ?? null;
        if (($m['jsonrpc'] ?? null) !== '2.0' || !is_string($m['method'] ?? null)) {
            return self::error($id, -32600, 'Invalid Request');
        }
        if (!array_key_exists('id', $m)) {
            return null; // a notification: nothing here needs acting on
        }
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];

        return match ($m['method']) {
            'initialize'   => self::result($id, $this->initialize($params)),
            'ping'         => self::result($id, new \stdClass()),
            'tools/list'   => self::result($id, ['tools' => $this->tools->describe()]),
            'tools/call'   => $this->callTool($id, $params),
            'prompts/list' => self::result($id, ['prompts' => $this->prompts->describe()]),
            'prompts/get'  => $this->getPrompt($id, $params),
            default        => self::error($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param array<mixed> $params
     * @return array<string,mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;
        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::DEFAULT_VERSION,
            'capabilities'    => ['tools' => new \stdClass(), 'prompts' => new \stdClass()],
            'serverInfo'      => ['name' => 'slimtds', 'title' => 'slimTDS traffic analysis', 'version' => $this->serverVersion],
            'instructions'    => Instructions::TEXT,
        ];
    }

    /**
     * @param array<mixed> $params
     * @return array<string,mixed>
     */
    private function callTool(mixed $id, array $params): array
    {
        $tool = is_string($params['name'] ?? null) ? $this->tools->get($params['name']) : null;
        if ($tool === null) {
            return self::error($id, -32602, 'Unknown tool');
        }
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $data = ($this->guard)(static fn (): array => $tool->call($args));
            // Encoding failure (NAN/INF, invalid UTF-8) is as much a tool
            // failure as an exception from call() itself, so it belongs in
            // this same try: the model gets isError:true, not a 500.
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (ToolError $e) {
            return self::result($id, self::toolText($e->getMessage(), true));
        } catch (\Throwable $e) {
            error_log('mcp tool ' . $tool->name() . ': ' . $e->getMessage());
            return self::result($id, self::toolText('The tool failed on the server side. Try a narrower period or fewer filters.', true));
        }
        return self::result($id, [
            'content'           => [['type' => 'text', 'text' => $json]],
            'structuredContent' => $data,
            'isError'           => false,
        ]);
    }

    /**
     * @param array<mixed> $params
     * @return array<string,mixed>
     */
    private function getPrompt(mixed $id, array $params): array
    {
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $prompt = is_string($params['name'] ?? null) ? $this->prompts->get($params['name'], $args) : null;
        return $prompt === null ? self::error($id, -32602, 'Unknown prompt') : self::result($id, $prompt);
    }

    /** @return array<string,mixed> */
    private static function toolText(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /** @return array<string,mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
