<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\HtmlPageSchema;
use Slim\Psr7\Response;

test('writes html body from config', function (): void {
    $schema = new HtmlPageSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['body' => '<h1>Hello</h1>'], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    expect((string)$resp->getBody())->toBe('<h1>Hello</h1>');
});

test('empty body when config missing', function (): void {
    $schema = new HtmlPageSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, [], new Response());
    expect((string)$resp->getBody())->toBe('');
});

test('outUrl is ignored, config body is used', function (): void {
    $schema = new HtmlPageSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com', ['body' => '<p>page</p>'], new Response());
    expect((string)$resp->getBody())->toBe('<p>page</p>');
});
