<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * An inclusive calendar-day range picked in an admin filter bar (?from=&to=).
 *
 * Days, not instants: `from` starts at 00:00 and `to` runs to the end of that
 * day, both in APP_TZ — PHP and the PDO session share that zone, so a bare
 * 'Y-m-d' means the same midnight on both sides and matches what the operator
 * reads in the tables. Repositories compare against exclusiveEnd() instead of
 * a 23:59:59 upper bound.
 */
final class DateRange
{
    /**
     * Rolling presets (?range=), in days back from today; null = from the first
     * record. They travel as the preset key, never as resolved dates, so a
     * bookmarked "last 30 days" is still the last 30 days next month.
     */
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365, 'all' => null];

    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        /** Key of PRESETS when the range came from one, null for hand-picked days or none. */
        public readonly ?string $preset = null,
    ) {}

    /**
     * Read ?from=&to= leniently: garbage is dropped, a reversed pair is
     * swapped, and a lone `to` gets a `from` $defaultDays earlier — on the
     * partitioned tables an upper bound alone would scan every partition back
     * to the first one. Pass null where an open start is fine.
     *
     * Hand-picked days win over ?range=. $earliestDay is asked only for the
     * 'all' preset, where a list with a default window needs a real first day
     * as its lower bound; without it 'all' simply leaves the range open.
     *
     * @param array<string,mixed> $query
     * @param (callable(): ?string)|null $earliestDay
     */
    public static function fromQuery(array $query, ?int $defaultDays, ?callable $earliestDay = null): self
    {
        $from = self::day($query['from'] ?? null);
        $to   = self::day($query['to'] ?? null);
        $preset = $query['range'] ?? null;
        if ($from === null && $to === null && is_string($preset) && array_key_exists($preset, self::PRESETS)) {
            $days = self::PRESETS[$preset];
            $start = $days !== null ? self::daysAgo($days) : ($earliestDay !== null ? self::day($earliestDay()) : null);
            return new self($start, null, $preset);
        }
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from === null && $to !== null && $defaultDays !== null) {
            $from = (new \DateTimeImmutable($to))->modify("-{$defaultDays} days")->format('Y-m-d');
        }
        return new self($from, $to);
    }

    /**
     * Filters as a view should see them: under a preset the resolved days are
     * blanked, so links and form fields carry ?range= and not today's dates.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function forView(array $filters): array
    {
        return $this->preset !== null ? array_merge($filters, ['from' => null, 'to' => null]) : $filters;
    }

    /** Strict 'Y-m-d' or null — 2026-02-31 is rejected, not rolled into March. */
    public static function day(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    /** First day NOT in the range: `created_at < exclusiveEnd($to)` covers all of $to. */
    public static function exclusiveEnd(string $to): string
    {
        return (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
    }

    public static function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify("-{$days} days")->format('Y-m-d');
    }

    public static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
