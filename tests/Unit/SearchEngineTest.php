<?php

declare(strict_types=1);

use App\Shared\Referer\SearchEngine;

test('classify returns null for empty / null / unknown referers', function (): void {
    expect(SearchEngine::classify(null))->toBeNull();
    expect(SearchEngine::classify(''))->toBeNull();
    expect(SearchEngine::classify('https://example.com/foo'))->toBeNull();
    expect(SearchEngine::classify('https://t.me/some_chat'))->toBeNull();
});

test('classify recognises common search engines across TLDs and case', function (): void {
    expect(SearchEngine::classify('https://www.google.com/search?q=foo'))->toBe('google');
    expect(SearchEngine::classify('https://Google.co.uk/'))->toBe('google');
    expect(SearchEngine::classify('https://google.ru/'))->toBe('google');
    expect(SearchEngine::classify('https://www.bing.com/'))->toBe('bing');
    expect(SearchEngine::classify('https://www.baidu.com/'))->toBe('baidu');
    expect(SearchEngine::classify('https://yandex.ru/search/?text=x'))->toBe('yandex');
    expect(SearchEngine::classify('https://ya.ru/'))->toBe('yandex');
    expect(SearchEngine::classify('https://duckduckgo.com/?q=x'))->toBe('duckduckgo');
});

test('sqlFilter empty value is a no-op', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('', 'c.referer');
    expect($frag)->toBe('');
    expect($params)->toBe([]);
});

test('sqlFilter any returns OR of every pattern as ILIKE bind', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('any', 'c.referer', 'se');
    expect($frag)->toContain('c.referer ILIKE :se_0');
    expect($frag)->toStartWith('(');
    expect($frag)->toEndWith(')');
    expect(count($params))->toBeGreaterThan(5);
    foreach ($params as $v) {
        expect($v)->toStartWith('%');
        expect($v)->toEndWith('%');
    }
});

test('sqlFilter for a specific engine binds only that engine\'s patterns', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('bing', 'pe.referer', 'x');
    expect($frag)->toBe('(pe.referer ILIKE :x_0)');
    expect($params)->toBe(['x_0' => '%bing.com%']);
});

test('sqlFilter none excludes all engines but keeps non-empty referer rows', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('none', 'c.referer');
    expect($frag)->toContain('c.referer IS NOT NULL');
    expect($frag)->toContain("c.referer <> ''");
    expect($frag)->toContain('NOT (');
    expect($params)->not->toBeEmpty();
});

test('sqlFilter with unknown value is a safe no-op', function (): void {
    [$frag, $params] = SearchEngine::sqlFilter('not-a-real-engine', 'c.referer');
    expect($frag)->toBe('');
    expect($params)->toBe([]);
});

test('sqlFilterEngines ORs patterns of the given engines only', function (): void {
    [$frag, $params] = SearchEngine::sqlFilterEngines(['chatgpt', 'google'], 'pe.referer', 'se');
    expect($frag)->toStartWith('(')->toEndWith(')');
    expect($frag)->toContain('pe.referer ILIKE :se_0');
    // chatgpt has 2 patterns + google has 3 = 5 binds
    expect($params)->toHaveCount(5);
    expect($params['se_0'])->toBe('%chatgpt.com%');
});

test('sqlFilterEngines is a no-op for empty / unknown engine lists', function (): void {
    expect(SearchEngine::sqlFilterEngines([], 'pe.referer'))->toBe(['', []]);
    expect(SearchEngine::sqlFilterEngines(['not-real'], 'pe.referer'))->toBe(['', []]);
});
