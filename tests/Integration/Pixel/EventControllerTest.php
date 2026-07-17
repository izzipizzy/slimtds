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
    $rawPdo = pdo();
    $rawPdo->exec('DELETE FROM stats.pixel_events_inbox');
    $rawPdo->exec('DELETE FROM stats.pixel_events');
    $rawPdo->exec('DELETE FROM stats.visitors_fingerprints');
    $rawPdo->exec('DELETE FROM core.campaigns');

    $this->db   = new Connection($rawPdo);
    $this->repo = new CampaignRepository($this->db, new CampaignIdGenerator());

    $this->ctrl = new EventController(
        $this->repo,
        new VisitorResolver($this->db),
        $this->db,
    );

    $this->camp = $this->repo->create(['name' => 'Pixel Test', 'slug' => 'px01', 'is_active' => '1']);
});

// ── helpers ────────────────────────────────────────────────────────────────

function pixelRequest(array $payload = []): \Psr\Http\Message\ServerRequestInterface
{
    $req  = (new ServerRequestFactory())->createServerRequest('POST', '/p/event');
    $json = json_encode($payload);
    $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($json);
    return $req
        ->withHeader('Content-Type', 'application/json')
        ->withBody($stream);
}

// ── tests ──────────────────────────────────────────────────────────────────

test('missing c returns 400', function (): void {
    $req  = pixelRequest(['url' => 'https://example.com']);
    $resp = ($this->ctrl)($req, new Response());
    expect($resp->getStatusCode())->toBe(400);
});

test('empty c returns 400', function (): void {
    $req  = pixelRequest(['c' => '', 'url' => 'https://example.com']);
    $resp = ($this->ctrl)($req, new Response());
    expect($resp->getStatusCode())->toBe(400);
});

test('unknown slug returns 404', function (): void {
    $req  = pixelRequest(['c' => 'zzz999', 'url' => 'https://example.com']);
    $resp = ($this->ctrl)($req, new Response());
    expect($resp->getStatusCode())->toBe(404);
});

test('valid payload inserts row and returns 204', function (): void {
    $payload = [
        'c'    => 'px01',
        'url'  => 'https://example.com/page',
        'ref'  => null,
        'ua'   => 'Mozilla/5.0 TestBrowser',
        'lang' => 'en',
        'tz'   => 'UTC',
        'sw'   => 1920,
        'sh'   => 1080,
        'fp'   => 'testfp123',
        't'    => time(),
    ];

    $req  = pixelRequest($payload);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(204);

    // EventController writes to the inbox; enrichment happens async via inbox:flush.
    $row = $this->db->fetchOne(
        'SELECT payload FROM stats.pixel_events_inbox ORDER BY created_at DESC LIMIT 1',
    );
    expect($row)->not->toBeNull();
    $p = json_decode((string)$row['payload'], true);
    expect($p['campaign_id'])->toBe($this->camp->id);
    expect($p['event'])->toBe('pageview');
    expect($p['url'])->toBe('https://example.com/page');
    expect($p['fp'])->toBe('testfp123');
    expect((int)$p['sw'])->toBe(1920);
});

test('new visitor gets Set-Cookie vu header', function (): void {
    $req  = pixelRequest(['c' => 'px01', 'url' => 'https://example.com', 'fp' => 'fpnew']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(204);
    $cookie = $resp->getHeaderLine('Set-Cookie');
    expect($cookie)->toContain('vu=');
    expect($cookie)->toContain('Path=/');
});
