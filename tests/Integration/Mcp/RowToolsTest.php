<?php

declare(strict_types=1);

require_once __DIR__ . '/McpFixtures.php';

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\ConversionRepository;
use App\Admin\Repository\SettingsRepository;
use App\Mcp\ApiKeyService;
use App\Mcp\PixelReportRepository;
use App\Mcp\ReportFilters;
use App\Mcp\Tool\ConversionsSummaryTool;
use App\Mcp\Tool\ListClicksTool;
use App\Mcp\Tool\PixelSummaryTool;
use App\Mcp\Tool\ToolError;
use App\Mcp\Tool\VisitorJourneyTool;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    $this->db->execute("DELETE FROM core.settings WHERE key LIKE 'mcp_%'");
    mcpSeedTraffic($this->db);
    $this->clicks = new ClickRepository($this->db);
    $this->keys = new ApiKeyService(new SettingsRepository($this->db));
    $this->filters = new ReportFilters(new CampaignRepository($this->db, new CampaignIdGenerator()), $this->clicks);
});

test('list_clicks masks the ip by default and pages', function (): void {
    $r = (new ListClicksTool($this->filters, $this->clicks, $this->keys))->call(['campaign' => 'mcptst', 'period' => 'today', 'limit' => 3]);
    expect($r['total'])->toBe(4);
    expect($r['clicks'])->toHaveCount(3);
    expect($r['clicks'][0]['ip'])->toBe('203.0.113.0/24');
    expect($r['clicks'][0])->toHaveKeys(['id', 'at', 'campaign', 'offer', 'country', 'device', 'referer', 'user_agent', 'converted']);
    expect($r['has_more'])->toBeTrue();
});

test('list_clicks returns full addresses once the owner allows it', function (): void {
    $this->keys->setFullIp(true);
    $r = (new ListClicksTool($this->filters, $this->clicks, $this->keys))->call(['campaign' => 'mcptst', 'period' => 'today']);
    expect($r['clicks'][0]['ip'])->toBe('203.0.113.77');
});

test('visitor_journey orders pageviews, clicks and conversions of one visitor', function (): void {
    $tool = new VisitorJourneyTool($this->clicks);
    $r = $tool->call(['visitor_uuid' => MCP_VISITOR]);
    expect(array_count_values(array_column($r['events'], 'kind')))->toBe(['conversion' => 1, 'click' => 2, 'pageview' => 2]);
    expect(fn () => $tool->call([]))->toThrow(ToolError::class, 'visitor_uuid');
    expect(fn () => $tool->call(['visitor_uuid' => 'not-a-uuid']))->toThrow(ToolError::class, 'UUID');
});

test('visitor_journey by fp_js finds the same visitor\'s clicks and conversion', function (): void {
    $tool = new VisitorJourneyTool($this->clicks);
    $r = $tool->call(['fp_js' => 'fp-mcp-1']);
    expect(array_count_values(array_column($r['events'], 'kind')))->toBe(['conversion' => 1, 'click' => 2]);
});

test('conversions_summary: statuses and revenue by offer', function (): void {
    $r = (new ConversionsSummaryTool($this->filters, new ConversionRepository($this->db)))->call(['campaign' => 'mcptst', 'period' => 'today']);
    expect($r['by_status']['approved'])->toBe(['count' => 1, 'payout' => '10.50']);
    expect($r['by_status']['pending'])->toBe(['count' => 1, 'payout' => '7.00']);
    expect($r['by_offer'][0])->toBe(['label' => 'Offer A', 'conversions' => 1, 'approved' => 1, 'payout' => '10.50']);
    expect(json_encode($r))->not->toContain('raw_query');
});

test('pixel_summary: events, pages and the share of lander visitors who clicked', function (): void {
    $r = (new PixelSummaryTool($this->filters, new PixelReportRepository($this->db)))->call(['campaign' => 'mcptst', 'period' => 'today']);
    expect($r['events'][0])->toBe(['event' => 'pageview', 'events' => 3, 'visitors' => 2]);
    expect($r['top_pages'][0]['page'])->toBe('https://lander-a.test/');
    expect($r['funnel'])->toBe(['pixel_visitors' => 2, 'clicked_visitors' => 1, 'click_through_percent' => 50.0]);
});
