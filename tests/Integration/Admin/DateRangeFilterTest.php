<?php

declare(strict_types=1);

use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Pixel\PixelEventRepository;
use App\Shared\Db\Connection;
use App\Shared\Time\TimelineWindow;

const RANGE_CAMPAIGN = '00000000-0000-7000-8000-0000000000d1';

/**
 * Fixtures sit on the three days AFTER today: the test database only has the
 * current and coming months partitioned, so "a week ago" would have nowhere to
 * land in the first days of a month. The filter is plain date arithmetic, so
 * which side of now() the days fall on changes nothing.
 *
 * Day strings come from the database, not PHP, so the test cannot disagree
 * with the session time zone about where midnight is.
 */
beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM stats.clicks');
    $this->pdo->exec('DELETE FROM stats.pixel_events');
    $this->pdo->exec('DELETE FROM stats.rrweb_sessions');
    $this->db = new Connection($this->pdo);

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:id, 'rangeflt', 'date range', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => RANGE_CAMPAIGN],
    );

    $day = fn (int $n): string => (string)$this->db->fetchScalar("SELECT (current_date + {$n})::text");
    [$this->d1, $this->d2, $this->d3] = [$day(1), $day(2), $day(3)];

    // d1 noon · d2 first instant · d2 last second · d3 first instant
    $this->stamps = [
        'd1_noon'  => "{$this->d1} 12:00:00",
        'd2_start' => "{$this->d2} 00:00:00",
        'd2_end'   => "{$this->d2} 23:59:59",
        'd3_start' => "{$this->d3} 00:00:00",
    ];
});

/** @param list<array<string,mixed>> $rows */
function rangeLabels(array $rows, string $column): array
{
    $labels = array_map(static fn (array $r): string => (string)$r[$column], $rows);
    sort($labels);
    return $labels;
}

test('clicks: from/to are inclusive days — the last second of `to` is in, the next midnight is out', function (): void {
    foreach ($this->stamps as $label => $ts) {
        $this->db->execute(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, utm_content, is_bot, created_at)
             VALUES (:c, uuidv7(), '1.1.1.1', :label, false, :ts::timestamptz)",
            ['c' => RANGE_CAMPAIGN, 'label' => $label, 'ts' => $ts],
        );
    }
    $repo = new ClickRepository($this->db);
    $base = ['campaign_id' => RANGE_CAMPAIGN, 'is_trash' => 'all'];

    expect($repo->page(1, 50, $base + ['from' => $this->d2, 'to' => $this->d2]))->toHaveCount(2)
        ->and($repo->count($base + ['from' => $this->d2, 'to' => $this->d2]))->toBe(2)
        ->and($repo->count($base + ['from' => $this->d1, 'to' => $this->d3]))->toBe(4)
        ->and($repo->count($base + ['from' => $this->d3]))->toBe(1)
        ->and($repo->count($base + ['to' => $this->d1]))->toBe(1);
});

test('clicks: a malformed day is ignored, not passed to SQL', function (): void {
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, is_bot, created_at)
         VALUES (:c, uuidv7(), '1.1.1.1', false, now())",
        ['c' => RANGE_CAMPAIGN],
    );
    $repo = new ClickRepository($this->db);
    expect($repo->count(['campaign_id' => RANGE_CAMPAIGN, 'is_trash' => 'all', 'from' => 'yesterday', 'to' => '2026-02-31']))->toBe(1);
});

test('pixel: from/to are inclusive days, in page() and count() alike', function (): void {
    foreach ($this->stamps as $label => $ts) {
        $this->db->execute(
            "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, event_name, created_at)
             VALUES (:c, uuidv7(), :label, :ts::timestamptz)",
            ['c' => RANGE_CAMPAIGN, 'label' => $label, 'ts' => $ts],
        );
    }
    $repo = new PixelEventRepository($this->db);
    $f = ['campaign_id' => RANGE_CAMPAIGN, 'from' => $this->d2, 'to' => $this->d2];

    expect(rangeLabels($repo->page(1, 50, $f), 'event_name'))->toBe(['d2_end', 'd2_start'])
        ->and($repo->count($f))->toBe(2)
        ->and($repo->count(['campaign_id' => RANGE_CAMPAIGN, 'from' => $this->d1, 'to' => $this->d3]))->toBe(4);
});

test('pixel: country filter matches the stored lowercase code whatever case is typed', function (): void {
    foreach (['ar', 'ar', 'cl'] as $cc) {
        $this->db->execute(
            "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, country, created_at)
             VALUES (:c, uuidv7(), :cc, now())",
            ['c' => RANGE_CAMPAIGN, 'cc' => $cc],
        );
    }
    $repo = new PixelEventRepository($this->db);
    $f = ['campaign_id' => RANGE_CAMPAIGN, 'country' => 'AR'];

    expect($repo->count($f))->toBe(2)
        ->and(rangeLabels($repo->page(1, 50, $f), 'country'))->toBe(['ar', 'ar'])
        ->and($repo->count(['campaign_id' => RANGE_CAMPAIGN]))->toBe(3)
        // The chart is filtered the same way as the list under it.
        ->and(array_sum(array_column($repo->timeline($f, TimelineWindow::fromFilters($f, 7)), 'events')))->toBe(2);
});

test('sessions: from/to filter on the day the session started; earliestDay is the oldest one', function (): void {
    $repo = new RrwebSessionRepository($this->db);
    expect($repo->earliestDay())->toBeNull();

    foreach (array_values($this->stamps) as $i => $ts) {
        $this->db->execute(
            "INSERT INTO stats.rrweb_sessions (session_id, campaign_id, started_at, last_at, chunk_count, event_count, bytes)
             VALUES (:s, :c, :ts::timestamptz, :ts::timestamptz, 1, 1, 1)",
            ['s' => sprintf('77777777-7777-7777-8777-77777777777%d', $i), 'c' => RANGE_CAMPAIGN, 'ts' => $ts],
        );
    }

    expect($repo->count(['from' => $this->d2, 'to' => $this->d2]))->toBe(2)
        ->and($repo->page(['from' => $this->d2, 'to' => $this->d2], 1, 50))->toHaveCount(2)
        ->and($repo->count(['to' => $this->d1]))->toBe(1)
        ->and($repo->count(['from' => $this->d3]))->toBe(1)
        ->and($repo->count([]))->toBe(4)
        ->and($repo->earliestDay())->toBe($this->d1);
});

test('chart follows the picked period: one bucket per hour, totals equal to the list', function (): void {
    foreach ($this->stamps as $label => $ts) {
        $this->db->execute(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, utm_content, is_bot, created_at)
             VALUES (:c, uuidv7(), '1.1.1.1', :label, false, :ts::timestamptz)",
            ['c' => RANGE_CAMPAIGN, 'label' => $label, 'ts' => $ts],
        );
    }
    // A past day too: the window must reach back to `from`, not just 48 hours.
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, is_bot, created_at)
         VALUES (:c, uuidv7(), '1.1.1.1', false, date_trunc('day', now()) + interval '1 minute')",
        ['c' => RANGE_CAMPAIGN],
    );
    $repo = new ClickRepository($this->db);
    $today = (string)$this->db->fetchScalar('SELECT current_date::text');
    $f = ['campaign_id' => RANGE_CAMPAIGN, 'is_trash' => 'all', 'from' => $today, 'to' => $today];

    $window = TimelineWindow::fromFilters($f, 7);
    $points = $repo->timeline($f, $window);

    expect($window->step)->toBe('hour')
        // Today is cut at the current hour — no empty buckets drawn into the future.
        ->and(count($points))->toBeLessThanOrEqual(24)
        ->and($points[0]['hour'])->toBe("{$today}T00:00:00")
        ->and(array_sum(array_column($points, 'clicks')))->toBe($repo->count($f))
        ->and($points[0]['clicks'])->toBe(1);
});

test('chart switches to daily buckets for a wide period and still sums to the list', function (): void {
    foreach (['ar', 'cl'] as $cc) {
        $this->db->execute(
            "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, country, created_at)
             VALUES (:c, uuidv7(), :cc, now())",
            ['c' => RANGE_CAMPAIGN, 'cc' => $cc],
        );
    }
    $repo = new PixelEventRepository($this->db);
    $from = (string)$this->db->fetchScalar('SELECT (current_date - 29)::text');
    $today = (string)$this->db->fetchScalar('SELECT current_date::text');
    $f = ['campaign_id' => RANGE_CAMPAIGN, 'from' => $from];

    $window = TimelineWindow::fromFilters($f, 7);
    $points = $repo->timeline($f, $window);

    expect($window->step)->toBe('day')
        ->and($points)->toHaveCount(30)
        ->and($points[0]['hour'])->toBe("{$from}T00:00:00")
        ->and($points[29]['hour'])->toBe("{$today}T00:00:00")
        ->and($points[29]['events'])->toBe(2)
        ->and(array_sum(array_column($points, 'events')))->toBe($repo->count($f));
});

test('with no period picked the chart covers the default list window, hourly', function (): void {
    $window = TimelineWindow::fromFilters([], 7);
    $points = (new ClickRepository($this->db))->timeline(['is_trash' => 'all'], $window);

    // 7 days back to the hour, through the current hour: 169 buckets.
    expect($window->step)->toBe('hour')->and($points)->toHaveCount(169);
});

test('pixel chart hides what the list hides — bots and other visitors included', function (): void {
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, is_bot, fp_js, created_at)
         VALUES (:c, uuidv7(), false, 'fp-a', now()), (:c, uuidv7(), true, 'fp-b', now()), (:c, uuidv7(), true, 'fp-b', now())",
        ['c' => RANGE_CAMPAIGN],
    );
    $repo = new PixelEventRepository($this->db);
    $sum = static fn (array $f): int => (int)array_sum(array_column($repo->timeline($f, TimelineWindow::fromFilters($f, 7)), 'events'));

    foreach ([['bot_view' => 'hide'], ['bot_view' => 'only'], ['bot_view' => 'all'], ['bot_view' => 'all', 'fp_js' => 'fp-a']] as $extra) {
        $f = ['campaign_id' => RANGE_CAMPAIGN] + $extra;
        expect($sum($f))->toBe($repo->count($f));
    }
    expect($sum(['campaign_id' => RANGE_CAMPAIGN, 'bot_view' => 'hide']))->toBe(1);
});
