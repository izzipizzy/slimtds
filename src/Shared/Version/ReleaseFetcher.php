<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Fetches the latest release of a repository. An interface so the check can be
 * tested exhaustively without touching the network.
 */
interface ReleaseFetcher
{
    /**
     * @param string|null $etag validator from the previous successful fetch
     * @throws ReleaseFetchException on transport, HTTP, or validation failure
     */
    public function fetchLatest(RepoSlug $repo, ?string $etag = null): ReleaseResult;
}
