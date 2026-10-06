<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * The window and bucket size of a list page's chart, derived from the same
 * filters as the list so the two always describe the same period: the picked
 * day range, else a legacy ISO `since`, else the rolling default window.
 *
 * Buckets are hours up to HOURLY_MAX_DAYS and days beyond — a month of hourly
 * points is noise, and a day is what a wide range is read by anyway. Bounds are
 * SQL fragments, not PHP timestamps, so "now" and midnight are the database
 * session's (APP_TZ) like everywhere else.
 */
final class TimelineWindow
{
    public const HOURLY_MAX_DAYS = 8;

    /** @param array<string,string> $params */
    private function __construct(
        /** 'hour' | 'day' */
        public readonly string $step,
        /** First bucket, aligned to $step. */
        public readonly string $startSql,
        /** Exclusive end, aligned to $step; never past the current bucket. */
        public readonly string $endSql,
        public readonly array $params,
    ) {}

    /** @param array<string,mixed> $filters reads 'from' / 'to' ('Y-m-d') and 'since' */
    public static function fromFilters(array $filters, int $defaultDays): self
    {
        $from  = DateRange::day($filters['from'] ?? null);
        $to    = DateRange::day($filters['to'] ?? null);
        $since = is_string($filters['since'] ?? null) && strtotime($filters['since']) !== false
            ? $filters['since']
            : null;

        $firstDay = $from ?? ($since !== null ? date('Y-m-d', (int)strtotime($since)) : DateRange::daysAgo($defaultDays));
        $lastDay  = $to !== null ? min($to, DateRange::today()) : DateRange::today();
        $days = (int)(new \DateTimeImmutable($firstDay))->diff(new \DateTimeImmutable($lastDay))->format('%r%a') + 1;
        $step = $days > self::HOURLY_MAX_DAYS ? 'day' : 'hour';

        $params = [];
        if ($from !== null) {
            $start = ':tl_from::timestamptz';
            $params['tl_from'] = $from;
        } elseif ($since !== null) {
            $start = ':tl_since::timestamptz';
            $params['tl_since'] = $since;
        } else {
            $start = "now() - interval '{$defaultDays} days'";
        }

        $current = "date_trunc('{$step}', now()) + interval '1 {$step}'";
        if ($to !== null) {
            $end = "least(:tl_to_excl::timestamptz, {$current})";
            $params['tl_to_excl'] = DateRange::exclusiveEnd($to);
        } else {
            $end = $current;
        }

        return new self($step, "date_trunc('{$step}', {$start})", $end, $params);
    }
}
