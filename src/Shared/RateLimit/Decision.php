<?php

declare(strict_types=1);

namespace App\Shared\RateLimit;

final readonly class Decision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $resetAt,  // unix timestamp when the window ends
    ) {}
}
