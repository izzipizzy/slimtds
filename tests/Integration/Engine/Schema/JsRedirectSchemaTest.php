<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\Schema\JsRedirectSchema;
use Slim\Psr7\Response;

test('emits js redirect for a normal url', function (): void {
    $schema = new JsRedirectSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $resp = $schema->respond($ctx, 'https://example.com/landing', [], new Response());
    $body = (string)$resp->getBody();
    expect($resp->getHeaderLine('Content-Type'))->toContain('text/html');
    expect($body)->toContain('window.location.replace(');
    expect($body)->toContain('https://example.com/landing');
});

test('null/empty url returns 204', function (): void {
    $schema = new JsRedirectSchema();
    $resp = $schema->respond(new Context('1.1.1.1', 'curl', 'demo', time()), null, [], new Response());
    expect($resp->getStatusCode())->toBe(204);
});

test('a </script> in the target url cannot break out of the inline script', function (): void {
    $schema = new JsRedirectSchema();
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $payload = 'https://p.com/?s=</script><script>alert(document.domain)</script>';
    $resp = $schema->respond($ctx, $payload, [], new Response());
    $body = (string)$resp->getBody();

    // The only literal script tag pair is the wrapper. A raw </script> from the
    // URL would inject a second pair — assert exactly one opening and one closing.
    expect(substr_count($body, '<script>'))->toBe(1);
    expect(substr_count($body, '</script>'))->toBe(1);
    // The payload's angle brackets must be present only in hex-escaped form.
    expect($body)->not->toContain('<script>alert');
});
