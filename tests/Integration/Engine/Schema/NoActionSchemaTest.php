<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\NoActionSchema;
use Slim\Psr7\Response;

test('returns 200 with empty body', function (): void {
    $schema = new NoActionSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, [], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect((string)$resp->getBody())->toBe('');
});

test('ignores outUrl and config', function (): void {
    $schema = new NoActionSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com', ['body' => 'ignored'], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect((string)$resp->getBody())->toBe('');
});
