<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\IframeSchema;
use Slim\Psr7\Response;

test('body contains iframe with url', function (): void {
    $schema = new IframeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    $body = (string)$resp->getBody();
    expect($body)->toContain('<iframe');
    expect($body)->toContain('https://example.com/landing');
});

test('url is html-escaped in iframe src', function (): void {
    $schema = new IframeSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/?a=1&b=2', [], new Response());
    expect((string)$resp->getBody())->toContain('&amp;');
});

test('null url returns 204', function (): void {
    $schema = new IframeSchema();
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});
