<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\OfferPicker;

test('picks the only offer when single candidate', function (): void {
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $ctx->visitorUuid = '019dc137-724a-756c-923a-a392001e3d79';
    $picked = (new OfferPicker())->pick([['offer_id' => 'a', 'weight' => 100]], $ctx);
    expect($picked)->toBe('a');
});

test('stable per visitor', function (): void {
    $picker = new OfferPicker();
    $candidates = [
        ['offer_id' => 'a', 'weight' => 50],
        ['offer_id' => 'b', 'weight' => 50],
    ];
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $ctx->visitorUuid = '019dc137-724a-756c-923a-a392001e3d79';

    $first = $picker->pick($candidates, $ctx);
    $second = $picker->pick($candidates, $ctx);
    expect($first)->toBe($second);
});

test('weight 0 excluded', function (): void {
    $candidates = [
        ['offer_id' => 'zero', 'weight' => 0],
        ['offer_id' => 'real', 'weight' => 100],
    ];
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $ctx->visitorUuid = '019dc137-724a-756c-923a-a392001e3d79';
    expect((new OfferPicker())->pick($candidates, $ctx))->toBe('real');
});

test('empty list returns null', function (): void {
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    expect((new OfferPicker())->pick([], $ctx))->toBeNull();
});

test('non-sticky rotates for the SAME visitor and matches weights', function (): void {
    $picker = new OfferPicker();
    $candidates = [
        ['offer_id' => 'a', 'weight' => 70],
        ['offer_id' => 'b', 'weight' => 30],
    ];
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $ctx->visitorUuid = '019dc137-724a-756c-923a-a392001e3d79'; // fixed visitor

    $counts = ['a' => 0, 'b' => 0];
    for ($i = 0; $i < 5000; $i++) {
        $counts[$picker->pick($candidates, $ctx, false)]++; // sticky = false
    }
    // Same visitor, yet both offers appear (rotation) and follow the weights.
    expect($counts['a'])->toBeGreaterThan(0)->and($counts['b'])->toBeGreaterThan(0);
    expect($counts['a'] / 5000)->toBeGreaterThan(0.65)->toBeLessThan(0.75);
});

test('sticky pins the same visitor across calls', function (): void {
    $picker = new OfferPicker();
    $candidates = [['offer_id' => 'a', 'weight' => 50], ['offer_id' => 'b', 'weight' => 50]];
    $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
    $ctx->visitorUuid = '019dc137-724a-756c-923a-a392001e3d79';
    $picks = array_map(fn () => $picker->pick($candidates, $ctx, true), range(1, 20));
    expect(array_unique($picks))->toHaveCount(1); // always the same offer
});

test('distribution roughly matches weights over many visitors', function (): void {
    $picker = new OfferPicker();
    $candidates = [
        ['offer_id' => 'a', 'weight' => 70],
        ['offer_id' => 'b', 'weight' => 30],
    ];
    $counts = ['a' => 0, 'b' => 0];
    for ($i = 0; $i < 5000; $i++) {
        $ctx = new Context('1.1.1.1', 'curl', 'demo', time());
        $ctx->visitorUuid = bin2hex(random_bytes(16));
        $picked = $picker->pick($candidates, $ctx);
        $counts[$picked]++;
    }
    expect($counts['a'] / 5000)->toBeGreaterThan(0.65)->toBeLessThan(0.75);
});
