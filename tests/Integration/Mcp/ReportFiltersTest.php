<?php

declare(strict_types=1);

require_once __DIR__ . '/McpFixtures.php';

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Mcp\ReportFilters;
use App\Mcp\Tool\ToolError;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Time\DateRange;

beforeEach(function (): void {
    $db = new Connection(pdo());
    mcpSeedTraffic($db);
    $this->f = new ReportFilters(new CampaignRepository($db, new CampaignIdGenerator()), new ClickRepository($db));
});

test('defaults: last 7 days, bots and trash excluded', function (): void {
    $f = $this->f->fromArgs([]);
    expect($f['bot_view'])->toBe('hide');
    expect($f['is_trash'])->toBe('hide');
    expect($f['to'])->toBe(DateRange::today());
    expect($f)->not->toHaveKey('campaign_id');
});

test('from is clamped to the first day on record', function (): void {
    expect($this->f->fromArgs(['period' => '90d'])['from'])->toBe(DateRange::today());
});

test('today and yesterday are single days', function (): void {
    $y = $this->f->fromArgs(['period' => 'yesterday']);
    expect($y['to'])->toBe(DateRange::daysAgo(1));
});

test('explicit days win over the period', function (): void {
    $f = $this->f->fromArgs(['period' => '30d', 'from' => DateRange::today(), 'to' => DateRange::today()]);
    expect([$f['from'], $f['to']])->toBe([DateRange::today(), DateRange::today()]);
});

test('campaign resolves by slug and by uuid', function (): void {
    expect($this->f->fromArgs(['campaign' => 'mcptst'])['campaign_id'])->toBe(MCP_CAMPAIGN);
    expect($this->f->fromArgs(['campaign' => MCP_CAMPAIGN])['campaign_id'])->toBe(MCP_CAMPAIGN);
});

test('bad input is a ToolError the model can read', function (array $args, string $needle): void {
    expect(fn () => $this->f->fromArgs($args))->toThrow(ToolError::class, $needle);
})->with([
    [['campaign' => 'nosuch'], 'list_campaigns'],
    [['period' => 'forever'], 'period'],
    [['from' => '2026-02-31'], 'YYYY-MM-DD'],
    [['bots' => 'maybe'], 'bots'],
    [['entry_source' => 'Google'], 'entry_source'],
]);

test('bots and trash map to the repository vocabulary', function (): void {
    $f = $this->f->fromArgs(['bots' => 'only', 'trash' => 'include', 'country' => 'AR', 'entry_source' => 'google']);
    expect([$f['bot_view'], $f['is_trash'], $f['country'], $f['entry_ref']])->toBe(['only', 'all', 'AR', 'google']);
});

test('entry_source also accepts the any and none pseudo-values', function (): void {
    expect($this->f->fromArgs(['entry_source' => 'any'])['entry_ref'])->toBe('any');
    expect($this->f->fromArgs(['entry_source' => 'none'])['entry_ref'])->toBe('none');
});

test('entry_source schema enum lists any, none and a real engine key', function (): void {
    $enum = ReportFilters::properties()['entry_source']['enum'];
    expect($enum)->toContain('any', 'none', 'google');
});

test('limit is bounded', function (): void {
    expect($this->f->limit([]))->toBe(50);
    expect($this->f->limit(['limit' => 5000]))->toBe(200);
    expect($this->f->limit(['limit' => 0]))->toBe(1);
});
