<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Either a validated release, or a 304 saying the stored representation is
 * still current.
 */
final class ReleaseResult
{
    private function __construct(
        public readonly bool $notModified,
        public readonly ?string $version = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $etag = null,
    ) {}

    public static function release(string $version, ?string $publishedAt, ?string $etag): self
    {
        return new self(false, $version, $publishedAt, $etag);
    }

    public static function notModified(): self
    {
        return new self(true);
    }
}
