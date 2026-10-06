<?php

declare(strict_types=1);

use App\Mcp\JsonRpcDispatcher;
use App\Mcp\PromptRegistry;
use App\Mcp\Tool\Schema;
use App\Mcp\Tool\ToolError;
use App\Mcp\Tool\ToolInterface;
use App\Mcp\ToolRegistry;

function mcpEchoTool(): ToolInterface
{
    return new class implements ToolInterface {
        public function name(): string { return 'echo'; }
        public function description(): string { return 'Echo back.'; }
        public function inputSchema(): array { return Schema::object(['say' => ['type' => 'string']]); }
        public function call(array $args): array
        {
            if (($args['say'] ?? '') === 'bad') {
                throw new ToolError('say something else');
            }
            if (($args['say'] ?? '') === 'boom') {
                throw new \LogicException('secret internals');
            }
            if (($args['say'] ?? '') === 'nan') {
                return ['x' => NAN];
            }
            return ['said' => $args['say'] ?? null];
        }
    };
}

beforeEach(function (): void {
    $this->d = new JsonRpcDispatcher(new ToolRegistry(mcpEchoTool()), new PromptRegistry(), 'v9.9.9');
    $this->rpc = fn (array $msg): ?array => $this->d->handle(json_encode($msg, JSON_THROW_ON_ERROR));
});

test('malformed json is a parse error', function (): void {
    expect($this->d->handle('{nope')['error']['code'])->toBe(-32700);
});

test('a message without jsonrpc 2.0 is an invalid request', function (): void {
    expect(($this->rpc)(['id' => 1, 'method' => 'ping'])['error']['code'])->toBe(-32600);
});

test('initialize echoes a supported version and falls back otherwise', function (): void {
    $ok = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']]);
    expect($ok['result']['protocolVersion'])->toBe('2025-03-26');
    expect($ok['result']['serverInfo'])->toBe(['name' => 'slimtds', 'title' => 'slimTDS traffic analysis', 'version' => 'v9.9.9']);
    expect($ok['result']['instructions'])->toContain('list_campaigns');
    $old = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
    expect($old['result']['protocolVersion'])->toBe('2025-06-18');
});

test('capabilities encode as json objects, not arrays', function (): void {
    $r = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
    expect(json_encode($r['result']['capabilities']))->toBe('{"tools":{},"prompts":{}}');
});

test('notifications produce no response', function (): void {
    expect(($this->rpc)(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']))->toBeNull();
});

test('ping answers an empty object', function (): void {
    $r = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping']);
    expect(json_encode($r))->toBe('{"jsonrpc":"2.0","id":"a","result":{}}');
});

test('unknown method', function (): void {
    expect(($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])['error']['code'])->toBe(-32601);
});

test('tools/list describes every tool as read-only', function (): void {
    $tools = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'];
    expect($tools)->toHaveCount(1);
    expect($tools[0]['name'])->toBe('echo');
    expect($tools[0]['annotations'])->toBe(['readOnlyHint' => true, 'openWorldHint' => false]);
    expect($tools[0]['inputSchema']['type'])->toBe('object');
});

test('tools/call returns text and structured content', function (): void {
    $r = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['say' => 'hi']]])['result'];
    expect($r['isError'])->toBeFalse();
    expect($r['structuredContent'])->toBe(['said' => 'hi']);
    expect(json_decode($r['content'][0]['text'], true))->toBe(['said' => 'hi']);
});

test('a ToolError reaches the model, an unexpected exception does not leak', function (): void {
    $call = fn (string $say) => ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['say' => $say]]])['result'];
    expect($call('bad')['isError'])->toBeTrue();
    expect($call('bad')['content'][0]['text'])->toBe('say something else');
    expect($call('boom')['isError'])->toBeTrue();
    expect($call('boom')['content'][0]['text'])->not->toContain('secret');
});

test('an unencodable tool result is a tool error, not an uncaught exception', function (): void {
    $r = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['say' => 'nan']]]);
    expect($r)->not->toHaveKey('error');
    expect($r['result']['isError'])->toBeTrue();
});

test('unknown tool is invalid params', function (): void {
    $r = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
    expect($r['error']['code'])->toBe(-32602);
});

test('a batch answers only the requests in it', function (): void {
    $r = $this->d->handle(json_encode([
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
    ]));
    expect($r)->toHaveCount(1);
    expect($r[0]['id'])->toBe(1);
});

test('the guard wraps every tool call', function (): void {
    $seen = 0;
    $d = new JsonRpcDispatcher(new ToolRegistry(mcpEchoTool()), new PromptRegistry(), 'v1', function (callable $fn) use (&$seen) {
        $seen++;
        return $fn();
    });
    $d->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]]));
    expect($seen)->toBe(1);
});

test('prompts are listed and rendered with arguments', function (): void {
    $list = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'])['result']['prompts'];
    expect(array_column($list, 'name'))->toBe(['traffic_review', 'bot_audit', 'funnel_leaks']);
    $got = ($this->rpc)(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get', 'params' => ['name' => 'traffic_review', 'arguments' => ['campaign' => 'abc123']]])['result'];
    expect($got['messages'][0]['content']['text'])->toContain('abc123');
});
