<?php

declare(strict_types=1);

namespace App\Pixel;

use App\Shared\Db\Connection;
use App\Shared\Referer\SearchEngine;
use App\Shared\Time\DateRange;
use App\Shared\Time\TimelineWindow;

final class PixelEventRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function recentForCampaign(string $campaignId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT id, visitor_uuid, fp_js, event_name, page_url, host(ip) AS ip,
                    country, city, asn, screen_w, screen_h, timezone, lang, props, created_at
             FROM stats.pixel_events
             WHERE campaign_id = :c
               AND created_at >= now() - interval '7 days'
             ORDER BY created_at DESC
             LIMIT :lim",
            ['c' => $campaignId, 'lim' => $limit],
        );
    }

    /** @return array{day:int, week:int, month:int} */
    public function countsForCampaign(string $campaignId): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                count(*) FILTER (WHERE created_at >= now() - interval '24 hours')  AS d,
                count(*) FILTER (WHERE created_at >= now() - interval '7 days')    AS w,
                count(*) FILTER (WHERE created_at >= now() - interval '30 days')   AS m
             FROM stats.pixel_events
             WHERE campaign_id = :c",
            ['c' => $campaignId],
        ) ?? ['d' => 0, 'w' => 0, 'm' => 0];
        return [
            'day'   => (int)$row['d'],
            'week'  => (int)$row['w'],
            'month' => (int)$row['m'],
        ];
    }

    // ── Cross-campaign methods ────────────────────────────────────────────────

    /**
     * Paginated cross-campaign pixel events with enriched campaign info.
     *
     * @param array{campaign_id?:?string, event_name?:?string, domain?:?string, country?:?string, since?:?string, from?:?string, to?:?string, search?:?string} $filters
     * @param string $orderBy SQL ORDER BY clause — caller must whitelist (e.g. via PixelColumnPreferences)
     * @return list<array<string,mixed>>
     */
    public function page(int $page, int $perPage, array $filters = [], string $orderBy = 'pe.created_at DESC'): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        [$where, $params] = $this->buildWhere($filters);
        $params['limit']  = $perPage;
        $params['offset'] = $offset;

        return $this->db->fetchAll(
            "SELECT pe.id, pe.campaign_id, pe.visitor_uuid, pe.fp_js, pe.event_name,
                    pe.page_url, pe.referer, host(pe.ip) AS ip, pe.country, pe.city, pe.asn,
                    pe.user_agent, pe.device, pe.os, pe.browser,
                    pe.screen_w, pe.screen_h, pe.timezone, pe.lang, pe.props, pe.created_at,
                    cmp.slug AS campaign_slug, cmp.name AS campaign_name,
                    (EXISTS (
                        SELECT 1 FROM core.conversions cv
                        JOIN stats.clicks ck ON ck.id = cv.click_id
                        WHERE ck.visitor_uuid = pe.visitor_uuid
                           OR (pe.fp_js IS NOT NULL AND ck.fp_js = pe.fp_js)
                    ))::int AS has_conversion
             FROM stats.pixel_events pe
             LEFT JOIN core.campaigns cmp ON cmp.id = pe.campaign_id
             {$where}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset",
            $params,
        );
    }

    /**
     * Total count for pagination.
     *
     * @param array{campaign_id?:?string, event_name?:?string, domain?:?string, country?:?string, since?:?string, from?:?string, to?:?string, search?:?string} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int)$this->db->fetchScalar(
            "SELECT count(*) FROM stats.pixel_events pe {$where}",
            $params,
        );
    }

    /** Day (APP_TZ) of the oldest event on record — the lower bound of the "all time" preset. */
    public function earliestDay(): ?string
    {
        $day = $this->db->fetchScalar('SELECT min(created_at)::date::text FROM stats.pixel_events');
        return is_string($day) && $day !== '' ? $day : null;
    }

    /**
     * KPI summary for the filtered window.
     *
     * @param array{campaign_id?:?string, event_name?:?string, domain?:?string, country?:?string, since?:?string, from?:?string, to?:?string, search?:?string} $filters
     * @return array{events:int, uniq_visitors:int, uniq_fp:int, distinct_event_types:int}
     */
    public function summary(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $this->db->fetchOne(
            "SELECT count(*)                                                      AS events,
                    count(DISTINCT pe.visitor_uuid)                               AS uniq_visitors,
                    count(DISTINCT pe.fp_js) FILTER (WHERE pe.fp_js IS NOT NULL)  AS uniq_fp,
                    count(DISTINCT pe.event_name)                                 AS distinct_event_types
             FROM stats.pixel_events pe
             {$where}",
            $params,
        ) ?? [];
        return [
            'events'               => (int)($row['events']               ?? 0),
            'uniq_visitors'        => (int)($row['uniq_visitors']        ?? 0),
            'uniq_fp'              => (int)($row['uniq_fp']              ?? 0),
            'distinct_event_types' => (int)($row['distinct_event_types'] ?? 0),
        ];
    }

    /**
     * Top N source-page hosts by event count.
     *
     * @param array{campaign_id?:?string, event_name?:?string, domain?:?string, country?:?string, since?:?string, from?:?string, to?:?string, search?:?string} $filters
     * @return list<array{host:string, events:int}>
     */
    public function topDomains(array $filters = [], int $limit = 10): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $params['limit'] = $limit;

        // Extend WHERE with page_url NOT NULL condition
        $whereExtra = $where === ''
            ? "WHERE pe.page_url IS NOT NULL"
            : $where . ' AND pe.page_url IS NOT NULL';

        /** @var list<array{host:string,events:int}> */
        return $this->db->fetchAll(
            "SELECT regexp_replace(pe.page_url, '^https?://([^/]+).*', '\\1') AS host,
                    count(*) AS events
             FROM stats.pixel_events pe
             {$whereExtra}
             GROUP BY host
             ORDER BY events DESC
             LIMIT :limit",
            $params,
        );
    }

    /**
     * Top N event names by count.
     *
     * @param array{campaign_id?:?string, event_name?:?string, domain?:?string, country?:?string, since?:?string, from?:?string, to?:?string, search?:?string} $filters
     * @return list<array{event_name:string, events:int}>
     */
    public function topEventNames(array $filters = [], int $limit = 10): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $params['limit'] = $limit;

        /** @var list<array{event_name:string,events:int}> */
        return $this->db->fetchAll(
            "SELECT pe.event_name, count(*) AS events
             FROM stats.pixel_events pe
             {$where}
             GROUP BY pe.event_name
             ORDER BY events DESC
             LIMIT :limit",
            $params,
        );
    }

    /**
     * Chart buckets over the same period and the same filters as the list under
     * it — hourly, or daily once the period is wider than
     * TimelineWindow::HOURLY_MAX_DAYS. The bucket start stays under the 'hour'
     * key whatever the step.
     *
     * @param array<string,mixed> $filters
     * @return list<array{hour:string, events:int, uniq:int}>
     */
    public function timeline(array $filters, TimelineWindow $window): array
    {
        [$cond, $params] = $this->filterConditionsNoTime($filters);
        $params += $window->params;
        $step = $window->step;
        $extra = $cond ? ' AND ' . implode(' AND ', $cond) : '';

        $rows = $this->db->fetchAll(
            <<<SQL
                WITH hours AS (
                    SELECT generate_series(
                        {$window->startSql},
                        {$window->endSql} - interval '1 {$step}',
                        interval '1 {$step}'
                    ) AS hour
                ),
                agg AS (
                    SELECT date_trunc('{$step}', pe.created_at) AS hour,
                           count(*)                          AS events,
                           count(DISTINCT pe.visitor_uuid)   AS uniq
                    FROM stats.pixel_events pe
                    WHERE pe.created_at >= {$window->startSql}
                      AND pe.created_at <  {$window->endSql}
                      {$extra}
                    GROUP BY 1
                )
                SELECT to_char(h.hour, 'YYYY-MM-DD"T"HH24:00:00') AS hour,
                       COALESCE(a.events, 0)::int AS events,
                       COALESCE(a.uniq,   0)::int AS uniq
                FROM hours h
                LEFT JOIN agg a USING (hour)
                ORDER BY h.hour
                SQL,
            $params,
        );
        return array_map(static fn ($r) => [
            'hour'   => (string)$r['hour'],
            'events' => (int)$r['events'],
            'uniq'   => (int)$r['uniq'],
        ], $rows);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build WHERE clause and params array for cross-campaign queries.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        [$cond, $params] = $this->filterConditionsNoTime($filters);

        // Time-window for partition pruning; default 7 days. The filter bar's
        // day range (from/to, inclusive) wins over the legacy ISO 'since'.
        $from = DateRange::day($filters['from'] ?? null);
        $to   = DateRange::day($filters['to'] ?? null);
        if ($from !== null) {
            $cond[]         = 'pe.created_at >= :from::timestamptz';
            $params['from'] = $from;
        } elseif (!empty($filters['since'])) {
            $cond[]          = 'pe.created_at >= :since';
            $params['since'] = (string)$filters['since'];
        } else {
            $cond[] = "pe.created_at >= now() - interval '7 days'";
        }
        if ($to !== null) {
            $cond[]            = 'pe.created_at < :to_excl::timestamptz';
            $params['to_excl'] = DateRange::exclusiveEnd($to);
        }

        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        return [$where, $params];
    }

    /**
     * Everything but the time window — shared by the list queries (which add
     * their own window) and the chart (which brings a TimelineWindow), so the
     * chart can never be filtered differently from the list under it.
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private function filterConditionsNoTime(array $filters): array
    {
        $cond   = [];
        $params = [];

        if (!empty($filters['campaign_id'])) {
            $cond[]              = 'pe.campaign_id = :cid';
            $params['cid']       = (string)$filters['campaign_id'];
        }
        if (!empty($filters['event_name'])) {
            $cond[]               = 'pe.event_name = :event_name';
            $params['event_name'] = (string)$filters['event_name'];
        }
        if (!empty($filters['domain'])) {
            // Exact host match (was ILIKE '%domain%'): hits idx_pixel_events_host
            // (btree on the same regexp_replace expr) instead of a lossy gin_trgm
            // heap-recheck over every candidate row.
            $cond[]           = "regexp_replace(pe.page_url, '^https?://([^/]+).*', '\\1') = :domain";
            $params['domain'] = (string)$filters['domain'];
        }
        if (!empty($filters['search'])) {
            [$frag, $bind] = SearchEngine::sqlFilter((string)$filters['search'], 'pe.referer', 'se');
            if ($frag !== '') {
                $cond[] = $frag;
                $params = array_merge($params, $bind);
            }
        }
        // IP substring match — typed in the filter bar (full IP or a prefix).
        if (!empty($filters['ip'])) {
            $cond[]        = 'host(pe.ip) ILIKE :ip';
            $params['ip']  = '%' . (string)$filters['ip'] . '%';
        }
        if (!empty($filters['country'])) {
            $cond[]            = 'pe.country = :country';
            $params['country'] = strtolower((string)$filters['country']);
        }
        if (!empty($filters['fp_js'])) {
            $cond[]          = 'pe.fp_js = :fp_js';
            $params['fp_js'] = (string)$filters['fp_js'];
        }
        // Bot view: 'hide' (humans only — default), 'all', 'only'.
        $botView = $filters['bot_view'] ?? null;
        if ($botView === 'hide') {
            $cond[] = 'pe.is_bot = false';
        } elseif ($botView === 'only') {
            $cond[] = 'pe.is_bot = true';
        }

        return [$cond, $params];
    }
}
