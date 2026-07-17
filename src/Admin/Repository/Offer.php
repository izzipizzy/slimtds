<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class Offer
{
    /**
     * @param list<string> $postbackUrls  Outgoing S2S postback URL templates
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public string $postbackToken,
        public ?string $payoutDefault,
        public string $currency,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $postbackUrls = [],
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $rawUrls = $row['postback_urls'] ?? '[]';
        if (is_string($rawUrls)) {
            $decoded = json_decode($rawUrls, true);
            $decoded = is_array($decoded) ? $decoded : [];
        } else {
            $decoded = is_array($rawUrls) ? $rawUrls : [];
        }
        /** @var list<string> $postbackUrls */
        $postbackUrls = array_values(array_filter(
            array_map('strval', $decoded),
            static fn (string $u) => trim($u) !== '',
        ));

        return new self(
            id:            (string)$row['id'],
            name:          (string)$row['name'],
            url:           (string)$row['url'],
            postbackToken: (string)$row['postback_token'],
            payoutDefault: isset($row['payout_default']) && $row['payout_default'] !== null
                ? (string)$row['payout_default']
                : null,
            currency:      (string)$row['currency'],
            isActive:      (bool)$row['is_active'],
            createdAt:     new DateTimeImmutable((string)$row['created_at']),
            updatedAt:     new DateTimeImmutable((string)$row['updated_at']),
            postbackUrls:  $postbackUrls,
        );
    }
}
