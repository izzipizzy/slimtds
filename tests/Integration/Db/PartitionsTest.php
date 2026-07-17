<?php

declare(strict_types=1);

use App\Shared\Db\Partitions;

beforeEach(function (): void {
    $this->pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    // чистим все партиции перед тестом
    foreach (['stats.clicks','stats.pixel_events','stats.visitors_fingerprints'] as $parent) {
        $rows = $this->pdo->query(
            "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='{$parent}'::regclass"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $r) { $this->pdo->exec("DROP TABLE IF EXISTS {$r}"); }
    }
});

test('ensureAhead creates partitions for current + N months', function (): void {
    $p = new Partitions($this->pdo);
    $now = new DateTimeImmutable('2026-05-01 00:00:00');
    $created = $p->ensureAhead(2, $now);

    expect($created)->toHaveCount(9); // 3 tables * (0..+2) months
    expect($created)->toContain('stats.clicks_2026_05');
    expect($created)->toContain('stats.clicks_2026_06');
    expect($created)->toContain('stats.clicks_2026_07');
});

test('ensureAhead is idempotent', function (): void {
    $p = new Partitions($this->pdo);
    $now = new DateTimeImmutable('2026-05-01 00:00:00');
    $p->ensureAhead(1, $now);
    $second = $p->ensureAhead(1, $now);
    expect($second)->toBeEmpty();
});

test('ensureAhead uses UTC bounds and does not overlap UTC-aligned partitions under a non-UTC session', function (): void {
    // Reproduce prod: the PDO factory pins the session to APP_TZ (Europe/Moscow).
    $this->pdo->exec("SET TIME ZONE 'Europe/Moscow'");
    // Migration-seeded partition with UTC-aligned bounds (as on prod).
    $this->pdo->exec(
        "CREATE TABLE stats.clicks_2026_05 PARTITION OF stats.clicks "
        . "FOR VALUES FROM ('2026-05-01 00:00:00+00') TO ('2026-06-01 00:00:00+00')"
    );

    $p = new Partitions($this->pdo);
    // Before the fix this threw 42P17: the June bound was parsed in Moscow tz
    // (2026-05-31 21:00+00) and overlapped May's UTC upper bound.
    $created = $p->ensureAhead(1, new DateTimeImmutable('2026-05-01 00:00:00'));

    expect($created)->toContain('stats.clicks_2026_06');

    // Read the stored bound under UTC so the rendered instant is unambiguous
    // (pg_get_expr renders timestamptz in the session tz).
    $this->pdo->exec("SET TIME ZONE 'UTC'");
    $bound = $this->pdo->query(
        "SELECT pg_get_expr(relpartbound, oid) FROM pg_class WHERE relname='clicks_2026_06'"
    )->fetchColumn();
    expect($bound)->toContain("'2026-06-01 00:00:00+00'");
    expect($bound)->toContain("'2026-07-01 00:00:00+00'");
});

test('dropOlderThanRetention removes old partitions', function (): void {
    $p = new Partitions($this->pdo);
    // Создадим партицию "за 2023", заведомо старше 365 дней от 2026-05-01
    $this->pdo->exec("CREATE TABLE stats.clicks_2023_01 PARTITION OF stats.clicks FOR VALUES FROM ('2023-01-01') TO ('2023-02-01')");
    $this->pdo->exec("CREATE TABLE stats.clicks_2026_05 PARTITION OF stats.clicks FOR VALUES FROM ('2026-05-01') TO ('2026-06-01')");

    $dropped = $p->dropOlderThanRetention(new DateTimeImmutable('2026-05-01 00:00:00'));
    expect($dropped)->toContain('stats.clicks_2023_01');
    expect($dropped)->not->toContain('stats.clicks_2026_05');
});

test('setRetention overrides default for drop window', function (): void {
    $p = new Partitions($this->pdo);
    $now = new DateTimeImmutable('2026-05-01 00:00:00');
    $this->pdo->exec("CREATE TABLE stats.clicks_2026_03 PARTITION OF stats.clicks FOR VALUES FROM ('2026-03-01') TO ('2026-04-01')");

    // Default is 365 days → won't drop
    $dropped = $p->dropOlderThanRetention($now);
    expect($dropped)->not->toContain('stats.clicks_2026_03');

    // Override to 30 days → should drop
    $p->setRetention('stats.clicks', 30);
    $dropped2 = $p->dropOlderThanRetention($now);
    expect($dropped2)->toContain('stats.clicks_2026_03');
});

test('partition bounds are UTC-aligned regardless of session timezone', function (): void {
    // Regression guard: partitions:rotate runs via bin/console under APP_TZ
    // (Europe/Moscow), while Pest and other callers use UTC. Bounds must be
    // emitted with an explicit UTC offset so the same calendar month maps to
    // the same instant in every session — otherwise adjacent months created
    // in different sessions overlap (e.g. UTC clicks_2026_05 ending at
    // 06-01 00:00Z vs a Moscow-created clicks_2026_06 starting at 05-31 21:00Z).
    $this->pdo->exec("SET TIME ZONE 'Europe/Moscow'");
    $p = new Partitions($this->pdo);
    $created = $p->ensureAhead(0, new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('Europe/Moscow')));
    expect($created)->toContain('stats.clicks_2026_09');

    // Read the stored bound under a UTC session: must be the UTC month boundary,
    // not the Moscow-shifted 2026-08-31 21:00.
    $this->pdo->exec("SET TIME ZONE 'UTC'");
    $bound = $this->pdo->query(
        "SELECT pg_get_expr(c.relpartbound, c.oid) FROM pg_inherits i
         JOIN pg_class c ON c.oid = i.inhrelid
         WHERE c.relname = 'clicks_2026_09'"
    )->fetchColumn();
    expect($bound)->toContain("FROM ('2026-09-01 00:00:00+00')");
    expect($bound)->toContain("TO ('2026-10-01 00:00:00+00')");
});

afterAll(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    (new \App\Shared\Db\Partitions($pdo))->ensureAhead(1);
});
