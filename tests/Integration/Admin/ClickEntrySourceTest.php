<?php

declare(strict_types=1);

use App\Admin\Repository\ClickRepository;
use App\Shared\Db\Connection;

const ENTRY_CAMPAIGN = '00000000-0000-7000-8000-0000000000e1';
const V_SEARCH       = '00000000-0000-7000-8000-0000000000e2';
const V_DIRECT       = '00000000-0000-7000-8000-0000000000e3';

/**
 * ClickRepository hides flow-less clicks unless routing is explicit, and the
 * fixtures below are deliberately flow-less. ClickController passes 'all', so
 * these tests mirror the real request path rather than the bare default.
 */
function entryFilters(array $extra = []): array
{
    return ['campaign_id' => ENTRY_CAMPAIGN, 'is_trash' => 'all'] + $extra;
}

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.pixel_events');
    $this->db = new Connection($pdo);
    $this->repo = new ClickRepository($this->db);

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:id, 'entrysrc', 'entry source', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => ENTRY_CAMPAIGN],
    );

    // Visitor A entered from Google, then navigated inside the lander. The later
    // in-lander pageview carries the lander as `referer` — picking the most
    // recent event would therefore hide the real source.
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, entry_referer, created_at)
         VALUES
            (:c, :v, 'https://www.google.com/search?q=loan', 'https://www.google.com/search?q=loan', now() - interval '10 minutes'),
            (:c, :v, 'https://lander.test/step-1',           'https://www.google.com/search?q=loan', now() - interval '4 minutes')",
        ['c' => ENTRY_CAMPAIGN, 'v' => V_SEARCH],
    );
    // Visitor B has a pixel event but no external entry source at all.
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, entry_referer, created_at)
         VALUES (:c, :v, 'https://lander.test/', NULL, now() - interval '6 minutes')",
        ['c' => ENTRY_CAMPAIGN, 'v' => V_DIRECT],
    );

    // Both clicks carry the LANDER as their own Referer — the go.php problem.
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            (:c, :vs, '1.1.1.1', 'https://lander.test/step-2', false, now()),
            (:c, :vd, '1.1.1.2', 'https://lander.test/step-2', false, now())",
        ['c' => ENTRY_CAMPAIGN, 'vs' => V_SEARCH, 'vd' => V_DIRECT],
    );
});

test('entry source comes from the earliest external pixel event, not the latest one', function (): void {
    $rows = $this->repo->page(1, 50, entryFilters());
    $byVisitor = [];
    foreach ($rows as $r) {
        $byVisitor[(string)$r['visitor_uuid']] = $r;
    }

    expect($byVisitor[V_SEARCH]['entry_referer'])->toBe('https://www.google.com/search?q=loan')
        // The raw Referer is untouched — the column is additive, not a replacement.
        ->and($byVisitor[V_SEARCH]['referer'])->toBe('https://lander.test/step-2')
        ->and($byVisitor[V_DIRECT]['entry_referer'])->toBeNull();
});

test('default listing applies no entry-source predicate', function (): void {
    expect($this->repo->count(entryFilters()))->toBe(2);
});

test('opt-in entry-source filter narrows to search entries only', function (): void {
    $filters = entryFilters(['entry_ref' => 'any']);

    $rows = $this->repo->page(1, 50, $filters);
    expect($this->repo->count($filters))->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and((string)$rows[0]['visitor_uuid'])->toBe(V_SEARCH);
});

test('entry-source filter matches a single named engine', function (): void {
    expect($this->repo->count(entryFilters(['entry_ref' => 'google'])))->toBe(1)
        ->and($this->repo->count(entryFilters(['entry_ref' => 'bing'])))->toBe(0);
});

test('a click whose visitor has no pixel event keeps a null entry source', function (): void {
    $orphan = '00000000-0000-7000-8000-0000000000e9';
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES (:c, :v, '1.1.1.3', 'https://lander.test/x', false, now())",
        ['c' => ENTRY_CAMPAIGN, 'v' => $orphan],
    );

    $rows = $this->repo->page(1, 50, entryFilters());
    $row = array_values(array_filter($rows, fn ($r) => (string)$r['visitor_uuid'] === $orphan));

    expect($row)->toHaveCount(1)
        ->and($row[0]['entry_referer'])->toBeNull();
});
