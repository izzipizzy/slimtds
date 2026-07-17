<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\BotDetector;
use App\Engine\ClickHandler;
use App\Engine\DeviceDetector;
use App\Engine\FilterCompiler;
use App\Engine\FlowMatcher;
use App\Engine\GeoLookup;
use App\Engine\MacroExpander;
use App\Engine\OfferPicker;
use App\Engine\Schema\SchemaRegistry;
use App\Engine\VisitorResolver;
use App\Shared\CampaignIdGenerator;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.visitors_fingerprints');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $oRepo = new OfferRepository($this->db);
    $fRepo = new FlowRepository($this->db);
    $compiler = new FilterCompiler();
    $this->handler = new ClickHandler(
        $cRepo, $oRepo,
        new VisitorResolver($this->db),
        new DeviceDetector(),
        new GeoLookup(),
        new BotDetector($this->db),
        new FlowMatcher($fRepo, $compiler),
        new OfferPicker(),
        new MacroExpander(),
        new SchemaRegistry(),
        $this->db,
        new TelegramNotifier(null, null),
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );

    $this->camp = $cRepo->create(['name' => 'Click test', 'slug' => 'clkt01', 'is_active' => '1']);
    $this->offer = $oRepo->create(['name' => 'O', 'url' => 'https://example.com/?c={country}&cid={click_id}', 'is_active' => '1']);
    $fRepo->create($this->camp->id, [
        'name' => 'all → offer',
        'filters' => [],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $this->offer->id, 'weight' => 100]],
        'schema_id' => 2,
        'is_active' => '1',
    ]);
});

test('valid slug routes to offer with 302 + macros expanded', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $req = $req->withParsedBody(null);
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://example.com/');
    expect($loc)->toContain('cid=');
});

test('unknown slug returns 404 (not trash 200)', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/nonexistent');
    $resp = $this->handler->handle($req, new Response(), 'nonexistent');
    expect($resp->getStatusCode())->toBe(404);
});

test('inactive campaign returns 404 regardless of trash_mode', function (): void {
    $this->db->execute('UPDATE core.campaigns SET is_active = false WHERE id = :id', ['id' => $this->camp->id]);
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    expect($resp->getStatusCode())->toBe(404);
});

test('trash {offer:name} redirects to the offer url, macro-expanded', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash offer', 'slug' => 'trsh01', 'is_active' => '1']);
    // No flow on this campaign → falls through to trash. Mode 1 = 302; the URL
    // references the offer by name, which must resolve to the offer's own URL.
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => '{offer:O}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh01'),
        new Response(),
        'trsh01',
    );
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://example.com/');
    expect($loc)->toContain('cid=');          // offer's {click_id} was expanded
    expect($loc)->not->toContain('{offer:');  // reference resolved, not passed through literally
});

test('trash {offer:missing} falls back to 204 (no leaked macro)', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash miss', 'slug' => 'trsh02', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => '{offer:NoSuchOffer}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh02'),
        new Response(),
        'trsh02',
    );
    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
});

test('trash plain url is macro-expanded', function (): void {
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $c2 = $cRepo->create(['name' => 'Trash url', 'slug' => 'trsh03', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :u WHERE id = :id',
        ['u' => 'https://fallback.example/?cid={click_id}', 'id' => $c2->id],
    );
    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh03'),
        new Response(),
        'trsh03',
    );
    expect($resp->getStatusCode())->toBe(302);
    $loc = $resp->getHeaderLine('Location');
    expect($loc)->toStartWith('https://fallback.example/?cid=');
    expect($loc)->not->toContain('{click_id}');
});

test('click is logged in stats.clicks', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $this->handler->handle($req, new Response(), 'clkt01');
    $count = (int)$this->db->fetchScalar('SELECT count(*) FROM stats.clicks WHERE campaign_id = :c', ['c' => $this->camp->id]);
    expect($count)->toBe(1);
});

test('Set-Cookie vu attached on first visit', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/clkt01');
    $resp = $this->handler->handle($req, new Response(), 'clkt01');
    $setCookie = $resp->getHeaderLine('Set-Cookie');
    expect($setCookie)->toContain('vu=');
    expect($setCookie)->toContain('Path=/');
});
