<?php

declare(strict_types=1);

use App\Shared\Time\DateRange;

test('reads a from/to pair as given', function (): void {
    $r = DateRange::fromQuery(['from' => '2026-09-01', 'to' => '2026-09-10'], 7);
    expect($r->from)->toBe('2026-09-01')->and($r->to)->toBe('2026-09-10');
});

test('no params means no range, so the repository default window applies', function (): void {
    $r = DateRange::fromQuery([], 7);
    expect($r->from)->toBeNull()->and($r->to)->toBeNull();
});

test('a reversed pair is swapped instead of matching nothing', function (): void {
    $r = DateRange::fromQuery(['from' => '2026-09-10', 'to' => '2026-09-01'], 7);
    expect($r->from)->toBe('2026-09-01')->and($r->to)->toBe('2026-09-10');
});

test('a lone `to` gets a lower bound so partitioned tables are not scanned whole', function (): void {
    $r = DateRange::fromQuery(['to' => '2026-09-10'], 7);
    expect($r->from)->toBe('2026-09-03')->and($r->to)->toBe('2026-09-10');
});

test('a lone `to` stays open-ended when the caller has no default window', function (): void {
    $r = DateRange::fromQuery(['to' => '2026-09-10'], null);
    expect($r->from)->toBeNull()->and($r->to)->toBe('2026-09-10');
});

test('a lone `from` runs to now', function (): void {
    $r = DateRange::fromQuery(['from' => '2026-09-01'], 7);
    expect($r->from)->toBe('2026-09-01')->and($r->to)->toBeNull();
});

test('anything that is not a real calendar day is dropped', function (mixed $bad): void {
    expect(DateRange::day($bad))->toBeNull();
})->with([
    'empty'            => '',
    'datetime'         => '2026-09-01T10:00',
    'impossible day'   => '2026-02-31',
    'sql'              => "2026-09-01'; DROP TABLE stats.clicks; --",
    'array'            => [['2026-09-01']],
    'null'             => null,
    'wrong separators' => '01.09.2026',
]);

test('exclusiveEnd is the next day, across month and year ends', function (): void {
    expect(DateRange::exclusiveEnd('2026-09-10'))->toBe('2026-09-11')
        ->and(DateRange::exclusiveEnd('2026-09-30'))->toBe('2026-10-01')
        ->and(DateRange::exclusiveEnd('2026-12-31'))->toBe('2027-01-01');
});

test('a preset resolves to a rolling start and remembers its key', function (): void {
    $r = DateRange::fromQuery(['range' => '30d'], 7);
    expect($r->preset)->toBe('30d')->and($r->from)->toBe(DateRange::daysAgo(30))->and($r->to)->toBeNull();
});

test('the all-time preset starts at the first record, asked for only then', function (): void {
    $asked = 0;
    $earliest = function () use (&$asked): string { $asked++; return '2026-04-26'; };

    expect(DateRange::fromQuery(['range' => 'all'], 7, $earliest)->from)->toBe('2026-04-26')
        ->and(DateRange::fromQuery(['range' => '7d'], 7, $earliest)->from)->toBe(DateRange::daysAgo(7))
        ->and($asked)->toBe(1)
        // No first-record source (sessions) or an empty table: the range stays open.
        ->and(DateRange::fromQuery(['range' => 'all'], null)->from)->toBeNull()
        ->and(DateRange::fromQuery(['range' => 'all'], 7, fn () => null)->from)->toBeNull();
});

test('hand-picked days win over a preset, and an unknown preset is ignored', function (): void {
    $picked = DateRange::fromQuery(['range' => '365d', 'from' => '2026-09-01'], 7);
    $bogus  = DateRange::fromQuery(['range' => '1000y'], 7);

    expect($picked->preset)->toBeNull()->and($picked->from)->toBe('2026-09-01')
        ->and($bogus->preset)->toBeNull()->and($bogus->from)->toBeNull();
});

test('under a preset the view gets no resolved days, so links carry ?range= instead', function (): void {
    $filters = ['country' => 'ar', 'from' => '2026-08-20', 'to' => null, 'range' => '30d'];

    expect(DateRange::fromQuery(['range' => '30d'], 7)->forView($filters))
        ->toBe(['country' => 'ar', 'from' => null, 'to' => null, 'range' => '30d'])
        ->and(DateRange::fromQuery(['from' => '2026-08-20'], 7)->forView($filters))->toBe($filters);
});
