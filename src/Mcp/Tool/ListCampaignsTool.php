<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\FlowRepository;
use App\Mcp\ReportFilters;

final class ListCampaignsTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly CampaignRepository $campaigns,
        private readonly FlowRepository $flows,
        private readonly ClickRepository $clicks,
    ) {}

    public function name(): string
    {
        return 'list_campaigns';
    }

    public function description(): string
    {
        return 'Every campaign with its slug, status, number of flows and clicks in the period. Start here: '
            . 'the slug is what the other tools take as "campaign".';
    }

    public function inputSchema(): array
    {
        return Schema::object(array_intersect_key(ReportFilters::properties(), array_flip(['period', 'from', 'to'])));
    }

    public function call(array $args): array
    {
        $f = $this->filters->fromArgs(array_intersect_key($args, array_flip(['period', 'from', 'to'])));
        $all = $this->campaigns->page(1, 1000);
        $flowCounts = $this->flows->countsByCampaign(array_map(static fn ($c) => $c->id, $all));
        $clicks = array_column($this->clicks->breakdown('campaign', $f, 1000), null, 'label');
        $rows = [];
        foreach ($all as $c) {
            $rows[] = [
                'id'        => $c->id,
                'slug'      => $c->slug,
                'name'      => $c->name,
                'is_active' => $c->isActive,
                'flows'     => (int)($flowCounts[$c->id] ?? 0),
                'clicks'    => (int)($clicks[$c->slug]['clicks'] ?? 0),
                'approved'  => (int)($clicks[$c->slug]['approved'] ?? 0),
                'revenue'   => (string)($clicks[$c->slug]['payout'] ?? '0'),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
        return ['period' => $this->filters->period($f), 'campaigns' => $rows];
    }
}
