<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;
use App\Shared\Referer\SearchEngine;

final class RrwebSessionRepository
{
    /** Host extracted from a full page_url, e.g. 'https://x.com/a' → 'x.com'. */
    private const HOST_EXPR = "substring(page_url from '://([^/]+)')";

    /** filter key → SQL expression it matches (whitelist; values are bound). */
    private const FILTERS = [
        'campaign_id' => 'campaign_id',
        'fp'          => 'fp_js',
        'domain'      => self::HOST_EXPR,
        'country'     => 'country',
        'browser'     => 'browser',
        'os'          => 'os',
        'device'      => 'device',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Build the WHERE clause shared by page()/count() from a filters map; fills $params.
     * Only whitelisted keys (self::FILTERS) with a non-empty value are applied.
     * @param array<string,?string> $filters
     * @param array<string,mixed>   $params
     */
    private function whereClause(array $filters, array &$params): string
    {
        $conds = [];
        foreach (self::FILTERS as $key => $expr) {
            $val = $filters[$key] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            $conds[] = $expr . ' = :' . $key;
            $params[$key] = $val;
        }
        // Minimum replay length (seconds) — hides bounces/short sessions.
        $minDur = (int)($filters['min_dur'] ?? 0);
        if ($minDur > 0) {
            $conds[] = 'COALESCE(last_event_ms - first_event_ms, 0) >= :min_dur_ms';
            $params['min_dur_ms'] = $minDur * 1000;
        }

        // Traffic source: match the session's own referer OR the visitor's
        // referer from linked pixel events (by fp_js) — the latter catches the
        // source even when document.referrer was empty (utm-only / AI redirects).
        $source = $filters['source'] ?? null;
        if ($source !== null && $source !== '') {
            [$f1, $p1] = SearchEngine::sqlFilter($source, 'referer', 'src');
            [$f2, $p2] = SearchEngine::sqlFilter($source, 'pe.referer', 'srcp');
            $parts = [];
            if ($f1 !== '') {
                $parts[] = $f1;
                $params += $p1;
            }
            if ($f2 !== '') {
                $parts[] = "EXISTS (SELECT 1 FROM stats.pixel_events pe WHERE pe.fp_js = stats.rrweb_sessions.fp_js AND {$f2})";
                $params += $p2;
            }
            if ($parts !== []) {
                $conds[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        return $conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds);
    }

    /** sort key → ORDER BY expression (whitelist). */
    private const SORTS = [
        'started'  => 'started_at',
        'duration' => '(last_event_ms - first_event_ms)',
    ];

    /**
     * @param array<string,?string> $filters
     * @return list<array<string,mixed>>
     */
    public function page(array $filters, int $page, int $perPage, string $sort = 'started', string $dir = 'desc'): array
    {
        $params = ['lim' => $perPage, 'off' => max(0, ($page - 1) * $perPage)];
        $where = $this->whereClause($filters, $params);
        $col = self::SORTS[$sort] ?? self::SORTS['started'];
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return $this->db->fetchAll(
            "SELECT session_id, campaign_id, visitor_uuid, fp_js, page_url, started_at, last_at,
                    chunk_count, event_count, bytes, country, device, ip, os, browser, referer,
                    (last_event_ms - first_event_ms) AS duration_ms
             FROM stats.rrweb_sessions
             {$where}
             ORDER BY {$col} {$dir} NULLS LAST, session_id DESC
             LIMIT :lim OFFSET :off",
            $params,
        );
    }

    /** @param array<string,?string> $filters */
    public function count(array $filters): int
    {
        $params = [];
        $where = $this->whereClause($filters, $params);
        return (int) $this->db->fetchScalar(
            "SELECT count(*) FROM stats.rrweb_sessions {$where}",
            $params,
        );
    }

    /**
     * Distinct non-empty values of a whitelisted filter field, for its dropdown.
     * @return list<string>
     */
    public function distinct(string $field): array
    {
        $expr = self::FILTERS[$field] ?? null;
        if ($expr === null || $field === 'campaign_id' || $field === 'fp') {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT {$expr} AS v FROM stats.rrweb_sessions
             WHERE {$expr} IS NOT NULL AND {$expr} <> '' ORDER BY v",
        );
        return array_values(array_map(
            static fn (array $r): string => (string)($r['v'] ?? ''),
            $rows,
        ));
    }

    /**
     * For a set of fingerprints, the earliest classifiable referer seen in pixel
     * events — lets the sessions list show the traffic source via the fp link
     * even when the session's own document.referrer was empty.
     *
     * @param list<string> $fps
     * @return array<string,string> fp_js => referer
     */
    public function pixelSourceReferers(array $fps): array
    {
        $fps = array_values(array_unique(array_filter($fps, static fn ($f): bool => is_string($f) && $f !== '')));
        if ($fps === []) {
            return [];
        }
        [$frag, $params] = SearchEngine::sqlFilter('any', 'referer', 'se');
        if ($frag === '') {
            return [];
        }
        $in = [];
        foreach ($fps as $i => $f) {
            $k = 'fp' . $i;
            $in[] = ':' . $k;
            $params[$k] = $f;
        }
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT ON (fp_js) fp_js, referer FROM stats.pixel_events
             WHERE fp_js IN (' . implode(',', $in) . ") AND ({$frag})
             ORDER BY fp_js, created_at",
            $params,
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['fp_js']] = (string)$r['referer'];
        }
        return $map;
    }

    /** @return array<string,mixed>|null */
    public function get(string $sessionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT session_id, campaign_id, visitor_uuid, fp_js, page_url, started_at, last_at,
                    chunk_count, event_count, bytes, country, device, ip, os, browser, referer,
                    (last_event_ms - first_event_ms) AS duration_ms
             FROM stats.rrweb_sessions WHERE session_id = :s',
            ['s' => $sessionId],
        );
    }

    /**
     * Concatenated, seq-ordered rrweb events for a session.
     * @return array<int,mixed>
     */
    public function events(string $sessionId): array
    {
        // Order by arrival time then seq: a multi-page visit shares one
        // session_id, and each page-load's chunks restart seq at 0, so seq alone
        // would interleave pages — created_at keeps them in navigation order.
        $rows = $this->db->fetchAll(
            "SELECT encode(payload,'base64') AS b64
             FROM stats.rrweb_chunks
             WHERE session_id = :s
             ORDER BY created_at, seq",
            ['s' => $sessionId],
        );
        $all = [];
        foreach ($rows as $row) {
            $gz = base64_decode((string) $row['b64'], true);
            if ($gz === false) {
                continue;
            }
            $json = @gzdecode($gz);
            if ($json === false) {
                continue;
            }
            $events = json_decode($json, true);
            if (is_array($events)) {
                foreach ($events as $e) {
                    $all[] = $e;
                }
            }
        }
        return $all;
    }
}
