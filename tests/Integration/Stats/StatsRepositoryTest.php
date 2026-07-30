<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Stats\StatsRepository;

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('TRUNCATE stats.clicks, stats.pixel_events, core.conversions CASCADE');
    $this->db = new Connection($this->pdo);
    $this->repo = new StatsRepository($this->db);
});

test('search stats use pixel entry source and exclude direct traffic and bots', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $pixelVisitor = '00000000-0000-7000-8000-000000000011';

    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, created_at)
         VALUES (:campaign, :visitor, 'https://www.google.com/search?q=loan', now() - interval '1 minute')",
        ['campaign' => $campaign, 'visitor' => $pixelVisitor],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot)
         VALUES
            (:campaign, :pixel_visitor, '1.1.1.1', 'https://lander.test/page', false),
            (:campaign, '00000000-0000-7000-8000-000000000012', '1.1.1.2', 'https://www.bing.com/search?q=loan', false),
            (:campaign, '00000000-0000-7000-8000-000000000013', '1.1.1.3', 'https://www.google.com/search?q=loan', true),
            (:campaign, '00000000-0000-7000-8000-000000000014', '1.1.1.4', 'https://lander.test/page', false)",
        ['campaign' => $campaign, 'pixel_visitor' => $pixelVisitor],
    );

    $summary = $this->repo->searchSummary($campaign, date('c', time() - 3600));
    $timeline = $this->repo->searchClicksTimeline($campaign, date('c', time() - 3600));

    expect($summary['clicks'])->toBe(2)
        ->and($summary['uniq'])->toBe(2)
        ->and($summary['bots'])->toBe(0)
        ->and(array_sum(array_column($timeline, 'clicks')))->toBe(2);
});
