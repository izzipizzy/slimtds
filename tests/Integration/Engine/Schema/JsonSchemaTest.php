<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\JsonSchema;
use Slim\Psr7\Response;

test('encodes array body as JSON', function (): void {
    $schema = new JsonSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['body' => ['ok' => true, 'code' => 1]], new Response());
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toBe('application/json');
    $data = json_decode((string)$resp->getBody(), true);
    expect($data['ok'])->toBeTrue();
    expect($data['code'])->toBe(1);
});

test('valid JSON string is re-encoded canonically', function (): void {
    $schema = new JsonSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    // Deliberately messy whitespace JSON
    $input = '{"b":2,  "a":  1}';
    $resp = $schema->respond($ctx, null, ['body' => $input], new Response());
    $out = (string)$resp->getBody();
    // Must parse and be valid JSON
    $data = json_decode($out, true);
    expect($data)->toBeArray();
    expect($data['a'])->toBe(1);
    expect($data['b'])->toBe(2);
});

test('non-JSON string passes through as-is', function (): void {
    $schema = new JsonSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, null, ['body' => 'not json at all'], new Response());
    expect((string)$resp->getBody())->toBe('not json at all');
});
