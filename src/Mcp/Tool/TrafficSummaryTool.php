<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ClickRepository;
use App\Mcp\ReportFilters;

final class TrafficSummaryTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly ClickRepository $clicks,
    ) {}

    public function name(): string
    {
        return 'traffic_summary';
    }

    public function description(): string
    {
        return 'KPIs for a period: clicks, unique visitors, conversions (with a per-status split: approved, '
            . 'pending, hold, rejected), approved revenue, CR and EPC, plus how many bot and trash clicks fall '
            . 'under the same filters. Call it twice to compare periods.';
    }

    public function inputSchema(): array
    {
        return Schema::object(ReportFilters::properties());
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs($args);
        $s = $this->clicks->summary($f);
        $clicks = $s['clicks'];
        return [
            'period'          => $this->filters->period($f),
            'clicks'          => $clicks,
            'unique_visitors' => $s['uniq_visitors'],
            'unique_fingerprints' => $s['uniq_fp'],
            'conversions'     => $s['conversions'],
            'approved'        => $s['approved'],
            'conversions_by_status' => $this->clicks->conversionStatuses($f),
            'revenue'         => $s['payout'],
            'cr_percent'      => $clicks > 0 ? round($s['approved'] / $clicks * 100, 2) : 0.0,
            'epc'             => $clicks > 0 ? round((float)$s['payout'] / $clicks, 4) : 0.0,
            'bot_clicks'      => $this->clicks->count(array_merge($f, ['bot_view' => 'only', 'is_trash' => 'all'])),
            'trash_clicks'    => $this->clicks->count(array_merge($f, ['is_trash' => 'only'])),
        ];
    }
}
