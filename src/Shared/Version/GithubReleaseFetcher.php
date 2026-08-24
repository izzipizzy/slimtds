<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Reads `releases/latest` from the GitHub API.
 *
 * Deliberately stricter than BotsUpdateCommand, which is the other HTTP caller
 * in this project: that one fetches a plaintext blocklist and only needs to
 * know whether it got a body. This response drives a link rendered in the
 * admin UI and may carry a token, so:
 *
 *  - redirects are disabled. PHP streams follow them by default, and a
 *    redirect would forward the Authorization header to the target host.
 *  - the body is read through a size cap.
 *  - JSON is decoded with JSON_THROW_ON_ERROR and every field is type-checked.
 *  - tag_name must parse as semver or the response is rejected.
 *  - html_url is ignored entirely; URLs are built locally by RepoSlug.
 *  - error text comes from a fixed vocabulary, never from raw transport
 *    messages, which can contain the request URL.
 */
final class GithubReleaseFetcher implements ReleaseFetcher
{
    private const TIMEOUT_SECONDS = 8;
    private const MAX_BYTES       = 512 * 1024;
    private const USER_AGENT      = 'slimTDS-update-check/1.0';

    public function __construct(private readonly ?string $token = null) {}

    public function fetchLatest(RepoSlug $repo, ?string $etag = null): ReleaseResult
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'timeout'         => self::TIMEOUT_SECONDS,
            'follow_location' => 0,      // never forward the token to another host
            'max_redirects'   => 0,
            'ignore_errors'   => true,   // read 4xx/5xx bodies instead of warning
        ]]);

        $stream = @fopen($repo->apiLatestReleaseUrl(), 'rb', false, $ctx);
        if ($stream === false) {
            throw new ReleaseFetchException('transport failure: could not reach the API');
        }

        try {
            $body = stream_get_contents($stream, self::MAX_BYTES + 1);
            // PHP populates $http_response_header in the local scope once the
            // stream is open; we only get here after a successful fopen.
            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;
        } finally {
            fclose($stream);
        }

        if (!is_string($body)) {
            throw new ReleaseFetchException('transport failure: empty response');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new ReleaseFetchException('response too large');
        }

        $status = self::statusFrom($responseHeaders);

        if ($status === 304) {
            return ReleaseResult::notModified();
        }
        if ($status !== 200) {
            throw new ReleaseFetchException(self::describeStatus($status));
        }

        return self::parseBody($body, self::headerValue($responseHeaders, 'etag'));
    }

    private static function parseBody(string $body, ?string $etag): ReleaseResult
    {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ReleaseFetchException('malformed JSON in response');
        }

        if (!is_array($data) || !isset($data['tag_name']) || !is_string($data['tag_name'])) {
            throw new ReleaseFetchException('response has no usable tag_name');
        }

        $tag = trim($data['tag_name']);
        if (SemVer::parse($tag) === null) {
            throw new ReleaseFetchException('tag_name is not a valid version');
        }

        $published = null;
        if (isset($data['published_at']) && is_string($data['published_at'])) {
            $p = trim($data['published_at']);
            // Only an ISO-8601 instant; anything else is dropped rather than stored.
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $p) === 1) {
                $published = $p;
            }
        }

        return ReleaseResult::release($tag, $published, $etag);
    }

    /** @param list<string> $headers */
    private static function statusFrom(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int)$m[1];   // keep walking: the last status line wins
            }
        }
        return $status ?? 0;
    }

    /** @param list<string> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';
        foreach ($headers as $h) {
            if (str_starts_with(strtolower($h), $needle)) {
                $v = trim(substr($h, strlen($needle)));
                return $v === '' ? null : $v;
            }
        }
        return null;
    }

    private static function describeStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'authentication rejected (check UPDATE_CHECK_TOKEN)',
            $status === 403 => 'forbidden or rate-limited',
            $status === 404 => 'repository or release not found',
            $status === 429 => 'rate-limited',
            $status >= 500  => "upstream error (HTTP {$status})",
            $status === 0   => 'no HTTP status in response',
            default         => "unexpected HTTP {$status}",
        };
    }
}
