<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\MacroExpander;
use App\Postback\PostbackController;
use App\Postback\PostbackOutbox;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');

    $this->db   = new Connection($pdo);
    $cRepo      = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo = new OfferRepository($this->db);

    $outbox     = new PostbackOutbox($this->db, $this->oRepo, new MacroExpander());
    $this->ctrl = new PostbackController(
        $this->oRepo,
        $cRepo,
        $this->db,
        new TelegramNotifier(null, null),
        $outbox,
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );

    $this->camp  = $cRepo->create(['name' => 'PB Campaign', 'slug' => 'pb01', 'is_active' => '1']);
    $this->offer = $this->oRepo->create([
        'name'      => 'PB Offer',
        'url'       => 'https://example.com/',
        'is_active' => '1',
    ]);

    // Insert a click row directly for the current month partition.
    // visitor_uuid and ip are NOT NULL. offer_id is set to the click's served
    // offer (as the engine does on the redirect path) so offer-token postbacks
    // are validated against the offer the click was actually routed to.
    $pdo->exec(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip)
         VALUES (uuidv7(), '{$this->camp->id}', '{$this->offer->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
});

function pbRequest(array $params): \Psr\Http\Message\ServerRequestInterface
{
    $uri = '/postback?' . http_build_query($params);
    return (new ServerRequestFactory())->createServerRequest('GET', $uri);
}

// Helper to get (and cache per test) a stable click id inserted in beforeEach
function clickId(object $test): string
{
    /** @var object{db: Connection} $test */
    return (string)$test->db->fetchScalar(
        "SELECT id FROM stats.clicks ORDER BY created_at DESC LIMIT 1",
    );
}

test('happy path: postback creates conversion and returns ok+updated=false', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeFalse();

    $row = $this->db->fetchOne(
        'SELECT payout, status FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($row)->not->toBeNull();
    expect((float)$row['payout'])->toBe(5.5);
    expect($row['status'])->toBe('approved');
});

test('second postback updates existing row, updated=true, count stays 1', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    // First call
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']), new Response());

    // Second call
    $resp = ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '7.00', 'status' => 'approved']), new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeTrue();

    $count = (int)$this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($count)->toBe(1);

    $row = $this->db->fetchOne(
        'SELECT payout FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(7.0);
});

test('unknown token returns 404', function (): void {
    $cid = clickId($this);

    $req  = pbRequest(['subid' => $cid, 'token' => 'totally_invalid_token_xyz']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(404);
});

test('offer token rejects postback for a click routed to a different offer', function (): void {
    $cid = clickId($this);

    // A second global offer with its own token. The seeded click belongs to
    // $this->offer, so claiming it with a different offer's token must fail.
    $otherOffer = $this->oRepo->create([
        'name'      => 'Other Offer',
        'url'       => 'https://other.example/',
        'is_active' => '1',
    ]);

    $req  = pbRequest(['subid' => $cid, 'token' => $otherOffer->postbackToken, 'payout' => '9.99', 'status' => 'approved']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(409);

    // The victim click's conversion must not have been created/overwritten.
    $count = (int)$this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($count)->toBe(0);
});

test('shared offer accepts postback for click from any campaign', function (): void {
    $cid = clickId($this);

    // Same offer is reused by another campaign's flow; postback for camp1 click + same token works
    $db    = $this->db;
    $cRepo = new CampaignRepository($db, new CampaignIdGenerator());

    $camp2 = $cRepo->create(['name' => 'Other Camp', 'slug' => 'pb02', 'is_active' => '1']);
    // Note: $this->offer is global. We don't need to recreate.

    $req  = pbRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'payout' => '4.00']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne('SELECT campaign_id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($row['campaign_id'])->toBe($this->camp->id); // conversion attributed to click's campaign
});
