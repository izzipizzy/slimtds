<?php

declare(strict_types=1);

namespace App\Shared\RateLimit;

use App\Shared\Db\Connection;
use DateTimeImmutable;

/**
 * Fixed-window rate limiter backed by core.rate_limits.
 *
 * Not a true sliding window — uses discrete buckets aligned to windowSeconds.
 * Adequate for login-rate-limiting (low RPS, fail2ban-style).
 */
final class RateLimiter
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Record one hit and report whether the caller is allowed.
     *
     * @param string $key              Namespaced identity, e.g. "login:alice", "ip:1.2.3.4"
     * @param int    $maxPerWindow     Maximum hits allowed per window
     * @param int    $windowSeconds    Window size in seconds
     */
    public function hit(string $key, int $maxPerWindow, int $windowSeconds, ?DateTimeImmutable $now = null): Decision
    {
        $now = $now ?? new DateTimeImmutable('now');
        $epoch = $now->getTimestamp();
        $windowStart = intdiv($epoch, $windowSeconds) * $windowSeconds;
        $windowEnd = $windowStart + $windowSeconds;
        $windowEndStr = (new DateTimeImmutable('@' . $windowEnd))->format('Y-m-d H:i:sP');

        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.rate_limits (key, window_end, count)
                VALUES (:key, :window_end, 1)
                ON CONFLICT (key, window_end) DO UPDATE
                SET count = core.rate_limits.count + 1
                RETURNING count
            SQL,
            ['key' => $key, 'window_end' => $windowEndStr],
        );
        $count = (int)($row['count'] ?? 0);

        return new Decision(
            allowed: $count <= $maxPerWindow,
            remaining: max(0, $maxPerWindow - $count),
            resetAt: $windowEnd,
        );
    }

    /**
     * Peek current count WITHOUT incrementing. Useful for pre-checks.
     */
    public function current(string $key, int $windowSeconds, ?DateTimeImmutable $now = null): int
    {
        $now = $now ?? new DateTimeImmutable('now');
        $epoch = $now->getTimestamp();
        $windowStart = intdiv($epoch, $windowSeconds) * $windowSeconds;
        $windowEnd = $windowStart + $windowSeconds;
        $windowEndStr = (new DateTimeImmutable('@' . $windowEnd))->format('Y-m-d H:i:sP');

        $count = $this->db->fetchScalar(
            'SELECT count FROM core.rate_limits WHERE key = :key AND window_end = :window_end',
            ['key' => $key, 'window_end' => $windowEndStr],
        );
        return (int)($count ?? 0);
    }

    /**
     * Reset a key (used e.g. on successful login to clear previous failures).
     * Removes ALL buckets for the given key.
     */
    public function reset(string $key): void
    {
        $this->db->execute('DELETE FROM core.rate_limits WHERE key = :key', ['key' => $key]);
    }
}
