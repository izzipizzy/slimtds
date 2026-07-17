<?php

declare(strict_types=1);

use App\Admin\Repository\SettingsRepository;
use App\Pixel\ScriptController;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// ── helpers ────────────────────────────────────────────────────────────────

function realPixelPath(): string
{
    return dirname(__DIR__, 3) . '/public/p.js';
}

function tmpPixelPath(): string
{
    return sys_get_temp_dir() . '/slimtds_test_pixel_' . getmypid() . '.js';
}

function makeCtrl(string $file): ScriptController
{
    $repo = new SettingsRepository(new Connection(pdo()));
    return new ScriptController($file, $repo);
}

// ── tests ──────────────────────────────────────────────────────────────────

test('200 with correct headers when p.js exists', function (): void {
    $file = tmpPixelPath();
    file_put_contents($file, '(function(){var x=1;})()');

    $ctrl = makeCtrl($file);
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/p.js');
    $resp = $ctrl($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toContain('application/javascript');
    expect($resp->getHeaderLine('Cache-Control'))->toContain('max-age=300');
    expect($resp->getHeaderLine('ETag'))->not->toBeEmpty();
    expect((string)$resp->getBody())->toContain('function');

    unlink($file);
});

test('ETag matches md5 of prefixed body', function (): void {
    $file    = tmpPixelPath();
    $content = '(function(){})()';
    file_put_contents($file, $content);

    $ctrl = makeCtrl($file);
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/p.js');
    $resp = $ctrl($req, new Response());

    $servedBody = (string) $resp->getBody();
    expect($resp->getHeaderLine('ETag'))->toBe('"' . md5($servedBody) . '"');

    unlink($file);
});

test('304 when If-None-Match matches ETag', function (): void {
    $file    = tmpPixelPath();
    $content = '(function(){var y=2;})()';
    file_put_contents($file, $content);

    // Compute the ETag the way the controller now does: md5(prefix + content).
    // We need the actual prefix, so we instantiate the controller and do a
    // first request to discover the real ETag.
    $ctrl = makeCtrl($file);
    $req1 = (new ServerRequestFactory())->createServerRequest('GET', '/p.js');
    $etag = $ctrl($req1, new Response())->getHeaderLine('ETag');

    $req2 = (new ServerRequestFactory())
        ->createServerRequest('GET', '/p.js')
        ->withHeader('If-None-Match', $etag);
    $resp = $ctrl($req2, new Response());

    expect($resp->getStatusCode())->toBe(304);
    expect((string)$resp->getBody())->toBe('');

    unlink($file);
});

test('503 when p.js is missing', function (): void {
    $repo = new SettingsRepository(new Connection(pdo()));
    $ctrl = new ScriptController('/nonexistent/path/p.js', $repo);
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/p.js');
    $resp = $ctrl($req, new Response());

    expect($resp->getStatusCode())->toBe(503);
});
