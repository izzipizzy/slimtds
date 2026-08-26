<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\SettingsRepository;
use App\Cron\Command\InboxFlushCommand;
use App\Engine\BotDetector;
use App\Engine\DeviceDetector;
use App\Engine\GeoLookup;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM stats.pixel_events_inbox');
    $this->pdo->exec('DELETE FROM stats.pixel_events');
    $this->pdo->exec('DELETE FROM core.campaigns');

    $this->db = new Connection($this->pdo);
    $repo     = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->camp = $repo->create(['name' => 'Flush Eref', 'slug' => 'fer01', 'is_active' => '1']);

    $this->cmd = new InboxFlushCommand(
        $this->db,
        new GeoLookup(),
        new DeviceDetector(),
        new BotDetector($this->db),
        new TelegramNotifier(null, null),
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );
});

function stageInboxEvent(array $payload): void
{
    // A real IP: the flush runs geo and bot detection, and those query with an
    // ::inet cast. This is the ordinary production shape — RealIp falls back to
    // 0.0.0.0 rather than nothing. A NULL ip is still reachable (an invalid
    // REMOTE_ADDR is not validated before filter_var drops it), and flush turns
    // NULL into '' which the bot queries reject; that is a pre-existing defect
    // tracked separately, not something this fixture papers over.
    test()->db->execute(
        "INSERT INTO stats.pixel_events_inbox (payload, ip, user_agent, visitor_uuid)
         VALUES (:p::jsonb, :ip::inet, 'Mozilla/5.0 Test', :vu)",
        [
            'p'  => json_encode($payload),
            'ip' => '203.0.113.7',
            'vu' => '55555555-5555-7555-8555-555555555555',
        ],
    );
}

test('eref from the inbox lands in stats.pixel_events.entry_referer', function (): void {
    stageInboxEvent([
        'campaign_id' => $this->camp->id,
        'event'       => 'pageview',
        'url'         => 'https://lander.example/inner',
        'ref'         => 'https://lander.example/',
        'eref'        => 'https://news.ycombinator.com/',
    ]);

    expect($this->cmd->tick())->toBe(1);

    $row = $this->db->fetchOne(
        'SELECT referer, entry_referer FROM stats.pixel_events ORDER BY created_at DESC LIMIT 1',
    );
    // The two are kept apart on purpose: referer is this page, entry_referer the visit.
    expect($row['referer'])->toBe('https://lander.example/');
    expect($row['entry_referer'])->toBe('https://news.ycombinator.com/');
});

test('an older payload without eref flushes with a NULL entry_referer', function (): void {
    stageInboxEvent([
        'campaign_id' => $this->camp->id,
        'event'       => 'pageview',
        'url'         => 'https://lander.example/',
        'ref'         => null,
    ]);

    expect($this->cmd->tick())->toBe(1);

    $row = $this->db->fetchOne(
        'SELECT entry_referer FROM stats.pixel_events ORDER BY created_at DESC LIMIT 1',
    );
    expect($row['entry_referer'])->toBeNull();
});
