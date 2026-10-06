<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Mcp\Tool\ToolError;
use App\Shared\Referer\SearchEngine;
use App\Shared\Time\DateRange;

/** Tool arguments in, the filter array ClickRepository already understands out. */
final class ReportFilters
{
    /** Days back from today; today and yesterday are single days. */
    public const PERIODS = ['today' => 0, 'yesterday' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
    private const VIEW = ['exclude' => 'hide', 'include' => 'all', 'only' => 'only'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly ClickRepository $clicks,
    ) {}

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function fromArgs(array $args): array
    {
        [$from, $to] = $this->range($args);
        $out = [
            'from'     => $from,
            'to'       => $to,
            'bot_view' => $this->view($args, 'bots'),
            'is_trash' => $this->view($args, 'trash'),
        ];
        if (is_string($args['campaign'] ?? null) && $args['campaign'] !== '') {
            $out['campaign_id'] = $this->campaignId($args['campaign']);
        }
        foreach (['country' => 'country', 'device' => 'device'] as $arg => $key) {
            if (is_string($args[$arg] ?? null) && $args[$arg] !== '') {
                $out[$key] = $args[$arg];
            }
        }
        if (is_string($args['entry_source'] ?? null) && $args['entry_source'] !== '') {
            $out['entry_ref'] = $this->entrySource($args['entry_source']);
        }
        return $out;
    }

    /** @param array<string,mixed> $args */
    public function limit(array $args): int
    {
        return max(1, min(self::MAX_LIMIT, (int)($args['limit'] ?? 50)));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{from:string, to:string, timezone:string}
     */
    public function period(array $filters): array
    {
        return ['from' => (string)$filters['from'], 'to' => (string)$filters['to'], 'timezone' => date_default_timezone_get()];
    }

    public function campaignId(string $ref): string
    {
        $c = preg_match(self::UUID, $ref) === 1 ? $this->campaigns->findById($ref) : $this->campaigns->findBySlug($ref);
        if ($c === null) throw new ToolError("No campaign \"{$ref}\". Call list_campaigns for the valid slugs.");
        return $c->id;
    }

    /** @return array<string,array<string,mixed>> JSON Schema properties shared by the report tools */
    public static function properties(): array
    {
        return [
            'period'       => ['type' => 'string', 'enum' => array_keys(self::PERIODS), 'description' => 'Rolling period, default 7d. Ignored when from/to are given.'],
            'from'         => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD, instance time zone.'],
            'to'           => ['type' => 'string', 'description' => 'Last day inclusive, YYYY-MM-DD.'],
            'campaign'     => ['type' => 'string', 'description' => 'Campaign slug, alias or UUID. Omit for all campaigns.'],
            'country'      => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2 code.'],
            'device'       => ['type' => 'string', 'description' => 'Device class as reported by traffic_breakdown dimension=device.'],
            'entry_source' => ['type' => 'string', 'enum' => self::entrySourceValues(), 'description' => 'Search engine the visitor originally came from: any, none, or an engine key such as google.'],
            'bots'         => ['type' => 'string', 'enum' => array_keys(self::VIEW), 'description' => 'Default exclude.'],
            'trash'        => ['type' => 'string', 'enum' => array_keys(self::VIEW), 'description' => 'Clicks that matched no flow. Default exclude.'],
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0:string, 1:string}
     */
    private function range(array $args): array
    {
        foreach (['from', 'to'] as $k) {
            if (isset($args[$k]) && DateRange::day($args[$k]) === null) {
                throw new ToolError("\"{$k}\" must be a real date in YYYY-MM-DD form.");
            }
        }
        $from = DateRange::day($args['from'] ?? null);
        $to   = DateRange::day($args['to'] ?? null);
        if ($from === null && $to === null) {
            $period = $args['period'] ?? '7d';
            if (!is_string($period) || !array_key_exists($period, self::PERIODS)) {
                throw new ToolError('"period" must be one of: ' . implode(', ', array_keys(self::PERIODS)) . '.');
            }
            $days = self::PERIODS[$period];
            $from = DateRange::daysAgo($days);
            $to   = $days === 1 ? $from : DateRange::today();
        }
        $to ??= DateRange::today();
        $from ??= (new \DateTimeImmutable($to))->modify('-7 days')->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $earliest = $this->clicks->earliestDay();
        if ($earliest !== null && $from < $earliest) {
            $from = min($earliest, $to);
        }
        return [$from, $to];
    }

    /** @param array<string,mixed> $args */
    private function view(array $args, string $key): string
    {
        $v = $args[$key] ?? 'exclude';
        return is_string($v) && isset(self::VIEW[$v])
            ? self::VIEW[$v]
            : throw new ToolError("\"{$key}\" must be one of: exclude, include, only.");
    }

    /**
     * `SearchEngine::sqlFilter()` silently no-ops on a value it doesn't
     * recognise, which used to let a typo'd or wrongly-cased engine name
     * (e.g. "Google") through as if the filter had matched everything —
     * the caller can't tell "no filter" from "filter matched nothing".
     * Validate here so an unknown value is a loud error instead.
     */
    private function entrySource(string $v): string
    {
        return in_array($v, self::entrySourceValues(), true)
            ? $v
            : throw new ToolError('"entry_source" must be any, none, or one of: ' . implode(', ', SearchEngine::keys()) . '.');
    }

    /** @return list<string> */
    private static function entrySourceValues(): array
    {
        return array_merge(['any', 'none'], SearchEngine::keys());
    }
}
