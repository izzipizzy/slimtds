<?php

declare(strict_types=1);

use App\Shared\Db\Partitions;

beforeEach(function (): void {
    $this->pdo = pdo();
    $rows = $this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) { $this->pdo->exec("DROP TABLE IF EXISTS {$r}"); }
});

test('ensureDailyAhead creates today + N daily partitions', function (): void {
    $p = new Partitions($this->pdo);
    $created = $p->ensureDailyAhead(3, new DateTimeImmutable('2026-06-23 00:00:00'));

    expect($created)->toHaveCount(4); // today + 3
    expect($created)->toContain('stats.rrweb_chunks_2026_06_23');
    expect($created)->toContain('stats.rrweb_chunks_2026_06_26');
});

test('ensureDailyAhead is idempotent', function (): void {
    $p = new Partitions($this->pdo);
    $now = new DateTimeImmutable('2026-06-23 00:00:00');
    $p->ensureDailyAhead(1, $now);
    expect($p->ensureDailyAhead(1, $now))->toBeEmpty();
});

test('dropDailyOlderThanRetention drops days past the window', function (): void {
    $p = new Partitions($this->pdo);
    $this->pdo->exec("CREATE TABLE stats.rrweb_chunks_2026_06_10 PARTITION OF stats.rrweb_chunks FOR VALUES FROM ('2026-06-10') TO ('2026-06-11')");
    $this->pdo->exec("CREATE TABLE stats.rrweb_chunks_2026_06_23 PARTITION OF stats.rrweb_chunks FOR VALUES FROM ('2026-06-23') TO ('2026-06-24')");

    $p->setRetention('stats.rrweb_chunks', 7);
    $dropped = $p->dropDailyOlderThanRetention(new DateTimeImmutable('2026-06-23 00:00:00'));

    expect($dropped)->toContain('stats.rrweb_chunks_2026_06_10'); // 13 days old > 7
    expect($dropped)->not->toContain('stats.rrweb_chunks_2026_06_23');
});
