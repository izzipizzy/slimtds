<?php

declare(strict_types=1);

require_once __DIR__ . '/McpFixtures.php';

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Mcp\ReportFilters;
use App\Mcp\Tool\GetCampaignTool;
use App\Mcp\Tool\ListCampaignsTool;
use App\Mcp\Tool\ToolError;
use App\Mcp\Tool\TrafficBreakdownTool;
use App\Mcp\Tool\TrafficSummaryTool;
use App\Mcp\Tool\TrafficTimelineTool;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $db = new Connection(pdo());
    mcpSeedTraffic($db);
    $this->db = $db;
    $this->campaigns = new CampaignRepository($db, new CampaignIdGenerator());
    $this->clicks = new ClickRepository($db);
    $this->flows = new FlowRepository($db);
    $this->offers = new OfferRepository($db);
    $this->filters = new ReportFilters($this->campaigns, $this->clicks);
});

test('traffic_summary separates real traffic, bots and trash and derives CR and EPC', function (): void {
    $r = (new TrafficSummaryTool($this->filters, $this->clicks))->call(['campaign' => 'mcptst', 'period' => 'today']);
    expect($r['clicks'])->toBe(4);
    expect($r['unique_visitors'])->toBe(3);
    expect($r['bot_clicks'])->toBe(1);
    expect($r['trash_clicks'])->toBe(1);
    expect($r['conversions'])->toBe(2);
    expect($r['approved'])->toBe(1);
    expect($r['revenue'])->toBe('10.50');
    expect($r['cr_percent'])->toBe(25.0);
    expect($r['epc'])->toBe(2.625);
    expect($r['period']['from'])->toBe(date('Y-m-d'));
    expect($r['conversions_by_status'])->toBe(['approved' => 1, 'pending' => 1, 'hold' => 0, 'rejected' => 0]);
    expect(array_sum($r['conversions_by_status']))->toBe($r['conversions']);
});

test('traffic_summary of an empty selection divides by nothing', function (): void {
    $r = (new TrafficSummaryTool($this->filters, $this->clicks))->call(['campaign' => 'mcptst', 'country' => 'zz']);
    expect([$r['clicks'], $r['cr_percent'], $r['epc']])->toBe([0, 0.0, 0.0]);
    expect($r['conversions_by_status'])->toBe(['approved' => 0, 'pending' => 0, 'hold' => 0, 'rejected' => 0]);
});

test('traffic_timeline is hourly for a short period and carries today\'s clicks', function (): void {
    $r = (new TrafficTimelineTool($this->filters, $this->clicks))->call(['campaign' => 'mcptst', 'period' => 'today']);
    expect($r['step'])->toBe('hour');
    expect(array_sum(array_column($r['buckets'], 'clicks')))->toBe(4);
});

test('traffic_breakdown adds CR per row and rejects an unknown dimension', function (): void {
    $tool = new TrafficBreakdownTool($this->filters, $this->clicks);
    $r = $tool->call(['dimension' => 'country', 'campaign' => 'mcptst', 'period' => 'today']);
    expect($r['rows'][0])->toBe(['label' => 'ar', 'clicks' => 2, 'uniq' => 1, 'conversions' => 1, 'approved' => 1, 'payout' => '10.50', 'cr_percent' => 50.0]);
    expect(fn () => $tool->call(['dimension' => 'shoe_size']))->toThrow(ToolError::class, 'dimension');
    expect($tool->inputSchema()['properties']['dimension']['enum'])->not->toContain('campaign');
});

test('list_campaigns reports clicks in the period', function (): void {
    $r = (new ListCampaignsTool($this->filters, $this->campaigns, $this->flows, $this->clicks))->call(['period' => 'today']);
    $row = array_values(array_filter($r['campaigns'], fn ($c) => $c['slug'] === 'mcptst'))[0];
    expect($row['clicks'])->toBe(4);
    expect($row['is_active'])->toBeTrue();
    expect($row)->not->toHaveKey('postback_token');
});

test('get_campaign never exposes the postback token', function (): void {
    $r = (new GetCampaignTool($this->filters, $this->campaigns, $this->flows, $this->offers))->call(['campaign' => 'mcptst']);
    expect($r['slug'])->toBe('mcptst');
    expect($r['flows'])->toBe([]);
    expect(json_encode($r))->not->toContain('postback');
});

test('get_campaign labels trash modes 4-7 same as the admin UI', function (): void {
    $this->db->execute('UPDATE core.campaigns SET trash_mode = 4 WHERE id = :id', ['id' => MCP_CAMPAIGN]);
    $r = (new GetCampaignTool($this->filters, $this->campaigns, $this->flows, $this->offers))->call(['campaign' => 'mcptst']);
    expect($r['trash_mode'])->toBe('301 redirect to trash url');
});
