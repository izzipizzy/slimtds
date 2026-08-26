<?php

declare(strict_types=1);

use App\Shared\Asset\Manifest;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;

beforeEach(function (): void {
    $root = dirname(__DIR__, 3);
    $this->view = new View(
        $root . '/resources/views',
        new Manifest($root . '/public/assets/manifest.json'),
        new I18n((new TranslatorFactory($root . '/resources/translations'))->create()),
    );
});

function clicksPage(array $items, array $filters = [], array $visible = ['referer', 'entry_referer']): string
{
    return test()->view->render('admin/clicks/index', [
        'title'           => 'Clicks',
        'items'           => $items,
        'total'           => count($items),
        'pages'           => 1,
        'page'            => 1,
        'filters'         => $filters,
        'timeline'        => [],
        'campaigns'       => [],
        'columns_meta'    => \App\Admin\Clicks\ColumnPreferences::COLUMNS,
        'visible_columns' => $visible,
        'sort'            => ['field' => 'created_at', 'dir' => 'desc'],
        'visitor'         => null,
        'lang'            => 'en',
        'csrf_token'      => 'deadbeef',
        '__layout__'      => null,
    ]);
}

function clickRow(array $over = []): array
{
    return $over + [
        'id' => '00000000-0000-7000-8000-00000000c001', 'campaign_id' => null,
        'campaign_slug' => null, 'campaign_name' => null, 'flow_name' => null,
        'offer_name' => null, 'visitor_uuid' => '00000000-0000-7000-8000-00000000v001',
        'ip' => '1.1.1.1', 'country' => null, 'region' => null, 'city' => null,
        'asn' => null, 'isp' => null, 'device' => null, 'os' => null,
        'browser' => null, 'lang' => null, 'is_bot' => false, 'bot_name' => null,
        'is_uniq' => true, 'user_agent' => 'UA', 'referer' => 'https://lander.test/step-2',
        'entry_referer' => null, 'utm_source' => null, 'utm_medium' => null,
        'utm_campaign' => null, 'schema_id' => null, 'out_url' => null,
        'http_status' => 302, 'created_at' => '2026-08-26 10:00:00',
        'lander_host' => null, 'lander_button' => null, 'fp_js' => null,
        'has_conversion' => 0,
    ];
}

test('the entry-source column shows the source and offers a filter chip', function (): void {
    $html = clicksPage([clickRow(['entry_referer' => 'https://www.google.com/search?q=loan'])]);

    expect($html)->toContain('google.com/search?q=loan');
    // The engine badge doubles as a filter link, exactly like the referer column.
    expect($html)->toContain('entry_ref=google');
});

test('a click with no entry source renders a dash, not an empty cell', function (): void {
    // The bare character also appears in the filter options, so assert on the
    // cell's own markup: checking for '—' alone passes even on an empty cell.
    $html = clicksPage([clickRow()]);
    expect($html)->toContain('<span style="color:var(--color-faintest)">—</span>');
});

test('the entry-source filter renders and keeps the current selection', function (): void {
    $html = clicksPage([clickRow()], ['entry_ref' => 'bing']);

    expect($html)->toContain('name="entry_ref"');
    expect($html)->toContain('Entry source');
    expect($html)->toMatch('/value="bing"\s+selected/');
});

test('an applied entry-source filter survives clicking another filter link', function (): void {
    // Every filter link is built from one allow-list. Leaving entry_ref out of
    // it drops the filter the moment the operator touches anything else — and
    // silently, since the list simply widens.
    $html = clicksPage(
        [clickRow(['country' => 'de'])],
        ['entry_ref' => 'google'],
        ['country', 'referer', 'entry_referer'],
    );
    expect(substr_count($html, 'entry_ref=google'))->toBeGreaterThan(0);
});

test('the entry-source filter survives pagination', function (): void {
    // Pagination builds its own query array, separate from the filter links.
    // Leaving entry_ref out of it drops the filter on page 2 — silently, since
    // a lost filter only widens the list.
    $rows = array_map(fn ($i) => clickRow(['id' => sprintf('00000000-0000-7000-8000-0000000%05d', $i)]), range(1, 3));
    $html = test()->view->render('admin/clicks/index', [
        'title' => 'Clicks', 'items' => $rows, 'total' => 200, 'pages' => 4, 'page' => 1,
        'filters' => ['entry_ref' => 'google'], 'timeline' => [], 'campaigns' => [],
        'columns_meta' => \App\Admin\Clicks\ColumnPreferences::COLUMNS,
        'visible_columns' => ['referer', 'entry_referer'],
        'sort' => ['field' => 'created_at', 'dir' => 'desc'],
        'visitor' => null, 'lang' => 'en', 'csrf_token' => 'x', '__layout__' => null,
    ]);

    expect($html)->toMatch('/entry_ref=google[^"\']*page=2|page=2[^"\']*entry_ref=google/');
});
