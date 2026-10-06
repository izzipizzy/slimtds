<?php

declare(strict_types=1);

require_once __DIR__ . '/McpFixtures.php';

use App\Admin\Repository\ClickRepository;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    mcpSeedTraffic($this->db);
    $this->repo = new ClickRepository($this->db);
    $this->real = ['campaign_id' => MCP_CAMPAIGN, 'bot_view' => 'hide', 'is_trash' => 'hide'];
});

test('by country: routed human clicks with their conversions', function (): void {
    expect($this->repo->breakdown('country', $this->real))->toBe([
        ['label' => 'ar', 'clicks' => 2, 'uniq' => 1, 'conversions' => 1, 'approved' => 1, 'payout' => '10.50'],
        ['label' => 'cl', 'clicks' => 1, 'uniq' => 1, 'conversions' => 1, 'approved' => 0, 'payout' => '0'],
        ['label' => 'de', 'clicks' => 1, 'uniq' => 0, 'conversions' => 0, 'approved' => 0, 'payout' => '0'],
    ]);
});

test('referer_domain strips the url down to the host and groups the rest as (none)', function (): void {
    $rows = array_column($this->repo->breakdown('referer_domain', $this->real), 'clicks', 'label');
    expect($rows)->toBe(['(none)' => 2, 'www.google.com' => 2]);
});

test('offer and bot_name dimensions', function (): void {
    expect(array_column($this->repo->breakdown('offer', $this->real), 'clicks', 'label'))->toBe(['Offer A' => 3, 'Offer B' => 1]);
    $bots = $this->repo->breakdown('bot_name', ['campaign_id' => MCP_CAMPAIGN, 'bot_view' => 'only', 'is_trash' => 'all']);
    expect(array_column($bots, 'clicks', 'label'))->toBe(['Googlebot' => 1]);
});

test('limit is honoured and an unknown dimension is refused', function (): void {
    expect($this->repo->breakdown('country', $this->real, 1))->toHaveCount(1);
    expect(fn () => $this->repo->breakdown('ip; DROP TABLE', $this->real))->toThrow(\InvalidArgumentException::class);
});
