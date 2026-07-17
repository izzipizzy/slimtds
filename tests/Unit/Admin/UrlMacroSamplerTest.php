<?php

declare(strict_types=1);

use App\Admin\Form\UrlMacroSampler;

test('leaves a plain url untouched', function (): void {
    expect(UrlMacroSampler::sample('https://ex.com/a?b=1'))->toBe('https://ex.com/a?b=1');
});

test('reduces a bare macro to "macro"', function (): void {
    expect(UrlMacroSampler::sample('https://ex.com/?c={click_id}'))->toBe('https://ex.com/?c=macro');
});

test('reduces a macro with an arg to "macro"', function (): void {
    expect(UrlMacroSampler::sample('https://ex.com/?n={rand:1-100}'))->toBe('https://ex.com/?n=macro');
});

test('reduces an embedded {spin} to its first value', function (): void {
    expect(UrlMacroSampler::sample('https://ex.com/?r={spin:slots|aviator|reg}'))->toBe('https://ex.com/?r=slots');
});

test('reduces a whole-url {spin} to its first url and validates', function (): void {
    $url = '{spin:https://a.com/x?p=1|https://b.com/y?p=1|https://c.com/z}';
    $sample = UrlMacroSampler::sample($url);
    expect($sample)->toBe('https://a.com/x?p=1');
    expect(filter_var($sample, FILTER_VALIDATE_URL))->not->toBeFalse();
});

test('trims whitespace around the first spin value', function (): void {
    expect(UrlMacroSampler::sample('{spin: https://a.com/x | https://b.com/y }'))->toBe('https://a.com/x');
});
