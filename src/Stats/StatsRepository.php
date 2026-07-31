<?php

declare(strict_types=1);

namespace App\Stats;

use App\Shared\Db\Connection;
use App\Shared\Referer\SearchEngine;

final class StatsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Time-series: clicks per hour for a campaign (or all) in given window.
     * @return list<array{hour:string, clicks:int, uniq:int, bot:int}>
     */
    public function clicksTimeline(?string $campaignId, string $sinceIso): array
    {
        $params = ['since' => $sinceIso];
        $where = 'WHERE hour >= :since';
        if ($campaignId !== null) {
            $where .= ' AND campaign_id = :cid';
            $params['cid'] = $campaignId;
        }
        $rows = $this->db->fetchAll(
            "SELECT to_char(hour, 'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    sum(clicks)::int       AS clicks,
                    sum(clicks_uniq)::int  AS uniq,
                    sum(clicks_bot)::int   AS bot
             FROM stats.clicks_hourly
             {$where}
             GROUP BY hour
             ORDER BY hour",
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
     * Top-line KPIs for a window: total clicks, unique clicks, conversions, approved-payout.
     * @return array{clicks:int, uniq:int, bots:int, conversions:int, approved:int, payout:string, cr:float, epc:float}
     */
    public function summary(?string $campaignId, string $sinceIso): array
    {
        $cidWhere = $campaignId !== null ? 'AND campaign_id = :cid' : '';
        $clickRow = $this->db->fetchOne(
            "SELECT COALESCE(sum(clicks), 0)::int AS clicks,
                    COALESCE(sum(clicks_uniq), 0)::int AS uniq,
                    COALESCE(sum(clicks_bot), 0)::int  AS bots
             FROM stats.clicks_hourly WHERE hour >= :since {$cidWhere}",
            $campaignId !== null ? ['since' => $sinceIso, 'cid' => $campaignId] : ['since' => $sinceIso],
        ) ?? ['clicks' => 0, 'uniq' => 0, 'bots' => 0];

        $convRow = $this->db->fetchOne(
            "SELECT COALESCE(sum(conv), 0)::int          AS conversions,
                    COALESCE(sum(conv_approved), 0)::int AS approved,
                    COALESCE(sum(payout), 0)::text       AS payout
             FROM core.conversions_hourly WHERE hour >= :since {$cidWhere}",
            $campaignId !== null ? ['since' => $sinceIso, 'cid' => $campaignId] : ['since' => $sinceIso],
        ) ?? ['conversions' => 0, 'approved' => 0, 'payout' => '0'];

        $clicks = (int)$clickRow['clicks'];
        $approved = (int)$convRow['approved'];
        $cr = $clicks > 0 ? round($approved / $clicks * 100, 2) : 0.0;
        $epc = $clicks > 0 ? round((float)$convRow['payout'] / max(1, $clicks), 4) : 0.0;
        return [
            'clicks'      => $clicks,
            'uniq'        => (int)$clickRow['uniq'],
            'bots'        => (int)$clickRow['bots'],
            'conversions' => (int)$convRow['conversions'],
            'approved'    => $approved,
            'payout'      => (string)$convRow['payout'],
            'cr'          => $cr,
            'epc'         => $epc,
        ];
    }

    /**
     * KPIs for non-bot clicks attributed to a known search/AI entry source.
     *
     * @return array{clicks:int, uniq:int, bots:int, conversions:int, approved:int, payout:string, cr:float, epc:float}
     */
    public function searchSummary(?string $campaignId, string $sinceIso): array
    {
        [$refWhere, $refParams] = SearchEngine::sqlFilterCompact(
            'any',
            SearchEngine::clickEntryRefererSql('c'),
            'search',
        );
        $campaignWhere = $campaignId !== null ? 'AND c.campaign_id = :cid' : '';
        $params = ['since' => $sinceIso] + $refParams;
        if ($campaignId !== null) {
            $params['cid'] = $campaignId;
        }

        $clickRow = $this->db->fetchOne(
            "SELECT count(DISTINCT c.visitor_uuid)::int AS clicks,
                    count(DISTINCT c.visitor_uuid)::int AS uniq
             FROM stats.clicks c
             WHERE c.created_at >= :since
               AND c.is_bot = false
               AND {$refWhere}
               {$campaignWhere}",
            $params,
        ) ?? ['clicks' => 0, 'uniq' => 0];

        $convRow = $this->db->fetchOne(
            "SELECT count(cv.id)::int AS conversions,
                    count(cv.id) FILTER (WHERE cv.status = 'approved')::int AS approved,
                    COALESCE(sum(cv.payout) FILTER (WHERE cv.status = 'approved'), 0)::text AS payout
             FROM core.conversions cv
             JOIN stats.clicks c ON c.id = cv.click_id
             WHERE cv.created_at >= :since
               AND c.is_bot = false
               AND {$refWhere}
               {$campaignWhere}",
            $params,
        ) ?? ['conversions' => 0, 'approved' => 0, 'payout' => '0'];

        $clicks = (int)$clickRow['clicks'];
        $approved = (int)$convRow['approved'];
        return [
            'clicks'      => $clicks,
            'uniq'        => (int)$clickRow['uniq'],
            'bots'        => 0,
            'conversions' => (int)$convRow['conversions'],
            'approved'    => $approved,
            'payout'      => (string)$convRow['payout'],
            'cr'          => $clicks > 0 ? round($approved / $clicks * 100, 2) : 0.0,
            'epc'         => $clicks > 0 ? round((float)$convRow['payout'] / $clicks, 4) : 0.0,
        ];
    }

    /**
     * @return list<array{hour:string, clicks:int, uniq:int, bot:int}>
     */
    public function searchClicksTimeline(?string $campaignId, string $sinceIso): array
    {
        [$refWhere, $refParams] = SearchEngine::sqlFilterCompact(
            'any',
            SearchEngine::clickEntryRefererSql('c'),
            'search',
        );
        $campaignWhere = $campaignId !== null ? 'AND c.campaign_id = :cid' : '';
        $params = ['since' => $sinceIso] + $refParams;
        if ($campaignId !== null) {
            $params['cid'] = $campaignId;
        }

        $rows = $this->db->fetchAll(
            "SELECT to_char(date_trunc('hour', first_click.created_at), 'YYYY-MM-DD\"T\"HH24:00:00\"Z\"') AS hour,
                    count(*)::int AS clicks,
                    count(*)::int AS uniq
             FROM (
                 SELECT DISTINCT ON (c.visitor_uuid) c.created_at
                 FROM stats.clicks c
                 WHERE c.created_at >= :since
                   AND c.is_bot = false
                   AND {$refWhere}
                   {$campaignWhere}
                 ORDER BY c.visitor_uuid, c.created_at
             ) first_click
             GROUP BY date_trunc('hour', first_click.created_at)
             ORDER BY date_trunc('hour', first_click.created_at)",
            $params,
        );

        return array_map(static fn ($r) => [
            'hour'   => (string)$r['hour'],
            'clicks' => (int)$r['clicks'],
            'uniq'   => (int)$r['uniq'],
            'bot'    => 0,
        ], $rows);
    }

    public function refreshClicksHourly(): void
    {
        try {
            $this->db->pdo->exec('REFRESH MATERIALIZED VIEW CONCURRENTLY stats.clicks_hourly');
        } catch (\PDOException $e) {
            // First refresh after migration must be non-concurrent (matview must be populated first)
            $this->db->pdo->exec('REFRESH MATERIALIZED VIEW stats.clicks_hourly');
        }
    }
}
