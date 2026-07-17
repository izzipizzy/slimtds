<?php

declare(strict_types=1);

use App\Admin\Middleware\RateLimitMiddleware;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Db\Connection;
use App\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.rate_limits');
    $pdo->exec('DELETE FROM core.auth_events');
    $this->db = new Connection($pdo);
    $this->limiter = new RateLimiter($this->db);
    $this->audit = new AuthEventLogger($this->db);
    $this->mw = new RateLimitMiddleware($this->limiter, $this->audit);
});

function runRL(RateLimitMiddleware $mw, array $body, string $ip): ResponseInterface
{
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody($body);
    $req = $req->withAttribute('_ignore', null);
    $server = $req->getServerParams();
    $server['REMOTE_ADDR'] = $ip;
    // Slim PSR7 doesn't expose setServerParams; use withAttribute simulation
    // For test simplicity, we recreate via Slim7's base factory which reads from $_SERVER
    $_SERVER['REMOTE_ADDR'] = $ip;
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody($body);

    $next = new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $r): ResponseInterface {
            return (new Response())->withStatus(200);
        }
    };
    return $mw->process($req, $next);
}

test('allows under limit', function (): void {
    $_ENV['RATE_LIMIT_IP'] = '3';
    $_ENV['RATE_LIMIT_LOGIN'] = '3';

    for ($i = 0; $i < 3; $i++) {
        $resp = runRL($this->mw, ['login' => 'x'], '10.0.0.1');
        expect($resp->getStatusCode())->toBe(200);
    }
});

test('blocks over limit with 429 and audit row', function (): void {
    $_ENV['RATE_LIMIT_IP'] = '2';
    $_ENV['RATE_LIMIT_LOGIN'] = '2';

    runRL($this->mw, ['login' => 'y'], '10.0.0.2');
    runRL($this->mw, ['login' => 'y'], '10.0.0.2');
    $resp = runRL($this->mw, ['login' => 'y'], '10.0.0.2');

    expect($resp->getStatusCode())->toBe(429);
    expect($resp->getHeaderLine('Retry-After'))->not->toBe('');

    $rows = $this->db->fetchAll('SELECT event_type, admin_login FROM core.auth_events WHERE event_type = :t', ['t' => 'rate_limited']);
    expect(count($rows))->toBeGreaterThan(0);
    expect($rows[0]['admin_login'])->toBe('y');
});
