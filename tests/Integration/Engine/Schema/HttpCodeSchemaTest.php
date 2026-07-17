<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\HttpCodeSchema;
use Slim\Psr7\Response;

test('returns 403 with body', function (): void {
    $schema = new HttpCodeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['status_code' => 403, 'body' => 'Forbidden'], new Response());
    expect($resp->getStatusCode())->toBe(403);
    expect((string)$resp->getBody())->toBe('Forbidden');
});

test('returns 404 without body', function (): void {
    $schema = new HttpCodeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['status_code' => 404], new Response());
    expect($resp->getStatusCode())->toBe(404);
    expect((string)$resp->getBody())->toBe('');
});

test('invalid code clamps to 200', function (): void {
    $schema = new HttpCodeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['status_code' => 9999], new Response());
    expect($resp->getStatusCode())->toBe(200);
});

test('code below 100 clamps to 200', function (): void {
    $schema = new HttpCodeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['status_code' => 50], new Response());
    expect($resp->getStatusCode())->toBe(200);
});
