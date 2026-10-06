<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ClickRepository;
use App\Mcp\ReportFilters;

final class TrafficBreakdownTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly ClickRepository $clicks,
    ) {}

    public function name(): string
    {
        return 'traffic_breakdown';
    }

    public function description(): string
    {
        return 'Clicks grouped by one dimension, with unique clicks, conversions, approved revenue and CR per row, '
            . 'largest first. One dimension per call; combine with the filters to drill down '
            . '(for example dimension=offer with country=ar).';
    }

    public function inputSchema(): array
    {
        return Schema::object(
            ['dimension' => ['type' => 'string', 'enum' => self::dimensions()]]
            + ReportFilters::properties()
            + ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReportFilters::MAX_LIMIT, 'description' => 'Rows, default 50.']],
            ['dimension'],
        );
    }

    public function call(array $args): array
    {
        $dimension = $args['dimension'] ?? null;
        if (!is_string($dimension) || !in_array($dimension, self::dimensions(), true)) {
            throw new ToolError('"dimension" must be one of: ' . implode(', ', self::dimensions()) . '.');
        }
        $f = $this->filters->fromArgs($args);
        $rows = array_map(static fn (array $r): array => $r + [
            'cr_percent' => $r['clicks'] > 0 ? round($r['approved'] / $r['clicks'] * 100, 2) : 0.0,
        ], $this->clicks->breakdown($dimension, $f, $this->filters->limit($args)));
        return ['period' => $this->filters->period($f), 'dimension' => $dimension, 'rows' => $rows];
    }

    /** @return list<string> `campaign` stays internal: list_campaigns is the tool for that. */
    private static function dimensions(): array
    {
        return array_values(array_diff(array_keys(ClickRepository::BREAKDOWN_DIMENSIONS), ['campaign']));
    }
}
