<?php

declare(strict_types=1);

use App\Admin\Middleware\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function runCsrf(string $method, array $body, ?string $sessionToken): ResponseInterface
{
    $_SESSION = $sessionToken !== null ? ['csrf_token' => $sessionToken] : [];
    $request = (new ServerRequestFactory())->createServerRequest($method, '/test');
    $request = $request->withParsedBody($body);
    $mw = new CsrfMiddleware();
    $next = new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $r): ResponseInterface {
            return (new Response())->withStatus(200);
        }
    };
    return $mw->process($request, $next);
}

test('GET passes through without token', function (): void {
    $resp = runCsrf('GET', [], 'abcd');
    expect($resp->getStatusCode())->toBe(200);
});

test('POST with matching token passes', function (): void {
    $token = str_repeat('a', 64);
    $resp = runCsrf('POST', ['_csrf' => $token], $token);
    expect($resp->getStatusCode())->toBe(200);
});

test('POST with missing token rejects with 403', function (): void {
    $resp = runCsrf('POST', [], str_repeat('a', 64));
    expect($resp->getStatusCode())->toBe(403);
});

test('POST with mismatched token rejects with 403', function (): void {
    $resp = runCsrf('POST', ['_csrf' => 'wrong'], str_repeat('a', 64));
    expect($resp->getStatusCode())->toBe(403);
});

test('POST without session token (new user) generates one and rejects the request', function (): void {
    $_SESSION = [];
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/test');
    $req = $req->withParsedBody([]);
    $mw = new CsrfMiddleware();
    $next = new class implements RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $r): ResponseInterface {
            return (new Response())->withStatus(200);
        }
    };
    $resp = $mw->process($req, $next);
    expect($resp->getStatusCode())->toBe(403);
    expect($_SESSION['csrf_token'] ?? '')->toMatch('/^[0-9a-f]{64}$/');
});
