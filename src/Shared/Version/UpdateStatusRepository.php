<?php

declare(strict_types=1);

namespace App\Shared\Version;

use App\Shared\Db\Connection;

/**
 * The single row of core.update_status.
 *
 * Every write is one statement, so a reader can never observe a new version
 * beside an old URL or timestamp.
 */
final class UpdateStatusRepository implements UpdateStateReader
{
    private const CHANNEL = 'github';

    public function __construct(private readonly Connection $db) {}

    public function read(): ?UpdateState
    {
        $row = $this->db->fetchOne(
            'SELECT repo, latest_version, latest_url, published_at,
                    extract(epoch from last_attempt_at)::bigint AS last_attempt_ts,
                    extract(epoch from last_success_at)::bigint AS last_success_ts,
                    last_error, etag
               FROM core.update_status
              WHERE channel = :c',
            ['c' => self::CHANNEL],
        );

        if ($row === null) {
            return null;
        }

        return new UpdateState(
            repo:          (string)$row['repo'],
            latestVersion: self::str($row['latest_version']),
            latestUrl:     self::str($row['latest_url']),
            publishedAt:   self::str($row['published_at']),
            lastAttemptAt: self::int($row['last_attempt_ts']),
            lastSuccessAt: self::int($row['last_success_ts']),
            lastError:     self::str($row['last_error']),
            etag:          self::str($row['etag']),
        );
    }

    /**
     * A validated 200: release data, the validator that produced it, and both
     * timestamps land together. `last_error` is cleared here — a recovered
     * check must not keep carrying an old failure.
     */
    public function recordSuccess(
        string $repo,
        string $latestVersion,
        ?string $latestUrl,
        ?string $publishedAt,
        ?string $etag,
        int $at,
    ): void {
        $this->db->run(
            <<<'SQL'
            INSERT INTO core.update_status
                (channel, repo, latest_version, latest_url, published_at,
                 last_attempt_at, last_success_at, last_error, etag, updated_at)
            VALUES
                (:c, :repo, :v, :url, :pub,
                 to_timestamp(:at), to_timestamp(:at), NULL, :etag, now())
            ON CONFLICT (channel) DO UPDATE SET
                repo            = EXCLUDED.repo,
                latest_version  = EXCLUDED.latest_version,
                latest_url      = EXCLUDED.latest_url,
                published_at    = EXCLUDED.published_at,
                last_attempt_at = EXCLUDED.last_attempt_at,
                last_success_at = EXCLUDED.last_success_at,
                last_error      = NULL,
                etag            = EXCLUDED.etag,
                updated_at      = now()
            SQL,
            ['c' => self::CHANNEL, 'repo' => $repo, 'v' => $latestVersion,
             'url' => $latestUrl, 'pub' => $publishedAt, 'etag' => $etag, 'at' => $at],
        );
    }

    /**
     * A 304: the representation we already hold is still current. Release data
     * and the validator stay untouched; both timestamps advance, because the
     * answer really was confirmed fresh.
     */
    public function recordNotModified(int $at): void
    {
        $this->db->run(
            'UPDATE core.update_status
                SET last_attempt_at = to_timestamp(:at),
                    last_success_at = to_timestamp(:at),
                    last_error      = NULL,
                    updated_at      = now()
              WHERE channel = :c',
            ['c' => self::CHANNEL, 'at' => $at],
        );
    }

    /**
     * A failed attempt. Release data and `last_success_at` are preserved — the
     * last good answer stays available; only its freshness ages out, which the
     * `stale` state then reports.
     */
    public function recordFailure(string $repo, string $error, int $at): void
    {
        $this->db->run(
            <<<'SQL'
            INSERT INTO core.update_status
                (channel, repo, last_attempt_at, last_error, updated_at)
            VALUES
                (:c, :repo, to_timestamp(:at), :err, now())
            ON CONFLICT (channel) DO UPDATE SET
                last_attempt_at = EXCLUDED.last_attempt_at,
                last_error      = EXCLUDED.last_error,
                updated_at      = now()
            SQL,
            ['c' => self::CHANNEL, 'repo' => $repo, 'err' => $error, 'at' => $at],
        );
    }

    /**
     * The configured repository changed. Everything tied to the old one goes
     * in a single statement, *before* the next request: release data, the
     * validator, and `last_success_at`.
     *
     * Clearing `last_success_at` is the part that matters. Left behind, a repo
     * change followed by a failed request keeps a recent success timestamp
     * belonging to the previous repository — the freshness check passes and the
     * footer asserts a verdict for data it no longer has.
     */
    public function resetForRepo(string $repo, int $at): void
    {
        $this->db->run(
            <<<'SQL'
            INSERT INTO core.update_status
                (channel, repo, latest_version, latest_url, published_at,
                 last_attempt_at, last_success_at, last_error, etag, updated_at)
            VALUES
                (:c, :repo, NULL, NULL, NULL, to_timestamp(:at), NULL, NULL, NULL, now())
            ON CONFLICT (channel) DO UPDATE SET
                repo            = EXCLUDED.repo,
                latest_version  = NULL,
                latest_url      = NULL,
                published_at    = NULL,
                last_success_at = NULL,
                last_error      = NULL,
                etag            = NULL,
                updated_at      = now()
            SQL,
            ['c' => self::CHANNEL, 'repo' => $repo, 'at' => $at],
        );
    }

    /**
     * Serialise checks so two runners (duplicate cron containers, a manual run
     * beside the scheduled one) cannot interleave. Session-scoped; released
     * when the connection closes at process exit.
     */
    public function tryLock(): bool
    {
        $row = $this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(:k)) AS ok', ['k' => 'slimtds.update_check']);
        return $row !== null && (bool)$row['ok'];
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string)$v;
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : (int)$v;
    }
}
