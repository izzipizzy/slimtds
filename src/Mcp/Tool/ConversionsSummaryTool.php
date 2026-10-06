<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ConversionRepository;
use App\Mcp\ReportFilters;

final class ConversionsSummaryTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly ConversionRepository $conversions,
    ) {}

    public function name(): string
    {
        return 'conversions_summary';
    }

    public function description(): string
    {
        return 'Conversions that arrived in the period, by status (approved, pending, hold, rejected) and by offer and '
            . 'campaign. Counted by postback arrival time and includes postbacks with no matching click, so totals can '
            . 'differ from traffic_summary, which counts conversions of the clicks made in the period.';
    }

    public function inputSchema(): array
    {
        return Schema::object(array_intersect_key(ReportFilters::properties(), array_flip(['period', 'from', 'to', 'campaign'])));
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs(array_intersect_key($args, array_flip(['period', 'from', 'to', 'campaign'])));
        $cf = array_intersect_key($f, array_flip(['campaign_id', 'from', 'to']));
        return [
            'period'      => $this->filters->period($f),
            'by_status'   => $this->conversions->statusBreakdown($cf),
            'by_offer'    => $this->conversions->revenueBy('offer', $cf),
            'by_campaign' => $this->conversions->revenueBy('campaign', $cf),
        ];
    }
}
