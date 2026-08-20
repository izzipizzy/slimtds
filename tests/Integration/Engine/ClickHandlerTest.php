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
    $this->offers = $oRepo;
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
    $this->flow = $fRepo->create($this->camp->id, [
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

test('inactive offer does not receive new traffic', function (): void {
    $this->db->execute(
        'UPDATE core.offers SET is_active = false WHERE id = :id',
        ['id' => $this->offer->id],
    );

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/clkt01'),
        new Response(),
        'clkt01',
    );

    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
});

test('inactive offer is removed before active offer weights are evaluated', function (): void {
    $activeOffer = $this->offers->create([
        'name' => 'Active fallback',
        'url' => 'https://active.example/?cid={click_id}',
        'is_active' => '1',
    ]);
    $this->db->execute(
        'UPDATE core.offers SET is_active = false WHERE id = :id',
        ['id' => $this->offer->id],
    );
    $this->db->execute(
        'UPDATE core.flows SET target_offers = :targets::jsonb WHERE id = :id',
        [
            'id' => $this->flow->id,
            'targets' => json_encode([
                ['offer_id' => $this->offer->id, 'weight' => 100],
                ['offer_id' => $activeOffer->id, 'weight' => 1],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/clkt01')
        ->withCookieParams(['vu' => '019dc137-724a-756c-923a-a392001e3d79']);
    $resp = $this->handler->handle($req, new Response(), 'clkt01');

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toStartWith('https://active.example/');
});

test('a malformed offer_id does not take the rest of the flow down with it', function (): void {
    // core.offers.id is a uuid column, so a non-uuid candidate aborts the lookup
    // for every visitor — not just the share of them the picker would have sent
    // to that entry. The healthy offer next to it must still be served.
    $this->db->execute(
        'UPDATE core.flows SET target_offers = :targets::jsonb WHERE id = :id',
        [
            'id' => $this->flow->id,
            'targets' => json_encode([
                ['offer_id' => 'not-a-uuid', 'weight' => 100],
                ['offer_id' => $this->offer->id, 'weight' => 1],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/clkt01'),
        new Response(),
        'clkt01',
    );

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toStartWith('https://example.com/');
});

test('an offer_id that no longer exists is skipped in favour of a live one', function (): void {
    $this->db->execute(
        'UPDATE core.flows SET target_offers = :targets::jsonb WHERE id = :id',
        [
            'id' => $this->flow->id,
            'targets' => json_encode([
                ['offer_id' => '019dc137-724a-756c-923a-a39200000000', 'weight' => 100],
                ['offer_id' => $this->offer->id, 'weight' => 1],
            ], JSON_THROW_ON_ERROR),
        ],
    );

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/clkt01'),
        new Response(),
        'clkt01',
    );

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toStartWith('https://example.com/');
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

test('trash {offer:name} does not redirect to an inactive offer', function (): void {
    $this->db->execute(
        'UPDATE core.offers SET is_active = false WHERE id = :id',
        ['id' => $this->offer->id],
    );
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $campaign = $cRepo->create(['name' => 'Inactive trash offer', 'slug' => 'trsh04', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :url WHERE id = :id',
        ['url' => '{offer:O}', 'id' => $campaign->id],
    );

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh04'),
        new Response(),
        'trsh04',
    );

    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
});

test('trash {offer:uuid} does not redirect to an inactive offer either', function (): void {
    // The reference resolves by id before it falls back to the name, so the
    // active-only check has to hold on both branches, not just the named one.
    $this->db->execute(
        'UPDATE core.offers SET is_active = false WHERE id = :id',
        ['id' => $this->offer->id],
    );
    $cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $campaign = $cRepo->create(['name' => 'Inactive trash offer by id', 'slug' => 'trsh05', 'is_active' => '1']);
    $this->db->execute(
        'UPDATE core.campaigns SET trash_mode = 1, trash_url = :url WHERE id = :id',
        ['url' => '{offer:' . $this->offer->id . '}', 'id' => $campaign->id],
    );

    $resp = $this->handler->handle(
        (new ServerRequestFactory())->createServerRequest('GET', '/trsh05'),
        new Response(),
        'trsh05',
    );

    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Location'))->toBe('');
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
