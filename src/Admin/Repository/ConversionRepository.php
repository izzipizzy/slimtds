<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class ConversionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{campaign_id?:?string, status?:?string, since?:?string} $filters
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
        if (!empty($filters['since'])) {
            $cond[] = 'cv.created_at >= :since';
            $params['since'] = (string)$filters['since'];
        } else {
            $cond[] = "cv.created_at >= now() - interval '30 days'";
        }
        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }
}
