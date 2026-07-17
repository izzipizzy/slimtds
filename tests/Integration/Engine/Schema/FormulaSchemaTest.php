<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\FormulaSchema;
use Slim\Psr7\Response;

test('returns html body with default content-type and 200', function (): void {
    $schema = new FormulaSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['body' => '<script>alert(1)</script>'], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    expect((string)$resp->getBody())->toBe('<script>alert(1)</script>');
});

test('custom content-type is applied', function (): void {
    $schema = new FormulaSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, [
        'body'         => 'data',
        'content_type' => 'application/octet-stream',
    ], new Response());
    expect($resp->getHeaderLine('Content-Type'))->toBe('application/octet-stream');
});

test('custom status code is applied', function (): void {
    $schema = new FormulaSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, [
        'body'        => '',
        'status_code' => 201,
    ], new Response());
    expect($resp->getStatusCode())->toBe(201);
});
