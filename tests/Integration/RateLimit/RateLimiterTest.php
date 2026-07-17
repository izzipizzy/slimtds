<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Shared\RateLimit\RateLimiter;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.rate_limits');
    $this->db = new Connection($pdo);
    $this->limiter = new RateLimiter($this->db);
});

test('first hit is allowed', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:00');
    $d = $this->limiter->hit('ip:1.1.1.1', 3, 60, $now);
    expect($d->allowed)->toBeTrue();
    expect($d->remaining)->toBe(2);
});

test('count increments within same window', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:00');
    $this->limiter->hit('ip:2.2.2.2', 3, 60, $now);
    $this->limiter->hit('ip:2.2.2.2', 3, 60, $now);
    $d = $this->limiter->hit('ip:2.2.2.2', 3, 60, $now);
    expect($d->allowed)->toBeTrue();
    expect($d->remaining)->toBe(0);
});

test('over-limit returns not allowed', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:00');
    for ($i = 0; $i < 3; $i++) {
        $this->limiter->hit('ip:3.3.3.3', 3, 60, $now);
    }
    $d = $this->limiter->hit('ip:3.3.3.3', 3, 60, $now);
    expect($d->allowed)->toBeFalse();
    expect($d->remaining)->toBe(0);
});

test('new window resets the counter', function (): void {
    $w1 = new DateTimeImmutable('2026-05-01 12:00:00');
    $w2 = new DateTimeImmutable('2026-05-01 12:01:00');  // next 60s window
    for ($i = 0; $i < 5; $i++) {
        $this->limiter->hit('ip:4.4.4.4', 3, 60, $w1);
    }
    $d = $this->limiter->hit('ip:4.4.4.4', 3, 60, $w2);
    expect($d->allowed)->toBeTrue();
    expect($d->remaining)->toBe(2);
});

test('current returns count without increment', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:00');
    $this->limiter->hit('ip:5.5.5.5', 5, 60, $now);
    $this->limiter->hit('ip:5.5.5.5', 5, 60, $now);
    expect($this->limiter->current('ip:5.5.5.5', 60, $now))->toBe(2);
    expect($this->limiter->current('ip:5.5.5.5', 60, $now))->toBe(2);  // still 2
});

test('reset clears all buckets for key', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:00');
    $this->limiter->hit('ip:6.6.6.6', 5, 60, $now);
    $this->limiter->hit('ip:6.6.6.6', 5, 60, $now);
    $this->limiter->reset('ip:6.6.6.6');
    expect($this->limiter->current('ip:6.6.6.6', 60, $now))->toBe(0);
});

test('resetAt is window_end unix timestamp', function (): void {
    $now = new DateTimeImmutable('2026-05-01 12:00:30');
    $d = $this->limiter->hit('ip:7.7.7.7', 3, 60, $now);
    $expected = strtotime('2026-05-01 12:01:00 UTC');
    expect($d->resetAt)->toBe($expected);
});
