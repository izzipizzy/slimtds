<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * A validated `owner/repo` GitHub slug, and the only place update-check URLs
 * are built.
 *
 * This is the credential boundary. The operator configures *which repository*
 * is queried; they never configure *which host* receives the request — and so
 * never which host receives `UPDATE_CHECK_TOKEN`. Both hosts below are
 * constants.
 *
 * Each segment is validated separately, and `.` / `..` are rejected outright.
 * A character-class check alone is not enough: it accepts `owner/..`, and
 * percent-encoding does not neutralise dot segments, so the request path could
 * normalise away from /repos/<owner>/<repo>/… .
 */
final class RepoSlug
{
    private const API_HOST = 'https://api.github.com';
    private const WEB_HOST = 'https://github.com';

    private const SEGMENT = '/^[A-Za-z0-9._-]{1,100}$/';

    private function __construct(
        private readonly string $owner,
        private readonly string $repo,
    ) {}

    public static function tryParse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $parts = explode('/', trim($raw));
        if (count($parts) !== 2) {
            return null;
        }

        foreach ($parts as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return new self($parts[0], $parts[1]);
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function __toString(): string
    {
        return $this->owner . '/' . $this->repo;
    }

    public function apiLatestReleaseUrl(): string
    {
        return self::API_HOST . '/repos/' . $this->path() . '/releases/latest';
    }

    /**
     * The compare view — every commit between the running release and the new
     * one, which is what "what changed" actually means when you are more than
     * one release behind.
     */
    public function compareUrl(string $from, string $to): ?string
    {
        if (!self::isSafeTag($from) || !self::isSafeTag($to)) {
            return null;
        }
        return self::WEB_HOST . '/' . $this->path() . '/compare/'
            . rawurlencode($from) . '...' . rawurlencode($to);
    }

    public function releaseTagUrl(string $tag): ?string
    {
        if (!self::isSafeTag($tag)) {
            return null;
        }
        return self::WEB_HOST . '/' . $this->path() . '/releases/tag/' . rawurlencode($tag);
    }

    /**
     * The update instructions, pinned to the release being offered — so the
     * steps the operator reads are the ones that shipped with that version,
     * not whatever main happens to say today.
     */
    public function updateGuideUrl(string $tag): ?string
    {
        if (!self::isSafeTag($tag)) {
            return null;
        }
        return self::WEB_HOST . '/' . $this->path() . '/blob/' . rawurlencode($tag)
            . '/README.md#updating-an-existing-install';
    }

    private function path(): string
    {
        return rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
    }

    /** Only something SemVer accepts is ever allowed into a URL. */
    private static function isSafeTag(string $tag): bool
    {
        return SemVer::parse($tag) !== null;
    }
}
