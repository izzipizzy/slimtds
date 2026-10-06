<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;
use App\Shared\Time\DateRange;

final class ConversionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{campaign_id?:?string, status?:?string, since?:?string, from?:?string, to?:?string} $filters
     * @return list<array<string,mixed>>
     */
    public function page(int $page, int $perPage, array $filters = []): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        [$where, $params] = $this->buildWhere($filters);
        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        return $this->db->fetchAll(
            "SELECT cv.*,
                    c.slug AS campaign_slug, c.name AS campaign_name,
                    o.name AS offer_name
             FROM core.conversions cv
             LEFT JOIN core.campaigns c ON c.id = cv.campaign_id
             LEFT JOIN core.offers o    ON o.id = cv.offer_id
             {$where}
             ORDER BY cv.created_at DESC
             LIMIT :limit OFFSET :offset",
            $params,
        );
    }

    /** Day (APP_TZ) of the oldest conversion on record — the lower bound of the "all time" preset. */
    public function earliestDay(): ?string
    {
        $day = $this->db->fetchScalar('SELECT min(created_at)::date::text FROM core.conversions');
        return is_string($day) && $day !== '' ? $day : null;
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int)$this->db->fetchScalar(
            "SELECT count(*) FROM core.conversions cv {$where}",
            $params,
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{approved:array{count:int, payout:string}, pending:array{count:int, payout:string}, hold:array{count:int, payout:string}, rejected:array{count:int, payout:string}}
     */
    public function statusBreakdown(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $rows = $this->db->fetchAll(
            "SELECT status, count(*) AS n, COALESCE(sum(payout), 0)::text AS total
             FROM core.conversions cv {$where} GROUP BY status",
            $params,
        );
        $out = [
            'approved' => ['count' => 0, 'payout' => '0'],
            'pending'  => ['count' => 0, 'payout' => '0'],
            'hold'     => ['count' => 0, 'payout' => '0'],
            'rejected' => ['count' => 0, 'payout' => '0'],
        ];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = ['count' => (int)$r['n'], 'payout' => (string)$r['total']];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array{label:string, conversions:int, approved:int, payout:string}>
     */
    public function revenueBy(string $by, array $filters = [], int $limit = 50): array
    {
        $expr = ['offer' => 'o.name', 'campaign' => 'c.slug::text'][$by]
            ?? throw new \InvalidArgumentException("unknown grouping: {$by}");
        [$where, $params] = $this->buildWhere($filters);
        $params['lim'] = $limit;
        $rows = $this->db->fetchAll(
            "SELECT COALESCE({$expr}, '(none)')                                          AS label,
                    count(*)                                                            AS conversions,
                    count(*) FILTER (WHERE cv.status = 'approved')                      AS approved,
                    COALESCE(sum(cv.payout) FILTER (WHERE cv.status = 'approved'), 0)::text AS payout
             FROM core.conversions cv
             LEFT JOIN core.campaigns c ON c.id = cv.campaign_id
             LEFT JOIN core.offers o    ON o.id = cv.offer_id
             {$where}
             GROUP BY 1
             ORDER BY conversions DESC, label
             LIMIT :lim",
            $params,
        );
        return array_map(static fn (array $r): array => [
            'label'       => (string)$r['label'],
            'conversions' => (int)$r['conversions'],
            'approved'    => (int)$r['approved'],
            'payout'      => (string)$r['payout'],
        ], $rows);
    }

    /** @return array{0:string, 1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $cond = [];
        $params = [];
        if (!empty($filters['campaign_id'])) {
            $cond[] = 'cv.campaign_id = :cid';
            $params['cid'] = (string)$filters['campaign_id'];
        }
        if (!empty($filters['status'])) {
            $cond[] = 'cv.status = :status';
            $params['status'] = (string)$filters['status'];
        }
        // The filter bar's day range (from/to, inclusive) wins over the legacy
        // ISO 'since' of old deep links.
        $from = DateRange::day($filters['from'] ?? null);
        $to   = DateRange::day($filters['to'] ?? null);
        if ($from !== null) {
            $cond[] = 'cv.created_at >= :from::timestamptz';
            $params['from'] = $from;
        } elseif (!empty($filters['since'])) {
            $cond[] = 'cv.created_at >= :since';
            $params['since'] = (string)$filters['since'];
        } else {
            $cond[] = "cv.created_at >= now() - interval '30 days'";
        }
        if ($to !== null) {
            $cond[] = 'cv.created_at < :to_excl::timestamptz';
            $params['to_excl'] = DateRange::exclusiveEnd($to);
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }
}
