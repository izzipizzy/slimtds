<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ClickRepository;
use App\Mcp\ReportFilters;
use App\Shared\Time\TimelineWindow;

final class TrafficTimelineTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly ClickRepository $clicks,
    ) {}

    public function name(): string
    {
        return 'traffic_timeline';
    }

    public function description(): string
    {
        return 'Clicks, unique clicks and bot clicks per time bucket. Hourly for periods up to 8 days, daily beyond. '
            . 'Use it to find when a change happened. Set bots=include to see the bot series.';
    }

    public function inputSchema(): array
    {
        return Schema::object(ReportFilters::properties());
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs($args);
        $window = TimelineWindow::fromFilters($f, 7);
        $buckets = array_map(static fn (array $b): array => [
            'at' => $b['hour'], 'clicks' => $b['clicks'], 'uniq' => $b['uniq'], 'bot' => $b['bot'],
        ], $this->clicks->timeline($f, $window));
        return ['period' => $this->filters->period($f), 'step' => $window->step, 'buckets' => $buckets];
    }
}
