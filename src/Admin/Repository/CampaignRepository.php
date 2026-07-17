<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

final class CampaignRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly CampaignIdGenerator $idGen,
    ) {}

    public function findById(string $id): ?Campaign
    {
        $row = $this->db->fetchOne('SELECT * FROM core.campaigns WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Campaign::fromRow($row);
    }

    public function findBySlug(string $slug): ?Campaign
    {
        $row = $this->db->fetchOne('SELECT * FROM core.campaigns WHERE slug = :slug', ['slug' => $slug]);
        return $row === null ? null : Campaign::fromRow($row);
    }

    public function findByPostbackToken(string $token): ?Campaign
    {
        $row = $this->db->fetchOne('SELECT * FROM core.campaigns WHERE postback_token = :token', ['token' => $token]);
        return $row === null ? null : Campaign::fromRow($row);
    }

    /** @return list<Campaign> */
    public function page(int $page, int $perPage, ?string $q = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = ['limit' => $perPage, 'offset' => $offset];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = "WHERE name ILIKE :q OR slug ILIKE :q";
            $params['q'] = '%' . $q . '%';
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM core.campaigns {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            $params,
        );
        return array_map(Campaign::fromRow(...), $rows);
    }

    public function count(?string $q = null): int
    {
        $params = [];
        $where = '';
        if ($q !== null && trim($q) !== '') {
            $where = "WHERE name ILIKE :q OR slug ILIKE :q";
            $params['q'] = '%' . $q . '%';
        }
        return (int)$this->db->fetchScalar("SELECT count(*) FROM core.campaigns {$where}", $params);
    }

    public function slugExists(string $slug, ?string $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $n = (int)$this->db->fetchScalar(
                'SELECT count(*) FROM core.campaigns WHERE slug = :slug AND id <> :id',
                ['slug' => $slug, 'id' => $excludeId],
            );
        } else {
            $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.campaigns WHERE slug = :slug', ['slug' => $slug]);
        }
        return $n > 0;
    }

    /**
     * @param array<string,mixed> $data name, notes, is_active, slug (optional custom), trash_mode, trash_url
     */
    public function create(array $data): Campaign
    {
        $slug = isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== ''
            ? $data['slug']
            : $this->generateUniqueSlug();

        $row = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.campaigns (slug, name, notes, is_active, trash_mode, trash_url, sticky_offer)
                VALUES (:slug, :name, :notes, :is_active, :trash_mode, :trash_url, :sticky_offer)
                RETURNING *
            SQL,
            [
                'slug'         => $slug,
                'name'         => (string)($data['name'] ?? ''),
                'notes'        => isset($data['notes']) && $data['notes'] !== '' ? (string)$data['notes'] : null,
                'is_active'    => !empty($data['is_active']) ? 'true' : 'false',
                'trash_mode'   => (int)($data['trash_mode'] ?? 0),
                'trash_url'    => isset($data['trash_url']) && $data['trash_url'] !== '' ? (string)$data['trash_url'] : null,
                // absent key → keep the DB default (true) so seeds/tests stay sticky
                'sticky_offer' => array_key_exists('sticky_offer', $data) ? (!empty($data['sticky_offer']) ? 'true' : 'false') : 'true',
            ],
        );
        return Campaign::fromRow($row);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $id, array $data): ?Campaign
    {
        $params = [
            'id'         => $id,
            'slug'       => (string)($data['slug'] ?? ''),
            'name'       => (string)($data['name'] ?? ''),
            'notes'      => isset($data['notes']) && $data['notes'] !== '' ? (string)$data['notes'] : null,
            'is_active'  => !empty($data['is_active']) ? 'true' : 'false',
            'trash_mode' => (int)($data['trash_mode'] ?? 0),
            'trash_url'  => isset($data['trash_url']) && $data['trash_url'] !== '' ? (string)$data['trash_url'] : null,
            'sticky_offer' => !empty($data['sticky_offer']) ? 'true' : 'false',
        ];
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE core.campaigns
                SET slug = :slug,
                    name = :name,
                    notes = :notes,
                    is_active = :is_active,
                    trash_mode = :trash_mode,
                    trash_url = :trash_url,
                    sticky_offer = :sticky_offer,
                    updated_at = now()
                WHERE id = :id
                RETURNING *
            SQL,
            $params,
        );
        return $row === null ? null : Campaign::fromRow($row);
    }

    public function delete(string $id): bool
    {
        return $this->db->execute('DELETE FROM core.campaigns WHERE id = :id', ['id' => $id]) > 0;
    }

    private function generateUniqueSlug(int $maxAttempts = 10): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = $this->idGen->generate();
            if (!$this->slugExists($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('could not generate unique slug after ' . $maxAttempts . ' attempts');
    }
}
