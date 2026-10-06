<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\PixelReportRepository;
use App\Mcp\ReportFilters;

final class PixelSummaryTool implements ToolInterface
{
    private const ARGS = ['period', 'from', 'to', 'campaign', 'country', 'bots'];

    public function __construct(
        private readonly ReportFilters $filters,
        private readonly PixelReportRepository $pixel,
    ) {}

    public function name(): string
    {
        return 'pixel_summary';
    }

    public function description(): string
    {
        return 'What happened on the landers before the click: pixel events by name, the most visited pages, and the '
            . 'share of lander visitors who went on to click into the campaign.';
    }

    public function inputSchema(): array
    {
        return Schema::object(array_intersect_key(ReportFilters::properties(), array_flip(self::ARGS)) + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReportFilters::MAX_LIMIT, 'description' => 'Pages, default 50.'],
        ]);
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs(array_intersect_key($args, array_flip(self::ARGS)));
        $funnel = $this->pixel->funnel($f);
        return [
            'period'    => $this->filters->period($f),
            'events'    => $this->pixel->eventsByName($f),
            'top_pages' => $this->pixel->topPages($f, $this->filters->limit($args)),
            'funnel'    => $funnel + [
                'click_through_percent' => $funnel['pixel_visitors'] > 0
                    ? round($funnel['clicked_visitors'] / $funnel['pixel_visitors'] * 100, 2) : 0.0,
            ],
        ];
    }
}
