<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\MetaRefreshSchema;
use Slim\Psr7\Response;

test('body contains url in meta refresh tag', function (): void {
    $schema = new MetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    expect((string)$resp->getBody())->toContain('https://example.com/landing');
});

test('url is html-escaped in meta refresh', function (): void {
    $schema = new MetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/?a=1&b=<script>', [], new Response());
    $body = (string)$resp->getBody();
    expect($body)->toContain('&amp;');
    expect($body)->toContain('&lt;script&gt;');
});

test('delay config is applied', function (): void {
    $schema = new MetaRefreshSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/', ['delay' => 5], new Response());
    expect((string)$resp->getBody())->toContain('5;url=');
});

test('null url returns 204', function (): void {
    $schema = new MetaRefreshSchema();
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});
