<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\ShowTextSchema;
use Slim\Psr7\Response;

test('writes plain text from config', function (): void {
    $schema = new ShowTextSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['body' => 'Hello world'], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/plain');
    expect((string)$resp->getBody())->toBe('Hello world');
});

test('empty body when config missing', function (): void {
    $schema = new ShowTextSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, [], new Response());
    expect((string)$resp->getBody())->toBe('');
});
