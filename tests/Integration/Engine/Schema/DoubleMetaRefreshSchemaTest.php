<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\DoubleMetaRefreshSchema;
use Slim\Psr7\Response;

test('body contains url and no-referrer meta', function (): void {
    $schema = new DoubleMetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    expect($resp->getStatusCode())->toBe(200);
    $body = (string)$resp->getBody();
    expect($body)->toContain('https://example.com/landing');
    expect($body)->toContain('no-referrer');
});

test('sets Referrer-Policy header', function (): void {
    $schema = new DoubleMetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/', [], new Response());
    expect($resp->getHeaderLine('Referrer-Policy'))->toBe('no-referrer');
});

test('url is html-escaped', function (): void {
    $schema = new DoubleMetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/?a=1&b=2', [], new Response());
    expect((string)$resp->getBody())->toContain('&amp;');
});

test('null url returns 204', function (): void {
    $schema = new DoubleMetaRefreshSchema();
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});
