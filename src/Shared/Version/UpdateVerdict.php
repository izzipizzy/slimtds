<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * What the footer renders. `state` drives the chip; `versionLabel` is shown
 * whenever the build identity is known, independently of the state — a build
 * we can name should be named even when we have nothing to say about updates.
 */
final class UpdateVerdict
{
    public const CURRENT = 'release_current';
    public const BEHIND  = 'behind';
    public const SOURCE  = 'source_build';
    public const STALE   = 'stale';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $state,
        public readonly string $versionLabel,
        public readonly string $commit = '',
        public readonly ?string $latestVersion = null,
        public readonly ?string $chipUrl = null,
        public readonly ?string $guideUrl = null,
        public readonly ?int $lastSuccessAt = null,
        public readonly ?string $publishedAt = null,
    ) {}

    public function isBehind(): bool
    {
        return $this->state === self::BEHIND;
    }
}
