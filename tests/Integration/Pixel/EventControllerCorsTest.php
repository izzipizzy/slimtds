<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Engine\VisitorResolver;
use App\Pixel\EventController;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.pixel_events_inbox');
    $pdo->exec('DELETE FROM stats.pixel_events');
    $pdo->exec('DELETE FROM stats.visitors_fingerprints');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $this->cRepo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->ctrl = new EventController(
        $this->cRepo,
        new VisitorResolver($this->db),
        $this->db,
    );
    $this->camp = $this->cRepo->create(['name' => 'PX', 'slug' => 'px0001', 'is_active' => '1']);
});

test('OPTIONS preflight returns 204 with permissive CORS headers', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/p/event')
        ->withHeader('Origin', 'https://lander-a.local')
        ->withHeader('Access-Control-Request-Method', 'POST')
        ->withHeader('Access-Control-Request-Headers', 'content-type');
    $resp = $this->ctrl->preflight($req, new Response());

    expect($resp->getStatusCode())->toBe(204);
    // When Origin is present the CORS logic echoes it (not '*')
    expect($resp->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://lander-a.local');
    expect($resp->getHeaderLine('Access-Control-Allow-Methods'))->toContain('POST');
});

test('POST cross-origin event stages inbox row with CORS header on response', function (): void {
    $payload = json_encode([
        'c' => 'px0001',
        'event' => 'purchase',
        'url' => 'https://lander-a.local/checkout',
        'ref' => '',
        'ua' => 'Mozilla/5.0 (Macintosh) Chrome/120.0',
        'lang' => 'en-US',
        'tz' => 'Europe/Moscow',
        'sw' => 1280, 'sh' => 800,
        'fp' => 'fp-test-12345678',
        't' => time(),
        'props' => ['amount' => 99.5, 'sku' => 'A-101'],
    ]);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/p/event')
        ->withHeader('Origin', 'https://lander-a.local')
        ->withHeader('Content-Type', 'application/json');
    $req->getBody()->write($payload);
    $req->getBody()->rewind();

    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(204);
    expect($resp->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://lander-a.local');

    // EventController writes to the inbox; enrichment happens async via inbox:flush.
    $row = $this->db->fetchOne(
        'SELECT payload FROM stats.pixel_events_inbox ORDER BY created_at DESC LIMIT 1',
    );
    expect($row)->not->toBeNull();
    $p = json_decode((string)$row['payload'], true);
    expect($p['campaign_id'])->toBe($this->camp->id);
    expect($p['event'])->toBe('purchase');
    expect($p['url'])->toBe('https://lander-a.local/checkout');
    expect($p['fp'])->toBe('fp-test-12345678');
});

test('unknown campaign slug returns 404 with CORS still applied', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/p/event')
        ->withHeader('Origin', 'https://lander-a.local')
        ->withHeader('Content-Type', 'application/json');
    $req->getBody()->write(json_encode(['c' => 'doesnotexist', 'event' => 'pageview']));
    $req->getBody()->rewind();

    $resp = ($this->ctrl)($req, new Response());
    expect($resp->getStatusCode())->toBe(404);
    // When Origin is present the CORS logic echoes it
    expect($resp->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://lander-a.local');
});

test('missing slug returns 400', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/p/event')
        ->withHeader('Origin', 'https://lander-a.local')
        ->withHeader('Content-Type', 'application/json');
    $req->getBody()->write(json_encode(['event' => 'pageview']));
    $req->getBody()->rewind();

    $resp = ($this->ctrl)($req, new Response());
    expect($resp->getStatusCode())->toBe(400);
});

test('events from 4 different lander domains all stage to inbox for same campaign', function (): void {
    foreach (['a', 'b', 'c', 'd'] as $L) {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/p/event')
            ->withHeader('Origin', "https://lander-{$L}.local")
            ->withHeader('Content-Type', 'application/json');
        $req->getBody()->write(json_encode([
            'c' => 'px0001', 'event' => 'pageview',
            'url' => "https://lander-{$L}.local/",
            'fp' => "fp-{$L}",
        ]));
        $req->getBody()->rewind();

        $resp = ($this->ctrl)($req, new Response());
        expect($resp->getStatusCode())->toBe(204);
    }

    // EventController writes to inbox; check 4 rows are staged there.
    $rows = $this->db->fetchAll(
        "SELECT payload->>'url' AS url FROM stats.pixel_events_inbox
         WHERE (payload->>'campaign_id') = :c ORDER BY url",
        ['c' => $this->camp->id],
    );
    expect($rows)->toHaveCount(4);
    expect($rows[0]['url'])->toBe('https://lander-a.local/');
    expect($rows[3]['url'])->toBe('https://lander-d.local/');
});
