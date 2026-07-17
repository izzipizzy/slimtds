<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\FilterCompiler;

function ctx(array $overrides = []): Context {
    $c = new Context($overrides['ip'] ?? '1.2.3.4', $overrides['ua'] ?? 'curl/8', 'demo', $overrides['ts'] ?? 1_714_000_000);
    foreach ($overrides as $k => $v) {
        if (!property_exists($c, $k)) continue;
        $c->{$k} = $v;
    }
    return $c;
}

test('empty filter matches everything', function (): void {
    $f = (new FilterCompiler())->compile([]);
    expect($f(ctx()))->toBeTrue();
});

test('eq operator', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'country', 'op' => 'eq', 'value' => 'RU']]]);
    expect($f(ctx(['country' => 'ru'])))->toBeTrue();
    expect($f(ctx(['country' => 'us'])))->toBeFalse();
});

test('AND within group', function (): void {
    $f = (new FilterCompiler())->compile([[
        ['field' => 'country', 'op' => 'eq', 'value' => 'ru'],
        ['field' => 'device',  'op' => 'eq', 'value' => 'mobile'],
    ]]);
    expect($f(ctx(['country' => 'ru', 'device' => 'mobile'])))->toBeTrue();
    expect($f(ctx(['country' => 'ru', 'device' => 'desktop'])))->toBeFalse();
});

test('OR across groups', function (): void {
    $f = (new FilterCompiler())->compile([
        [['field' => 'country', 'op' => 'eq', 'value' => 'ru']],
        [['field' => 'country', 'op' => 'eq', 'value' => 'us']],
    ]);
    expect($f(ctx(['country' => 'ru'])))->toBeTrue();
    expect($f(ctx(['country' => 'us'])))->toBeTrue();
    expect($f(ctx(['country' => 'de'])))->toBeFalse();
});

test('in operator with array value', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'country', 'op' => 'in', 'value' => ['ru', 'ua', 'kz']]]]);
    expect($f(ctx(['country' => 'ua'])))->toBeTrue();
    expect($f(ctx(['country' => 'pl'])))->toBeFalse();
});

test('not_in operator', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'country', 'op' => 'not_in', 'value' => ['ru']]]]);
    expect($f(ctx(['country' => 'us'])))->toBeTrue();
    expect($f(ctx(['country' => 'ru'])))->toBeFalse();
});

test('regex operator on referer', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'referer', 'op' => 'regex', 'value' => 'google\.']]]);
    expect($f(ctx(['referer' => 'https://www.google.com/search'])))->toBeTrue();
    expect($f(ctx(['referer' => 'https://yandex.ru/'])))->toBeFalse();
});

test('between for time_hour', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'time_hour', 'op' => 'between', 'value' => [9, 18]]]]);
    expect($f(ctx(['ts' => strtotime('2026-05-01 12:00:00 UTC')])))->toBeTrue();
    expect($f(ctx(['ts' => strtotime('2026-05-01 03:00:00 UTC')])))->toBeFalse();
});

test('exists operator', function (): void {
    $f = (new FilterCompiler())->compile([[['field' => 'utm_source', 'op' => 'exists', 'value' => null]]]);
    expect($f(ctx(['utm' => ['source' => 'fb']])))->toBeTrue();
    expect($f(ctx()))->toBeFalse();
});

test('compiler caches identical filters', function (): void {
    $compiler = new FilterCompiler();
    $a = $compiler->compile([[['field' => 'country', 'op' => 'eq', 'value' => 'ru']]]);
    $b = $compiler->compile([[['field' => 'country', 'op' => 'eq', 'value' => 'ru']]]);
    expect($a)->toBe($b);
});
