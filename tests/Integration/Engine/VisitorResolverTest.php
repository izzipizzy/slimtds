<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\VisitorResolver;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec("DELETE FROM stats.visitors_fingerprints WHERE created_at > now() - interval '7 days'");
    $this->resolver = new VisitorResolver(new Connection($pdo));
});

test('reuses uuid from valid cookie', function (): void {
    $existing = '019dc137-724a-756c-923a-a392001e3d79';
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01')
        ->withCookieParams(['vu' => $existing]);
    $ctx = new Context('1.1.1.1', 'curl/8', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx);
    expect($needCookie)->toBeFalse();
    expect($ctx->visitorUuid)->toBe($existing);
    expect($ctx->isUniqVisitor)->toBeFalse();
});

test('rejects malformed cookie and issues fresh uuid', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01')
        ->withCookieParams(['vu' => 'garbage']);
    $ctx = new Context('1.1.1.2', 'curl/8', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx);
    expect($needCookie)->toBeTrue();
    expect($ctx->visitorUuid)->toMatch('/^[0-9a-f]{8}-/');
    expect($ctx->isUniqVisitor)->toBeTrue();
});

test('reuses fingerprint match within 24h window', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01');
    $ctx1 = new Context('5.5.5.5', 'Mozilla/5.0 stable', 'demo01', time());
    $this->resolver->resolve($req, $ctx1);

    $ctx2 = new Context('5.5.5.5', 'Mozilla/5.0 stable', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx2);

    expect($ctx2->visitorUuid)->toBe($ctx1->visitorUuid);
    expect($ctx2->isUniqVisitor)->toBeFalse();
    expect($needCookie)->toBeFalse();
});

test('different UA triggers fresh uuid', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01');
    $a = new Context('9.9.9.9', 'UA-A', 'demo01', time());
    $this->resolver->resolve($req, $a);
    $b = new Context('9.9.9.9', 'UA-B', 'demo01', time());
    $this->resolver->resolve($req, $b);
    expect($a->visitorUuid)->not->toBe($b->visitorUuid);
});
