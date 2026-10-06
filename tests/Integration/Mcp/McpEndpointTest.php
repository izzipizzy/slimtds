<?php

declare(strict_types=1);

require_once __DIR__ . '/McpFixtures.php';

use App\Admin\Repository\SettingsRepository;
use App\Mcp\ApiKeyService;
use App\Mcp\BearerAuthMiddleware;
use App\Mcp\JsonRpcDispatcher;
use App\Mcp\McpController;
use App\Mcp\PromptRegistry;
use App\Mcp\SkillController;
use App\Mcp\Tool\Schema;
use App\Mcp\Tool\ToolInterface;
use App\Mcp\ToolRegistry;
use App\Shared\Db\Connection;
use App\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    $this->db->execute("DELETE FROM core.settings WHERE key LIKE 'mcp_%'");
    $this->db->execute("DELETE FROM core.rate_limits WHERE key LIKE 'mcp_%'");
    $this->keys = new ApiKeyService(new SettingsRepository($this->db));

    $writer = new class($this->db) implements ToolInterface {
        public function __construct(private Connection $db) {}
        public function name(): string { return 'write'; }
        public function description(): string { return 'Tries to write.'; }
        public function inputSchema(): array { return Schema::object([]); }
        public function call(array $args): array
        {
            $this->db->execute("INSERT INTO core.settings (key, value) VALUES ('mcp_pwned', '1')");
            return ['wrote' => true];
        }
    };
    $this->mcp = new McpController(
        new JsonRpcDispatcher(new ToolRegistry($writer), new PromptRegistry(), 'test', McpController::readOnlyGuard($this->db)),
        $this->keys,
    );
    $mw = new BearerAuthMiddleware($this->keys, new RateLimiter($this->db));
    $handler = new class($this->mcp) implements RequestHandlerInterface {
        public function __construct(private McpController $c) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return ($this->c)($request, new Response());
        }
    };
    // $remoteAddr is the TCP peer (REMOTE_ADDR); $headers can carry a
    // client-supplied X-Real-IP etc. to prove those are never trusted.
    $this->post = function (string $body, ?string $key, string $remoteAddr = '198.51.100.7', array $headers = []) use ($mw, $handler): ResponseInterface {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/mcp', ['REMOTE_ADDR' => $remoteAddr]);
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        if ($key !== null) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $key);
        }
        $req->getBody()->write($body);
        $req->getBody()->rewind();
        return $mw->process($req, $handler);
    };
    $this->ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
});

test('404 while no key exists', function (): void {
    expect(($this->post)($this->ping, 'stds_x')->getStatusCode())->toBe(404);
});

test('401 with a challenge for a missing or wrong key', function (): void {
    $this->keys->generate();
    $r = ($this->post)($this->ping, null);
    expect($r->getStatusCode())->toBe(401);
    expect($r->getHeaderLine('WWW-Authenticate'))->toBe('Bearer');

    $r2 = ($this->post)($this->ping, 'stds_wrong');
    expect($r2->getStatusCode())->toBe(401);
    expect($r2->getHeaderLine('WWW-Authenticate'))->toBe('Bearer');
});

test('a valid key gets a json answer and marks the key as used', function (): void {
    $key = $this->keys->generate();
    $r = ($this->post)($this->ping, $key);
    expect($r->getStatusCode())->toBe(200);
    expect($r->getHeaderLine('Content-Type'))->toBe('application/json');
    expect((string)$r->getBody())->toBe('{"jsonrpc":"2.0","id":1,"result":{}}');
    expect($this->keys->status()['last_used_at'])->not->toBe('');
});

test('a notification is acknowledged with 202 and no body', function (): void {
    $key = $this->keys->generate();
    $r = ($this->post)('{"jsonrpc":"2.0","method":"notifications/initialized"}', $key);
    expect($r->getStatusCode())->toBe(202);
    expect((string)$r->getBody())->toBe('');
});

test('ten failures from one address lock out further wrong-key attempts, without growing past 10', function (): void {
    $this->keys->generate();
    for ($i = 0; $i < 10; $i++) {
        ($this->post)($this->ping, 'stds_wrong', '198.51.100.7');
    }
    $r = ($this->post)($this->ping, 'stds_wrong', '198.51.100.7');
    expect($r->getStatusCode())->toBe(429);
    $retryAfter = (int)$r->getHeaderLine('Retry-After');
    expect($retryAfter)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
    // The lockout check itself must not write: still exactly 10 hits.
    expect((new RateLimiter($this->db))->current('mcp_fail:198.51.100.7', 60))->toBe(10);
});

test('a valid key still works from an address locked out for wrong keys', function (): void {
    $key = $this->keys->generate();
    for ($i = 0; $i < 10; $i++) {
        ($this->post)($this->ping, 'stds_wrong', '198.51.100.7');
    }
    $r = ($this->post)($this->ping, $key, '198.51.100.7');
    expect($r->getStatusCode())->toBe(200);
});

test('a spoofed X-Real-IP does not let an attacker dodge the lockout', function (): void {
    $this->keys->generate();
    for ($i = 0; $i < 10; $i++) {
        ($this->post)($this->ping, 'stds_wrong', '198.51.100.7', ['X-Real-IP' => "203.0.113.{$i}"]);
    }
    $r = ($this->post)($this->ping, 'stds_wrong', '198.51.100.7');
    expect($r->getStatusCode())->toBe(429);
});

test('a spoofed X-Real-IP cannot frame a different address for the lockout', function (): void {
    $this->keys->generate();
    for ($i = 0; $i < 10; $i++) {
        ($this->post)($this->ping, 'stds_wrong', '198.51.100.7', ['X-Real-IP' => '203.0.113.9']);
    }
    $r = ($this->post)($this->ping, 'stds_wrong', '203.0.113.9');
    expect($r->getStatusCode())->toBe(401);
});

test('the 120 per minute key budget is enforced with a bounded Retry-After', function (): void {
    $key = $this->keys->generate();
    for ($i = 0; $i < 120; $i++) {
        expect(($this->post)($this->ping, $key)->getStatusCode())->toBe(200);
    }
    $r = ($this->post)($this->ping, $key);
    expect($r->getStatusCode())->toBe(429);
    $retryAfter = (int)$r->getHeaderLine('Retry-After');
    expect($retryAfter)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
});

test('tools run in a read-only transaction', function (): void {
    $key = $this->keys->generate();
    $r = ($this->post)('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"write","arguments":{}}}', $key);
    expect(json_decode((string)$r->getBody(), true)['result']['isError'])->toBeTrue();
    expect((int)$this->db->fetchScalar("SELECT count(*) FROM core.settings WHERE key = 'mcp_pwned'"))->toBe(0);
});

test('methodNotAllowed is 404 while disabled and 405 with Allow: POST once a key exists', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/mcp');
    $r = ($this->mcp)->methodNotAllowed($req, new Response());
    expect($r->getStatusCode())->toBe(404);

    $this->keys->generate();
    $r2 = ($this->mcp)->methodNotAllowed($req, new Response());
    expect($r2->getStatusCode())->toBe(405);
    expect($r2->getHeaderLine('Allow'))->toBe('POST');
});

test('the skill is public once MCP is on, and absent before', function (): void {
    $skill = new SkillController($this->keys);
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/mcp/skill');
    expect($skill($req, new Response())->getStatusCode())->toBe(404);
    $this->keys->generate();
    $r = $skill($req, new Response());
    expect($r->getStatusCode())->toBe(200);
    expect($r->getHeaderLine('Content-Type'))->toBe('text/markdown; charset=utf-8');
    expect((string)$r->getBody())->toContain('name: slimtds-traffic-analysis');
});
