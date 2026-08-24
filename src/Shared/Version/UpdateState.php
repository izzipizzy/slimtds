<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * The persisted result of update checking — one row of core.update_status.
 *
 * `lastAttemptAt` and `lastSuccessAt` are deliberately separate: one timestamp
 * cannot answer both "when did we last try" and "how old is the answer we are
 * showing", and conflating them is how a checker that has been broken for
 * months keeps asserting a fresh verdict.
 */
final class UpdateState
{
    public function __construct(
        public readonly string $repo,
        public readonly ?string $latestVersion = null,
        public readonly ?string $latestUrl = null,
        public readonly ?string $publishedAt = null,
        public readonly ?int $lastAttemptAt = null,
        public readonly ?int $lastSuccessAt = null,
        public readonly ?string $lastError = null,
        public readonly ?string $etag = null,
    ) {}

    /** A stored validator is only usable against the repository it came from. */
    public function etagFor(string $repo): ?string
    {
        return $this->repo === $repo ? $this->etag : null;
    }

    public function hasRelease(): bool
    {
        return $this->latestVersion !== null && $this->latestVersion !== '';
    }
}
