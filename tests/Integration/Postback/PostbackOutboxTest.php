<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\MacroExpander;
use App\Postback\PostbackOutbox;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.postback_deliveries');
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');

    $this->db     = new Connection($pdo);
    $cRepo        = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo  = new OfferRepository($this->db);
    $this->outbox = new PostbackOutbox($this->db, $this->oRepo, new MacroExpander());

    $this->camp  = $cRepo->create(['name' => 'OB Campaign', 'slug' => 'ob01', 'is_active' => '1']);

    // Offer with two postback URLs containing macros
    $this->offer = $this->oRepo->create([
        'name'          => 'OB Offer',
        'url'           => 'https://example.com/',
        'is_active'     => '1',
        'postback_urls' => [
            'https://network.example.com/cb?cid={click_id}&payout={payout}&status={status}',
            'https://other.net/postback?sub={click_id}&ext={external_id}&cur={currency}',
        ],
    ]);

    // Insert a click so we can create a conversion
    $pdo->exec(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip)
         VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );

    $clickId = (string)$this->db->fetchScalar(
        'SELECT id FROM stats.clicks ORDER BY created_at DESC LIMIT 1',
    );

    // Insert a conversion row to FK-reference from postback_deliveries
    $convRow = $this->db->fetchOne(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency)
         VALUES (:cid, :camp, :offer, '5.50', 'approved', 'USD')
         RETURNING id",
        ['cid' => $clickId, 'camp' => $this->camp->id, 'offer' => $this->offer->id],
    );
    $this->conversionId = (string)$convRow['id'];
    $this->clickId      = $clickId;
});

test('enqueue creates one delivery row per postback URL with macros expanded', function (): void {
    $n = $this->outbox->enqueue($this->conversionId, $this->offer->id, [
        'click_id'    => $this->clickId,
        'payout'      => '5.50',
        'status'      => 'approved',
        'external_id' => 'EXT123',
        'currency'    => 'USD',
    ]);

    expect($n)->toBe(2);

    $rows = $this->db->fetchAll(
        'SELECT target_url, attempts, delivered_at FROM core.postback_deliveries ORDER BY created_at ASC',
    );

    expect($rows)->toHaveCount(2);

    // First URL — click_id, payout, status macros
    $url1 = $rows[0]['target_url'];
    expect($url1)->toContain('cid=' . $this->clickId);
    expect($url1)->toContain('payout=5.50');
    expect($url1)->toContain('status=approved');
    expect($url1)->not->toContain('{click_id}');
    expect($url1)->not->toContain('{payout}');

    // Second URL — external_id, currency macros
    $url2 = $rows[1]['target_url'];
    expect($url2)->toContain('ext=EXT123');
    expect($url2)->toContain('cur=USD');
    expect($url2)->toContain('sub=' . $this->clickId);

    // Both rows should be pending (not delivered, attempts=0)
    expect((int)$rows[0]['attempts'])->toBe(0);
    expect($rows[0]['delivered_at'])->toBeNull();
});

test('enqueue returns 0 for offer with no postback URLs', function (): void {
    $offerNoUrls = $this->oRepo->create([
        'name'      => 'NoURL Offer',
        'url'       => 'https://example.com/',
        'is_active' => '1',
    ]);

    $n = $this->outbox->enqueue($this->conversionId, $offerNoUrls->id, [
        'click_id' => $this->clickId,
        'payout'   => '1.00',
        'status'   => 'approved',
    ]);

    expect($n)->toBe(0);
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.postback_deliveries');
    expect($count)->toBe(0);
});

test('tick marks delivery as delivered when target returns 2xx', function (): void {
    // Check if slimtds.local is reachable from the app container
    $healthCheck = shell_exec('docker compose -f /Users/vid/code/slimTDS/docker-compose.yml -f /Users/vid/code/slimTDS/docker-compose.override.yml exec -T app curl -sk -o /dev/null -w "%{http_code}" https://slimtds.local/__health 2>/dev/null');
    if (trim((string)$healthCheck) !== '200') {
        $this->markTestSkipped('slimtds.local not reachable from test runner — skipping live delivery test');
    }

    // Insert a delivery row pointing at the health endpoint
    $this->db->execute(
        "INSERT INTO core.postback_deliveries (conversion_id, target_url, next_attempt_at)
         VALUES (:conv, 'https://slimtds.local/__health', now())",
        ['conv' => $this->conversionId],
    );

    $id = (string)$this->db->fetchScalar('SELECT id FROM core.postback_deliveries LIMIT 1');

    $processed = $this->outbox->tick(10);
    expect($processed)->toBeGreaterThanOrEqual(1);

    $row = $this->db->fetchOne(
        'SELECT delivered_at, last_status FROM core.postback_deliveries WHERE id = :id',
        ['id' => $id],
    );
    expect($row['delivered_at'])->not->toBeNull();
    expect((int)$row['last_status'])->toBe(200);
});

test('tick increments attempts and schedules retry on 5xx', function (): void {
    // Point at a URL that will definitely fail (unreachable)
    $this->db->execute(
        "INSERT INTO core.postback_deliveries (conversion_id, target_url, next_attempt_at)
         VALUES (:conv, 'http://192.0.2.1/nonexistent', now())",
        ['conv' => $this->conversionId],
    );

    $id = (string)$this->db->fetchScalar('SELECT id FROM core.postback_deliveries LIMIT 1');

    $this->outbox->tick(10);

    $row = $this->db->fetchOne(
        'SELECT attempts, delivered_at, next_attempt_at FROM core.postback_deliveries WHERE id = :id',
        ['id' => $id],
    );

    expect((int)$row['attempts'])->toBe(1);
    expect($row['delivered_at'])->toBeNull();
    // next_attempt_at should be in the future (≥ now)
    $nextTs = strtotime((string)$row['next_attempt_at']);
    expect($nextTs)->toBeGreaterThan(time());
});
