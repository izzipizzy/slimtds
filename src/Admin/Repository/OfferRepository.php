<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class OfferRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findById(string $id): ?Offer
    {
        $row = $this->db->fetchOne('SELECT * FROM core.offers WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Offer::fromRow($row);
    }

    public function findByToken(string $token): ?Offer
    {
        $row = $this->db->fetchOne('SELECT * FROM core.offers WHERE postback_token = :t', ['t' => $token]);
        return $row === null ? null : Offer::fromRow($row);
    }

    /** Exact name match; if names collide, the most recently created wins. */
    public function findByName(string $name): ?Offer
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM core.offers WHERE name = :n ORDER BY created_at DESC LIMIT 1',
            ['n' => $name],
        );
        return $row === null ? null : Offer::fromRow($row);
    }

    /**
     * All offers (global). Optional pagination.
     * @return list<Offer>
     */
    public function all(?int $limit = null, ?int $offset = null): array
    {
        $sql = 'SELECT * FROM core.offers ORDER BY created_at DESC';
        $params = [];
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params['limit'] = $limit;
            $params['offset'] = $offset ?? 0;
        }
        return array_map(Offer::fromRow(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * Offers referenced by any flow of a campaign (extracted from flows.target_offers JSONB).
     * @return list<Offer>
     */
    public function forCampaign(string $campaignId): array
    {
        // A target row saved without an offer picked leaves {"offer_id": ""} in the
        // JSONB; a bare ->>'offer_id')::uuid would throw 22P02 on that empty string.
        // Skip anything that isn't a UUID-shaped string before casting.
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT DISTINCT o.*
                FROM core.offers o
                WHERE o.id IN (
                    SELECT (elem ->> 'offer_id')::uuid
                    FROM core.flows f
                    CROSS JOIN LATERAL jsonb_array_elements(f.target_offers) AS elem
                    WHERE f.campaign_id = :cid AND f.target_type = 'offers'
                      AND (elem ->> 'offer_id') ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
                )
                ORDER BY o.created_at DESC
            SQL,
            ['cid' => $campaignId],
        );
        return array_map(Offer::fromRow(...), $rows);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): Offer
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.offers (name, url, payout_default, currency, is_active, postback_urls)
                VALUES (:name, :url, :payout_default, :currency, :is_active, :postback_urls::jsonb)
                RETURNING *
            SQL,
            [
                'name'           => (string)($data['name'] ?? ''),
                'url'            => trim((string)($data['url'] ?? '')),
                'payout_default' => $this->numeric($data['payout_default'] ?? null),
                'currency'       => (string)($data['currency'] ?? 'USD'),
                'is_active'      => !empty($data['is_active']) ? 'true' : 'false',
                'postback_urls'  => $this->encodePostbackUrls($data['postback_urls'] ?? []),
            ],
        );
        return Offer::fromRow($row);
    }

    /** @param array<string,mixed> $data */
    public function update(string $id, array $data): ?Offer
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.offers
                SET name = :name,
                    url = :url,
                    payout_default = :payout_default,
                    currency = :currency,
                    is_active = :is_active,
                    postback_urls = :postback_urls::jsonb,
                    updated_at = now()
                WHERE id = :id
                RETURNING *
            SQL,
            [
                'id'             => $id,
                'name'           => (string)($data['name'] ?? ''),
                'url'            => trim((string)($data['url'] ?? '')),
                'payout_default' => $this->numeric($data['payout_default'] ?? null),
                'currency'       => (string)($data['currency'] ?? 'USD'),
                'is_active'      => !empty($data['is_active']) ? 'true' : 'false',
                'postback_urls'  => $this->encodePostbackUrls($data['postback_urls'] ?? []),
            ],
        );
        return $row === null ? null : Offer::fromRow($row);
    }

    public function rotateToken(string $id): ?string
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.offers
                SET postback_token = encode(gen_random_bytes(16), 'hex'), updated_at = now()
                WHERE id = :id
                RETURNING postback_token
            SQL,
            ['id' => $id],
        );
        return $row === null ? null : (string)$row['postback_token'];
    }

    public function delete(string $id): bool
    {
        // Strip the offer from any flow that references it so we don't leave dangling
        // {offer_id: <gone>} entries in flows.target_offers. The picker would skip them
        // gracefully, but UI counts would be off.
        return $this->db->transactional(function () use ($id) {
            $this->db->execute(
                <<<'SQL'
                    UPDATE core.flows
                    SET target_offers = (
                        SELECT COALESCE(jsonb_agg(elem), '[]'::jsonb)
                        FROM jsonb_array_elements(target_offers) elem
                        WHERE elem ->> 'offer_id' <> :oid
                    )
                    WHERE target_offers @> jsonb_build_array(jsonb_build_object('offer_id', :oid::text))
                SQL,
                ['oid' => $id],
            );
            return $this->db->execute('DELETE FROM core.offers WHERE id = :id', ['id' => $id]) > 0;
        });
    }

    /**
     * Number of flows referencing this offer in target_offers (any campaign).
     */
    public function flowReferenceCount(string $offerId): int
    {
        return (int)$this->db->fetchScalar(
            <<<'SQL'
                SELECT count(*) FROM core.flows
                WHERE target_offers @> jsonb_build_array(jsonb_build_object('offer_id', :oid::text))
            SQL,
            ['oid' => $offerId],
        );
    }

    /**
     * Top-level paginated list with usage info (number of flows referencing each offer).
     * @return list<array{offer:Offer, flow_refs:int}>
     */
    public function pageAll(int $page, int $perPage, ?string $q = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = ['limit' => $perPage, 'offset' => $offset];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE o.name ILIKE :q OR o.url ILIKE :q';
            $params['q'] = '%' . trim($q) . '%';
        }
        $sql = "
            SELECT o.*, (
                SELECT count(*) FROM core.flows f
                WHERE f.target_offers @> jsonb_build_array(jsonb_build_object('offer_id', o.id::text))
            ) AS _flow_refs
            FROM core.offers o
            {$where}
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $refs = (int)$r['_flow_refs'];
            unset($r['_flow_refs']);
            $out[] = ['offer' => Offer::fromRow($r), 'flow_refs' => $refs];
        }
        return $out;
    }

    public function countAll(?string $q = null): int
    {
        $params = [];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE name ILIKE :q OR url ILIKE :q';
            $params['q'] = '%' . trim($q) . '%';
        }
        return (int)$this->db->fetchScalar("SELECT count(*) FROM core.offers {$where}", $params);
    }

    /**
     * Distinct offer count per campaign — derived from flows.target_offers JSONB.
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
            <<<SQL
                SELECT campaign_id, COUNT(DISTINCT offer_id) AS n
                FROM core.flows f, LATERAL jsonb_array_elements(f.target_offers) AS elem(val),
                     LATERAL (SELECT (val ->> 'offer_id') AS offer_id) AS o
                WHERE f.campaign_id IN ({$in}) AND f.target_type = 'offers' AND offer_id IS NOT NULL
                GROUP BY campaign_id
            SQL,
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

    /** @param mixed $urls */
    private function encodePostbackUrls(mixed $urls): string
    {
        $clean = array_values(array_filter(
            (array)$urls,
            static fn (mixed $u) => is_string($u) && trim($u) !== '',
        ));
        return json_encode($clean, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private function numeric(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v)) return null;
        return (string)$v;
    }
}
