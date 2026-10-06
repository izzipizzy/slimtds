<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ClickRepository;
use App\Mcp\ApiKeyService;
use App\Mcp\IpMask;
use App\Mcp\ReportFilters;

final class ListClicksTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly ClickRepository $clicks,
        private readonly ApiKeyService $keys,
    ) {}

    public function name(): string
    {
        return 'list_clicks';
    }

    public function description(): string
    {
        return 'Individual clicks, newest first, under the same filters as the reports. For checking a hypothesis on '
            . 'real rows, not for counting — use traffic_summary and traffic_breakdown for numbers.';
    }

    public function inputSchema(): array
    {
        return Schema::object(ReportFilters::properties() + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReportFilters::MAX_LIMIT, 'description' => 'Rows per page, default 50.'],
            'page'  => ['type' => 'integer', 'minimum' => 1, 'description' => 'Default 1.'],
        ]);
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs($args);
        $limit = $this->filters->limit($args);
        $page = max(1, (int)($args['page'] ?? 1));
        $full = $this->keys->fullIp();
        $total = $this->clicks->count($f);
        $rows = array_map(static fn (array $r): array => [
            'id'           => (string)$r['id'],
            'at'           => (string)$r['created_at'],
            'campaign'     => $r['campaign_slug'],
            'flow'         => $r['flow_name'],
            'offer'        => $r['offer_name'],
            'visitor_uuid' => (string)$r['visitor_uuid'],
            'fp_js'        => $r['fp_js'],
            'ip'           => $full ? $r['ip'] : IpMask::mask($r['ip']),
            'country'      => $r['country'],
            'city'         => $r['city'],
            'asn'          => $r['asn'],
            'isp'          => $r['isp'],
            'device'       => $r['device'],
            'os'           => $r['os'],
            'browser'      => $r['browser'],
            'is_bot'       => (bool)$r['is_bot'],
            'bot_name'     => $r['bot_name'],
            'is_uniq'      => (bool)$r['is_uniq'],
            'referer'      => $r['referer'],
            'entry_referer' => $r['entry_referer'],
            'utm_source'   => $r['utm_source'],
            'lander'       => $r['lander_host'],
            'lander_button' => $r['lander_button'],
            'out_url'      => $r['out_url'],
            'user_agent'   => $r['user_agent'],
            'converted'    => (bool)$r['has_conversion'],
        ], $this->clicks->page($page, $limit, $f));
        return [
            'period'   => $this->filters->period($f),
            'total'    => $total,
            'page'     => $page,
            'has_more' => $page * $limit < $total,
            'clicks'   => $rows,
        ];
    }
}
