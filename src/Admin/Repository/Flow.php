<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class Flow
{
    /**
     * @param list<list<array{field:string,op:string,value:mixed}>> $filters
     * @param list<array{offer_id:string,weight:int}> $targetOffers
     * @param array<string,mixed> $schemaConfig
     */
    public function __construct(
        public string $id,
        public string $campaignId,
        public string $name,
        public array $filters,
        public string $targetType,
        public array $targetOffers,
        public int $schemaId,
        public array $schemaConfig,
        public int $weight,
        public int $position,
        public bool $isActive,
        public ?bool $stickyOffer,   // null = inherit campaign; true/false = override
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (string)$row['id'],
            campaignId:    (string)$row['campaign_id'],
            name:          (string)$row['name'],
            filters:       self::decodeJson((string)$row['filters'], []),
            targetType:    (string)$row['target_type'],
            targetOffers:  self::decodeJson((string)$row['target_offers'], []),
            schemaId:      (int)$row['schema_id'],
            schemaConfig:  self::decodeJson((string)$row['schema_config'], []),
            weight:        (int)$row['weight'],
            position:      (int)$row['position'],
            isActive:      (bool)$row['is_active'],
            stickyOffer:   array_key_exists('sticky_offer', $row) && $row['sticky_offer'] !== null ? (bool)$row['sticky_offer'] : null,
            createdAt:     new DateTimeImmutable((string)$row['created_at']),
            updatedAt:     new DateTimeImmutable((string)$row['updated_at']),
        );
    }

    private static function decodeJson(string $json, array $default): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? $v : $default;
    }
}
