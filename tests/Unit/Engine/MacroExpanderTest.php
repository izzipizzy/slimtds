<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\MacroExpander;

beforeEach(function (): void {
    $this->expander = new MacroExpander();
    $this->ctx = new Context('1.2.3.4', 'curl', 'demo', 1_714_000_000);
    $this->ctx->country = 'ru';
    $this->ctx->city = 'moscow';
    $this->ctx->clickId = '019dc137-724a-756c-923a-a392001e3d79';
    $this->ctx->utm['source'] = 'fb';
});

test('substitutes basic macros', function (): void {
    $u = 'https://example.com/?c={country}&city={city}&cid={click_id}&utm={utm_source}';
    $r = $this->expander->expand($u, $this->ctx);
    expect($r)->toBe('https://example.com/?c=ru&city=moscow&cid=019dc137-724a-756c-923a-a392001e3d79&utm=fb');
});

test('rand:1-100 yields integer in range', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{rand:1-100}', $this->ctx);
        $n = (int)$r;
        expect($n)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(100);
    }
});

test('randstr:5 yields 5-char alphanumeric', function (): void {
    $r = $this->expander->expand('{randstr:5}', $this->ctx);
    expect($r)->toMatch('/^[A-Za-z0-9]{5}$/');
});

test('unknown macros pass through unchanged', function (): void {
    $u = 'https://example.com/?x={notamacro}';
    expect($this->expander->expand($u, $this->ctx))->toBe('https://example.com/?x={notamacro}');
});

test('spin picks one of the listed values', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{spin:slots|aviator|registration}', $this->ctx);
        expect($r)->toBeIn(['slots', 'aviator', 'registration']);
    }
});

test('spin keeps path-like values raw (no url-encoding)', function (): void {
    $r = $this->expander->expand('{spin:slots/game/52358/aviator}', $this->ctx);
    expect($r)->toBe('slots/game/52358/aviator');
});

test('spin trims whitespace and drops empty segments', function (): void {
    for ($i = 0; $i < 50; $i++) {
        $r = $this->expander->expand('{spin: slots | aviator | }', $this->ctx);
        expect($r)->toBeIn(['slots', 'aviator']);
    }
});

test('spin with no valid values expands to empty string', function (): void {
    expect($this->expander->expand('r={spin:}', $this->ctx))->toBe('r=');
    expect($this->expander->expand('r={spin: | }', $this->ctx))->toBe('r=');
});

test('spin works embedded in a full url alongside other macros', function (): void {
    $u = 'https://betlnk.net/go/HASH/?tid={click_id}&r={spin:slots|aviator}';
    $r = $this->expander->expand($u, $this->ctx);
    expect($r)->toMatch('#^https://betlnk\.net/go/HASH/\?tid=019dc137-724a-756c-923a-a392001e3d79&r=(slots|aviator)$#');
});

test('spin covers every value across many iterations', function (): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $seen[$this->expander->expand('{spin:a|b|c}', $this->ctx)] = true;
    }
    expect(array_keys($seen))->toEqualCanonicalizing(['a', 'b', 'c']);
});

test('named query parameters encode URL components for arbitrary names', function (string $name): void {
    $this->ctx->query[$name] = 'a &%/é%20';
    expect($this->expander->expand('https://example.com/?p={param:' . $name . '}', $this->ctx))
        ->toBe('https://example.com/?p=a%20%26%25%2F%C3%A9%2520');
})->with(['brand', 'sub_id_30', 'sub_id_31', '_custom-2', 'Campaign', str_repeat('a', 64)]);

test('named query parameters expand missing and invalid recognized tokens to empty', function (string $name, mixed $value): void {
    $this->ctx->query[$name] = $value;
    expect($this->expander->expand('p={param:' . $name . '}', $this->ctx))->toBe('p=');
})->with([
    'empty name' => ['', 'ember'],
    '65 character name' => [str_repeat('a', 65), 'ember'],
    'digit first' => ['1brand', 'ember'],
    'hyphen first' => ['-brand', 'ember'],
    'space' => ['br and', 'ember'],
    'colon' => ['brand:other', 'ember'],
    'unicode name' => ['бренд', 'ember'],
    'newline name' => ["brand\n", 'ember'],
    'array' => ['brand', ['ember']],
    'null' => ['brand', null],
    'integer' => ['brand', 0],
    'boolean' => ['brand', true],
    '2049 bytes' => ['brand', str_repeat('x', 2049)],
    'multibyte oversize' => ['brand', str_repeat('é', 1025)],
    ...array_combine(
        array_map(fn (int $i) => 'control ' . $i, [...range(0, 31), 127]),
        array_map(fn (int $i) => ['brand', 'ember' . chr($i)], [...range(0, 31), 127]),
    ),
]);

test('named query parameters preserve zero empty maximum values builtins and unknown policy', function (): void {
    $this->ctx->query = ['country' => 'us', 'zero' => '0', 'empty' => '', 'max' => str_repeat('x', 2048)];
    expect($this->expander->expand('{country}/{param:country}/{param:zero}/{param:empty}/{param:missing}/{unknown}', $this->ctx))
        ->toBe('ru/us/0///{unknown}');
    expect($this->expander->expand('{param:max}', $this->ctx))->toBe(str_repeat('x', 2048));
    $this->ctx->query['max'] = str_repeat('é', 1024);
    expect($this->expander->expand('{param:max}', $this->ctx))->toBe(str_repeat('%C3%A9', 1024));
});
