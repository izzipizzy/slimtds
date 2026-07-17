<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class FlowRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findById(string $id): ?Flow
    {
        $row = $this->db->fetchOne('SELECT * FROM core.flows WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Flow::fromRow($row);
    }

    /** @return list<Flow> */
    public function forCampaign(string $campaignId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM core.flows WHERE campaign_id = :cid ORDER BY position, created_at',
            ['cid' => $campaignId],
        );
        return array_map(Flow::fromRow(...), $rows);
    }

    public function countForCampaign(string $campaignId): int
    {
        return (int)$this->db->fetchScalar('SELECT count(*) FROM core.flows WHERE campaign_id = :cid', ['cid' => $campaignId]);
    }

    /**
     * Grouped counts for multiple campaigns — mirrors OfferRepository::countsByCampaign.
     * @param list<string> $campaignIds
     * @return array<string,int>
     */
    public function countsByCampaign(array $campaignIds): array
    {
        if (empty($campaignIds)) return [];
        $placeholders = [];
        $params = [];
        foreach ($campaignIds as $i => $cid) {
            $k = "cid{$i}";
            $placeholders[] = ":{$k}";
            $params[$k] = $cid;
        }
        $in = implode(',', $placeholders);
        $rows = $this->db->fetchAll(
            "SELECT campaign_id, count(*) AS n FROM core.flows WHERE campaign_id IN ({$in}) GROUP BY campaign_id",
            $params,
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(string)$r['campaign_id']] = (int)$r['n'];
        }
        foreach ($campaignIds as $cid) {
            $result[$cid] = $result[$cid] ?? 0;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    public function create(string $campaignId, array $data): Flow
    {
        $nextPos = (int)$this->db->fetchScalar(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM core.flows WHERE campaign_id = :cid',
            ['cid' => $campaignId],
        );
        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.flows
                    (campaign_id, name, filters, target_type, target_offers, schema_id, schema_config, weight, position, is_active, sticky_offer)
                VALUES
                    (:cid, :name, :filters::jsonb, :target_type, :target_offers::jsonb, :schema_id, :schema_config::jsonb, :weight, :position, :is_active, :sticky_offer)
                RETURNING *
            SQL,
            [
                'cid'            => $campaignId,
                'name'           => (string)($data['name'] ?? ''),
                'filters'        => $this->encode($data['filters'] ?? []),
                'target_type'    => (string)($data['target_type'] ?? 'offers'),
                'target_offers'  => $this->encode($data['target_offers'] ?? []),
                'schema_id'      => (int)($data['schema_id'] ?? 2),
                'schema_config'  => $this->encode($data['schema_config'] ?? []),
                'weight'         => (int)($data['weight'] ?? 100),
                'position'       => $nextPos,
                'is_active'      => !empty($data['is_active']) ? 'true' : 'false',
                'sticky_offer'   => $this->stickyBind($data),
            ],
        );
        return Flow::fromRow($row);
    }

    /** @param array<string,mixed> $data */
    public function update(string $id, array $data): ?Flow
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.flows SET
                    name          = :name,
                    filters       = :filters::jsonb,
                    target_type   = :target_type,
                    target_offers = :target_offers::jsonb,
                    schema_id     = :schema_id,
                    schema_config = :schema_config::jsonb,
                    weight        = :weight,
                    is_active     = :is_active,
                    sticky_offer  = :sticky_offer,
                    updated_at    = now()
                WHERE id = :id
                RETURNING *
            SQL,
            [
                'id'             => $id,
                'name'           => (string)($data['name'] ?? ''),
                'filters'        => $this->encode($data['filters'] ?? []),
                'target_type'    => (string)($data['target_type'] ?? 'offers'),
                'target_offers'  => $this->encode($data['target_offers'] ?? []),
                'schema_id'      => (int)($data['schema_id'] ?? 2),
                'schema_config'  => $this->encode($data['schema_config'] ?? []),
                'weight'         => (int)($data['weight'] ?? 100),
                'is_active'      => !empty($data['is_active']) ? 'true' : 'false',
                'sticky_offer'   => $this->stickyBind($data),
            ],
        );
        return $row === null ? null : Flow::fromRow($row);
    }

    public function delete(string $id): bool
    {
        return $this->db->execute('DELETE FROM core.flows WHERE id = :id', ['id' => $id]) > 0;
    }

    /** @param list<string> $orderedIds */
    public function reorder(string $campaignId, array $orderedIds): void
    {
        $this->db->transactional(function () use ($campaignId, $orderedIds): void {
            foreach ($orderedIds as $i => $id) {
                $this->db->execute(
                    'UPDATE core.flows SET position = :p, updated_at = now() WHERE id = :id AND campaign_id = :cid',
                    ['p' => $i + 1, 'id' => $id, 'cid' => $campaignId],
                );
            }
        });
    }

    /**
     * @return list<array{flow:Flow, campaign_slug:string, campaign_name:string}>
     */
    public function pageAll(int $page, int $perPage, ?string $q = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = ['limit' => $perPage, 'offset' => $offset];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = "WHERE f.name ILIKE :q OR c.name ILIKE :q OR c.slug ILIKE :q";
            $params['q'] = '%' . trim($q) . '%';
        }
        $rows = $this->db->fetchAll(
            "SELECT f.*, c.slug AS _camp_slug, c.name AS _camp_name
             FROM core.flows f
             JOIN core.campaigns c ON c.id = f.campaign_id
             {$where}
             ORDER BY f.created_at DESC
             LIMIT :limit OFFSET :offset",
            $params,
        );
        $out = [];
        foreach ($rows as $r) {
            $slug = (string)$r['_camp_slug'];
            $name = (string)$r['_camp_name'];
            unset($r['_camp_slug'], $r['_camp_name']);
            $out[] = ['flow' => Flow::fromRow($r), 'campaign_slug' => $slug, 'campaign_name' => $name];
        }
        return $out;
    }

    public function countAll(?string $q = null): int
    {
        $params = [];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = "WHERE f.name ILIKE :q OR c.name ILIKE :q OR c.slug ILIKE :q";
            $params['q'] = '%' . trim($q) . '%';
        }
        return (int)$this->db->fetchScalar(
            "SELECT count(*) FROM core.flows f JOIN core.campaigns c ON c.id=f.campaign_id {$where}",
            $params,
        );
    }

    private function encode(mixed $v): string
    {
        if (is_string($v)) {
            // already JSON from form
            return $v === '' ? '[]' : $v;
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Bind value for the nullable flows.sticky_offer column.
     * Absent or null → NULL (inherit campaign); otherwise 'true'/'false'.
     * @param array<string,mixed> $data
     */
    private function stickyBind(array $data): ?string
    {
        if (!array_key_exists('sticky_offer', $data)) {
            return null;
        }
        $v = $data['sticky_offer'];
        // null / '' / 'inherit' → inherit campaign (NULL).
        if ($v === null || $v === '' || $v === 'inherit') {
            return null;
        }
        // 'rotate' / '0' / false → rotate by weight; everything else → sticky.
        return ($v === 'rotate' || $v === '0' || $v === 0 || $v === false) ? 'false' : 'true';
    }
}
