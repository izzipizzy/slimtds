<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Shared\Db\Connection;
use App\Shared\Time\DateRange;

/** Aggregates over stats.pixel_events. The admin UI lists events; nothing there groups them. */
final class PixelReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $f
     * @return list<array{event:string, events:int, visitors:int}>
     */
    public function eventsByName(array $f): array
    {
        [$where, $params] = $this->where($f);
        return array_map(static fn (array $r): array => [
            'event' => (string)$r['event'], 'events' => (int)$r['events'], 'visitors' => (int)$r['visitors'],
        ], $this->db->fetchAll(
            "SELECT pe.event_name AS event, count(*) AS events, count(DISTINCT pe.visitor_uuid) AS visitors
             FROM stats.pixel_events pe {$where} GROUP BY 1 ORDER BY events DESC, event",
            $params,
        ));
    }

    /**
     * @param array<string,mixed> $f
     * @return list<array{page:string, events:int, visitors:int}>
     */
    public function topPages(array $f, int $limit): array
    {
        [$where, $params] = $this->where($f);
        $params['lim'] = $limit;
        return array_map(static fn (array $r): array => [
            'page' => (string)$r['page'], 'events' => (int)$r['events'], 'visitors' => (int)$r['visitors'],
        ], $this->db->fetchAll(
            "SELECT COALESCE(split_part(pe.page_url, '?', 1), '(none)') AS page,
                    count(*) AS events, count(DISTINCT pe.visitor_uuid) AS visitors
             FROM stats.pixel_events pe {$where} GROUP BY 1 ORDER BY events DESC, page LIMIT :lim",
            $params,
        ));
    }

    /**
     * Lander visitors, and how many of them have a click in the same campaign
     * since the period began.
     *
     * @param array<string,mixed> $f
     * @return array{pixel_visitors:int, clicked_visitors:int}
     */
    public function funnel(array $f): array
    {
        [$where, $params] = $this->where($f);
        $row = $this->db->fetchOne(
            "SELECT count(DISTINCT pe.visitor_uuid) AS pixel_visitors,
                    count(DISTINCT pe.visitor_uuid) FILTER (WHERE EXISTS (
                        SELECT 1 FROM stats.clicks c
                        WHERE c.visitor_uuid = pe.visitor_uuid
                          AND c.campaign_id = pe.campaign_id
                          AND c.created_at >= :from::timestamptz
                          AND c.created_at < :to_excl::timestamptz
                    )) AS clicked_visitors
             FROM stats.pixel_events pe {$where}",
            $params,
        ) ?? [];
        return ['pixel_visitors' => (int)($row['pixel_visitors'] ?? 0), 'clicked_visitors' => (int)($row['clicked_visitors'] ?? 0)];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private function where(array $f): array
    {
        $cond = ['pe.created_at >= :from::timestamptz', 'pe.created_at < :to_excl::timestamptz'];
        $params = ['from' => (string)$f['from'], 'to_excl' => DateRange::exclusiveEnd((string)$f['to'])];
        if (!empty($f['campaign_id'])) {
            $cond[] = 'pe.campaign_id = :cid';
            $params['cid'] = (string)$f['campaign_id'];
        }
        if (!empty($f['country'])) {
            $cond[] = 'pe.country = :country';
            $params['country'] = strtolower((string)$f['country']);
        }
        if (($f['bot_view'] ?? 'hide') === 'hide') {
            $cond[] = 'pe.is_bot = false';
        } elseif ($f['bot_view'] === 'only') {
            $cond[] = 'pe.is_bot = true';
        }
        return ['WHERE ' . implode(' AND ', $cond), $params];
    }
}
