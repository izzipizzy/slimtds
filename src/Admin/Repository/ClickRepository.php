<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;
use App\Shared\Referer\SearchEngine;

final class ClickRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{campaign_id?:?string, country?:?string, device?:?string, is_bot?:?bool, is_uniq?:?bool, since?:?string, is_trash?:?string, search?:?string, ip?:?string, click_id?:?string, visitor?:?string, fp_js?:?string} $filters
     * @return list<array<string,mixed>>
     */
    /**
     * @param array{campaign_id?:?string, country?:?string, device?:?string, is_bot?:?bool, is_uniq?:?bool, since?:?string, is_trash?:?string, search?:?string, ip?:?string, click_id?:?string, visitor?:?string, fp_js?:?string} $filters
     * @param string $orderBy SQL fragment, must come from a whitelist (e.g. "c.created_at DESC")
     * @return list<array<string,mixed>>
     */
    public function page(int $page, int $perPage, array $filters = [], string $orderBy = 'c.created_at DESC'): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        [$where, $params] = $this->buildWhere($filters);
        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        return $this->db->fetchAll(
            "SELECT c.id, c.campaign_id, c.flow_id, c.offer_id, c.visitor_uuid,
                    host(c.ip) AS ip, c.country, c.region, c.city, c.asn, c.isp,
                    c.device, c.os, c.browser, c.lang,
                    c.is_bot, c.bot_name, c.is_uniq, c.user_agent, c.referer,
                    c.utm_source, c.utm_medium, c.utm_campaign,
                    c.schema_id, c.out_url, c.http_status, c.created_at,
                    c.lander_host, c.lander_button, c.fp_js,
                    cmp.slug AS campaign_slug, cmp.name AS campaign_name,
                    o.name AS offer_name,
                    fl.name AS flow_name,
                    er.entry_referer,
                    (EXISTS (SELECT 1 FROM core.conversions cv WHERE cv.click_id = c.id))::int AS has_conversion
             FROM stats.clicks c
             LEFT JOIN core.campaigns cmp ON cmp.id = c.campaign_id
             LEFT JOIN core.offers o      ON o.id   = c.offer_id
             LEFT JOIN core.flows fl      ON fl.id  = c.flow_id
             -- Entry source the pixel recorded for this visitor, shown ALONGSIDE
             -- c.referer, never replacing it. Earliest matching event wins: that
             -- is the one carrying the external source, unlike the most recent
             -- one, which is typically an in-lander navigation.
             LEFT JOIN LATERAL (
                 SELECT pe.entry_referer
                 FROM stats.pixel_events pe
                 WHERE pe.visitor_uuid = c.visitor_uuid
                   AND pe.entry_referer IS NOT NULL
                   AND pe.created_at >= c.created_at - interval '24 hours'
                   AND pe.created_at <= c.created_at + interval '5 minutes'
                 ORDER BY pe.created_at
                 LIMIT 1
             ) er ON true
             {$where}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset",
            $params,
        );
    }

    /** @param array<string,mixed> $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        return (int)$this->db->fetchScalar(
            "SELECT count(*) FROM stats.clicks c {$where}",
            $params,
        );
    }

    /**
     * Hourly buckets for the 48-hour window ending at the current hour.
     * Re-applies the user's current filters (campaign/country/device/bot/uniq/routing)
     * but ignores any 'since' override — the window is always [now()−47h, now()].
     * Empty hours are filled with zeros via generate_series + LEFT JOIN.
     *
     * @param array<string,mixed> $filters
     * @return list<array{hour:string, clicks:int, uniq:int, bot:int}>
     */
    public function hourlyTimeline(array $filters): array
    {
        [$extraConds, $params] = $this->filterConditionsNoTime($filters);
        $extra = $extraConds ? ' AND ' . implode(' AND ', $extraConds) : '';

        $rows = $this->db->fetchAll(
            <<<SQL
                WITH hours AS (
                    SELECT generate_series(
                        date_trunc('hour', now()) - interval '47 hours',
                        date_trunc('hour', now()),
                        interval '1 hour'
                    ) AS hour
                ),
                agg AS (
                    SELECT date_trunc('hour', c.created_at)    AS hour,
                           count(*)                            AS clicks,
                           count(*) FILTER (WHERE c.is_uniq)   AS uniq,
                           count(*) FILTER (WHERE c.is_bot)    AS bot
                    FROM stats.clicks c
                    WHERE c.created_at >= date_trunc('hour', now()) - interval '47 hours'
                      AND c.created_at <  date_trunc('hour', now()) + interval '1 hour'
                      {$extra}
                    GROUP BY 1
                )
                SELECT to_char(h.hour, 'YYYY-MM-DD"T"HH24:00:00') AS hour,
                       COALESCE(a.clicks, 0)::int AS clicks,
                       COALESCE(a.uniq,   0)::int AS uniq,
                       COALESCE(a.bot,    0)::int AS bot
                FROM hours h
                LEFT JOIN agg a USING (hour)
                ORDER BY h.hour
                SQL,
            $params,
        );
        return array_map(static fn ($r) => [
            'hour'   => (string)$r['hour'],
            'clicks' => (int)$r['clicks'],
            'uniq'   => (int)$r['uniq'],
            'bot'    => (int)$r['bot'],
        ], $rows);
    }

    /**
     * First pixel-event referer of a visitor — that's the actual entry point
     * (a click's referer is just the lander page where the CTA was). Returns
     * raw URL + an engine key from SearchEngine::classify, or null if we have
     * no pixel events for them at all.
     *
     * @return array{ref:string, source:?string}|null
     */
    public function entrySource(string $visitorUuid, int $sinceDays = 30): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT referer
             FROM stats.pixel_events
             WHERE visitor_uuid = :v AND created_at >= now() - make_interval(days => :days)
             ORDER BY created_at ASC
             LIMIT 1",
            ['v' => $visitorUuid, 'days' => $sinceDays],
        );
        if ($row === null) return null;
        $ref = (string)($row['referer'] ?? '');
        return ['ref' => $ref, 'source' => SearchEngine::classify($ref !== '' ? $ref : null)];
    }

    /**
     * Aggregate counters for a visitor over a window. Used in the visitor card
     * shown above the journey timeline.
     *
     * @return array{pageviews:int, clicks:int, conversions:int, first_seen:?string}
     */
    public function visitorTotals(string $visitorUuid, int $sinceDays = 30): array
    {
        $row = $this->db->fetchOne(
            "WITH p AS (
                SELECT count(*) AS n, min(created_at) AS first_seen
                FROM stats.pixel_events
                WHERE visitor_uuid = :v AND created_at >= now() - make_interval(days => :days)
             ), c AS (
                SELECT count(*) AS n, min(created_at) AS first_seen
                FROM stats.clicks
                WHERE visitor_uuid = :v AND created_at >= now() - make_interval(days => :days)
             ), v AS (
                SELECT count(*) AS n
                FROM core.conversions cv JOIN stats.clicks ck ON ck.id = cv.click_id
                WHERE ck.visitor_uuid = :v
             )
             SELECT (SELECT n FROM p) AS pageviews,
                    (SELECT n FROM c) AS clicks,
                    (SELECT n FROM v) AS conversions,
                    LEAST((SELECT first_seen FROM p), (SELECT first_seen FROM c)) AS first_seen",
            ['v' => $visitorUuid, 'days' => $sinceDays],
        );
        return [
            'pageviews'   => (int)($row['pageviews']   ?? 0),
            'clicks'      => (int)($row['clicks']      ?? 0),
            'conversions' => (int)($row['conversions'] ?? 0),
            'first_seen'  => $row['first_seen'] !== null ? (string)$row['first_seen'] : null,
        ];
    }

    /**
     * Earliest pixel-event referer across every visitor_uuid that ever carried
     * this FingerprintJS id. Used by the visitor card when the operator drills
     * in by fp_js — a fingerprint can outlive the cookie, so the "entry" is
     * defined as the first pixel hit known for the same browser.
     *
     * @return array{ref:string, source:?string}|null
     */
    public function entrySourceByFp(string $fpJs, int $sinceDays = 30): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT referer
             FROM stats.pixel_events
             WHERE fp_js = :fp AND created_at >= now() - make_interval(days => :days)
             ORDER BY created_at ASC
             LIMIT 1",
            ['fp' => $fpJs, 'days' => $sinceDays],
        );
        if ($row === null) return null;
        $ref = (string)($row['referer'] ?? '');
        return ['ref' => $ref, 'source' => SearchEngine::classify($ref !== '' ? $ref : null)];
    }

    /**
     * Aggregate counters across every visitor_uuid that shares this fp_js.
     *
     * @return array{pageviews:int, clicks:int, conversions:int, first_seen:?string}
     */
    public function visitorTotalsByFp(string $fpJs, int $sinceDays = 30): array
    {
        $row = $this->db->fetchOne(
            "WITH p AS (
                SELECT count(*) AS n, min(created_at) AS first_seen
                FROM stats.pixel_events
                WHERE fp_js = :fp AND created_at >= now() - make_interval(days => :days)
             ), c AS (
                SELECT count(*) AS n, min(created_at) AS first_seen
                FROM stats.clicks
                WHERE fp_js = :fp AND created_at >= now() - make_interval(days => :days)
             ), v AS (
                SELECT count(*) AS n
                FROM core.conversions cv JOIN stats.clicks ck ON ck.id = cv.click_id
                WHERE ck.fp_js = :fp
             )
             SELECT (SELECT n FROM p) AS pageviews,
                    (SELECT n FROM c) AS clicks,
                    (SELECT n FROM v) AS conversions,
                    LEAST((SELECT first_seen FROM p), (SELECT first_seen FROM c)) AS first_seen",
            ['fp' => $fpJs, 'days' => $sinceDays],
        );
        return [
            'pageviews'   => (int)($row['pageviews']   ?? 0),
            'clicks'      => (int)($row['clicks']      ?? 0),
            'conversions' => (int)($row['conversions'] ?? 0),
            'first_seen'  => $row['first_seen'] !== null ? (string)$row['first_seen'] : null,
        ];
    }

    /**
     * Time-ordered journey by FingerprintJS id — same shape as visitorJourney()
     * but matches on fp_js (clicks + pixel events both carry it natively;
     * conversions are joined via clicks.fp_js).
     *
     * @return list<array<string,mixed>>
     */
    public function visitorJourneyByFp(string $fpJs, int $limit = 80, int $sinceDays = 30): array
    {
        return $this->db->fetchAll(
            "(
                SELECT 'pageview' AS kind, p.created_at AS at,
                       p.page_url AS detail, p.referer AS ref,
                       NULL::uuid AS click_id, NULL::text AS slug, NULL::text AS lander_host,
                       NULL::text AS lander_button, NULL::text AS offer, NULL::text AS status, NULL::numeric AS payout
                FROM stats.pixel_events p
                WHERE p.fp_js = :fp AND p.created_at >= now() - make_interval(days => :days)
             )
             UNION ALL
             (
                SELECT 'click' AS kind, c.created_at AS at,
                       c.out_url AS detail, c.referer AS ref,
                       c.id AS click_id, cmp.slug AS slug, c.lander_host AS lander_host,
                       c.lander_button AS lander_button, o.name AS offer, NULL::text AS status, NULL::numeric AS payout
                FROM stats.clicks c
                LEFT JOIN core.campaigns cmp ON cmp.id = c.campaign_id
                LEFT JOIN core.offers o ON o.id = c.offer_id
                WHERE c.fp_js = :fp AND c.created_at >= now() - make_interval(days => :days)
             )
             UNION ALL
             (
                SELECT 'conversion' AS kind, cv.created_at AS at,
                       cv.external_id AS detail, NULL::text AS ref,
                       cv.click_id AS click_id, cmp.slug AS slug, NULL::text AS lander_host,
                       NULL::text AS lander_button, o.name AS offer, cv.status AS status, cv.payout AS payout
                FROM core.conversions cv
                JOIN stats.clicks ck ON ck.id = cv.click_id
                LEFT JOIN core.campaigns cmp ON cmp.id = cv.campaign_id
                LEFT JOIN core.offers o ON o.id = cv.offer_id
                WHERE ck.fp_js = :fp
             )
             ORDER BY at DESC
             LIMIT :lim",
            ['fp' => $fpJs, 'days' => $sinceDays, 'lim' => $limit],
        );
    }

    /**
     * Time-ordered timeline of pixel events + clicks + conversions for a visitor.
     * Each row has a discriminator `kind` ∈ {'pageview','click','conversion'} +
     * kind-specific columns merged into a single shape suitable for one table.
     *
     * @return list<array<string,mixed>>
     */
    public function visitorJourney(string $visitorUuid, int $limit = 80, int $sinceDays = 30): array
    {
        return $this->db->fetchAll(
            "(
                SELECT 'pageview' AS kind, p.created_at AS at,
                       p.page_url AS detail, p.referer AS ref,
                       NULL::uuid AS click_id, NULL::text AS slug, NULL::text AS lander_host,
                       NULL::text AS lander_button, NULL::text AS offer, NULL::text AS status, NULL::numeric AS payout
                FROM stats.pixel_events p
                WHERE p.visitor_uuid = :v AND p.created_at >= now() - make_interval(days => :days)
             )
             UNION ALL
             (
                SELECT 'click' AS kind, c.created_at AS at,
                       c.out_url AS detail, c.referer AS ref,
                       c.id AS click_id, cmp.slug AS slug, c.lander_host AS lander_host,
                       c.lander_button AS lander_button, o.name AS offer, NULL::text AS status, NULL::numeric AS payout
                FROM stats.clicks c
                LEFT JOIN core.campaigns cmp ON cmp.id = c.campaign_id
                LEFT JOIN core.offers o ON o.id = c.offer_id
                WHERE c.visitor_uuid = :v AND c.created_at >= now() - make_interval(days => :days)
             )
             UNION ALL
             (
                SELECT 'conversion' AS kind, cv.created_at AS at,
                       cv.external_id AS detail, NULL::text AS ref,
                       cv.click_id AS click_id, cmp.slug AS slug, NULL::text AS lander_host,
                       NULL::text AS lander_button, o.name AS offer, cv.status AS status, cv.payout AS payout
                FROM core.conversions cv
                JOIN stats.clicks ck ON ck.id = cv.click_id
                LEFT JOIN core.campaigns cmp ON cmp.id = cv.campaign_id
                LEFT JOIN core.offers o ON o.id = cv.offer_id
                WHERE ck.visitor_uuid = :v
             )
             ORDER BY at DESC
             LIMIT :lim",
            ['v' => $visitorUuid, 'days' => $sinceDays, 'lim' => $limit],
        );
    }

    /**
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        [$cond, $params] = $this->filterConditionsNoTime($filters);
        // Pinpoint a single click by id (used to deep-link from /admin/conversions).
        // Bypasses the default time window AND the hide-trash filter — once you have
        // the id you want the row regardless of where it landed.
        if (!empty($filters['click_id'])) {
            $cond = array_values(array_filter($cond, static fn (string $c): bool => $c !== 'c.flow_id IS NOT NULL'));
            $cond[] = 'c.id = :click_id';
            $params['click_id'] = (string)$filters['click_id'];
            $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
            return [$where, $params];
        }
        // Pinpoint a visitor by uuid (deep-link from /admin/pixel). Same as
        // click_id: bypass the hide-trash filter and the default time window so
        // the converting click shows up regardless of routing/age.
        if (!empty($filters['visitor'])) {
            $cond = array_values(array_filter($cond, static fn (string $c): bool => $c !== 'c.flow_id IS NOT NULL'));
            $cond[] = 'c.visitor_uuid = :visitor';
            $params['visitor'] = (string)$filters['visitor'];
            $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
            return [$where, $params];
        }
        // Time-window optimization for partition pruning — caller can pass 'since' as ISO string
        if (!empty($filters['since'])) {
            $cond[] = 'c.created_at >= :since';
            $params['since'] = (string)$filters['since'];
        } else {
            // Default 7-day window — uses BRIN index efficiently
            $cond[] = "c.created_at >= now() - interval '7 days'";
        }
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        return [$where, $params];
    }

    /**
     * Build the non-time portion of the WHERE clause. Used by both the list
     * query (which adds its own `since`) and the hourly-timeline query (which
     * pins the window to the last 48 hours).
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private function filterConditionsNoTime(array $filters): array
    {
        $cond = [];
        $params = [];
        if (!empty($filters['campaign_id'])) {
            $cond[] = 'c.campaign_id = :cid';
            $params['cid'] = (string)$filters['campaign_id'];
        }
        if (!empty($filters['country'])) {
            $cond[] = 'c.country = :country';
            $params['country'] = strtolower((string)$filters['country']);
        }
        if (!empty($filters['device'])) {
            $cond[] = 'c.device = :device';
            $params['device'] = (string)$filters['device'];
        }
        // Bot view: 'hide' (default — non-bots only), 'all' (include bots),
        // 'only' (bots only). Legacy 'is_bot' boolean kept for backward compat.
        $botView = $filters['bot_view'] ?? null;
        if ($botView === 'hide') {
            $cond[] = 'c.is_bot = false';
        } elseif ($botView === 'only') {
            $cond[] = 'c.is_bot = true';
        } elseif (isset($filters['is_bot']) && $filters['is_bot'] !== null) {
            $cond[] = 'c.is_bot = :is_bot';
            $params['is_bot'] = $filters['is_bot'] ? 'true' : 'false';
        }
        if (isset($filters['is_uniq']) && $filters['is_uniq'] !== null) {
            $cond[] = 'c.is_uniq = :is_uniq';
            $params['is_uniq'] = $filters['is_uniq'] ? 'true' : 'false';
        }
        // Routing filter — clicks with flow_id IS NULL fell through to the
        // campaign trash mode (no flow matched). Default 'hide' keeps the list
        // focused on actual routed traffic; 'only' shows just fallthroughs.
        $routing = $filters['is_trash'] ?? 'hide';
        if ($routing === 'hide') {
            $cond[] = 'c.flow_id IS NOT NULL';
        } elseif ($routing === 'only') {
            $cond[] = 'c.flow_id IS NULL';
        }
        // Search-engine referer filter (any | <engine> | none)
        if (!empty($filters['search'])) {
            [$frag, $bind] = SearchEngine::sqlFilter((string)$filters['search'], 'c.referer', 'se');
            if ($frag !== '') {
                $cond[] = $frag;
                $params = array_merge($params, $bind);
            }
        }
        // Entry-source filter (any | <engine> | none) — OPT-IN, never a default.
        // Matches the entry referer the pixel recorded for the same visitor
        // instead of the click's own Referer header, which behind a server-side
        // go.php redirect is the lander itself. Written as EXISTS so it works
        // identically in page() and count(), and costs nothing when unset.
        if (!empty($filters['entry_ref'])) {
            [$frag, $bind] = SearchEngine::sqlFilter((string)$filters['entry_ref'], 'pe.entry_referer', 'eref');
            if ($frag !== '') {
                $cond[] = "EXISTS (
                    SELECT 1 FROM stats.pixel_events pe
                    WHERE pe.visitor_uuid = c.visitor_uuid
                      AND pe.entry_referer IS NOT NULL
                      AND pe.created_at >= c.created_at - interval '24 hours'
                      AND pe.created_at <= c.created_at + interval '5 minutes'
                      AND {$frag}
                )";
                $params = array_merge($params, $bind);
            }
        }
        // IP substring match — typed in the filter bar (full IP or a prefix).
        if (!empty($filters['ip'])) {
            $cond[] = 'host(c.ip) ILIKE :ip';
            $params['ip'] = '%' . (string)$filters['ip'] . '%';
        }
        // FingerprintJS visitor id — exact match (filter chip from a row, or
        // hand-typed in the filter bar to follow the same fingerprint across
        // /admin/clicks and /admin/pixel).
        if (!empty($filters['fp_js'])) {
            $cond[] = 'c.fp_js = :fp_js';
            $params['fp_js'] = (string)$filters['fp_js'];
        }
        // fp_js presence filter — '1' = with fp, '0' = no fp.
        if (isset($filters['fp_js_has']) && $filters['fp_js_has'] !== null) {
            $cond[] = $filters['fp_js_has'] === '1' ? 'c.fp_js IS NOT NULL' : 'c.fp_js IS NULL';
        }
        return [$cond, $params];
    }
}
