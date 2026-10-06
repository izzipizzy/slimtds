<?php

declare(strict_types=1);

use App\Shared\TestLink;
use Slim\Psr7\Factory\ServerRequestFactory;

test('campaign and flow keys differ for the same id and are 12 hex chars', function (): void {
    $id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    $c = TestLink::campaignKey($id);
    $f = TestLink::flowKey($id);

    expect($c)->toMatch('/^[0-9a-f]{12}$/')
        ->and($f)->toMatch('/^[0-9a-f]{12}$/')
        ->and($c)->not->toBe($f);
});

test('a key only matches its own scope', function (): void {
    $id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    $link = new TestLink((string)TestLink::flowKey($id));

    expect($link->isFlow($id))->toBeTrue()
        ->and($link->isCampaign($id))->toBeFalse()
        ->and($link->isFlow('0190a1b2-c3d4-7e5f-8a9b-000000000000'))->toBeFalse();
});

test('without APP_SECRET there are no keys and nothing matches', function (): void {
    $saved = $_ENV['APP_SECRET'] ?? null;
    $_ENV['APP_SECRET'] = '';
    try {
        expect(TestLink::flowKey('x'))->toBeNull()
            ->and((new TestLink('000000000000'))->isFlow('x'))->toBeFalse();
    } finally {
        $_ENV['APP_SECRET'] = $saved;
    }
});

test('params are validated and normalised', function (): void {
    expect(TestLink::fromParams([]))->toBeNull()
        ->and(TestLink::fromParams(['_t' => 'nothex!!!!!!']))->toBeNull()
        ->and(TestLink::fromParams(['_t' => ['a']]))->toBeNull();

    $link = TestLink::fromParams(['_t' => 'abcdef012345', '_geo' => 'BR', '_dbg' => '1']);
    expect($link?->geo)->toBe('br')
        ->and($link?->dry)->toBeTrue();

    $junk = TestLink::fromParams(['_t' => 'abcdef012345', '_geo' => 'BRA']);
    expect($junk?->geo)->toBeNull()
        ->and($junk?->dry)->toBeFalse();
});

test('a proxied lander click carries the params in X-Lander-Path', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/Ab3xYz')
        ->withHeader('X-Lander-Path', '/play/offer?_t=abcdef012345&_geo=de');

    expect(TestLink::fromRequest($req)?->geo)->toBe('de');
});
