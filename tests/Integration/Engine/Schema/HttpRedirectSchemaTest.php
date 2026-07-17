<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\HttpRedirectSchema;
use Slim\Psr7\Response;

dataset('codes', [301, 302, 303, 307, 308]);

test('emits proper redirect', function (int $code): void {
    $schema = new HttpRedirectSchema($code);
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    expect($resp->getStatusCode())->toBe($code);
    expect($resp->getHeaderLine('Location'))->toBe('https://example.com/landing');
})->with('codes');

test('rejects unsupported code', function (): void {
    new HttpRedirectSchema(999);
})->throws(InvalidArgumentException::class);

test('null url returns 204', function (): void {
    $schema = new HttpRedirectSchema(302);
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});
