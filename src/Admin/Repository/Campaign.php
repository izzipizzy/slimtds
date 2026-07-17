<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class Campaign
{
    public function __construct(
        public string $id,               // uuid
        public string $slug,             // citext, unique
        public string $name,
        public ?string $notes,
        public bool $isActive,
        public ?int $defaultSchema,
        public int $trashMode,           // 0=blank, 1=302 to trash_url, 2=403, 3=404
        public ?string $trashUrl,
        public string $postbackToken,    // catch-all token for the whole campaign
        public bool $stickyOffer,        // true = consistent-hash per visitor; false = rotate by weight
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string)$row['id'],
            slug:           (string)$row['slug'],
            name:           (string)$row['name'],
            notes:          isset($row['notes']) ? (string)$row['notes'] : null,
            isActive:       (bool)$row['is_active'],
            defaultSchema:  isset($row['default_schema']) ? (int)$row['default_schema'] : null,
            trashMode:      (int)$row['trash_mode'],
            trashUrl:       isset($row['trash_url']) ? (string)$row['trash_url'] : null,
            postbackToken:  (string)($row['postback_token'] ?? ''),
            stickyOffer:    (bool)($row['sticky_offer'] ?? true),
            createdAt:      new DateTimeImmutable((string)$row['created_at']),
            updatedAt:      new DateTimeImmutable((string)$row['updated_at']),
        );
    }
}
