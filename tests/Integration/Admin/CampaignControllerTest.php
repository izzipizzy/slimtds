<?php

declare(strict_types=1);

use App\Admin\Controller\CampaignController;
use App\Admin\Form\CampaignForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $_SESSION = [];
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.campaigns');
    $db = new Connection($pdo);
    $this->repo = new CampaignRepository($db, new CampaignIdGenerator());
    $this->controller = new CampaignController(
        $this->repo,
        new CampaignForm($this->repo, new CampaignIdGenerator()),
        new OfferRepository($db),
        new FlowRepository($db),
    );

    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n   = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

test('index renders list of campaigns', function (): void {
    $this->repo->create(['name' => 'Alpha', 'slug' => 'alpha1']);
    $this->repo->create(['name' => 'Beta', 'slug' => 'beta01']);

    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/campaigns');
    $resp = $this->controller->index($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(200);
    $body = (string)$resp->getBody();
    expect($body)->toContain('Alpha');
    expect($body)->toContain('alpha1');
    expect($body)->toContain('Beta');
});

test('create valid data creates campaign and redirects', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns');
    $req = $req->withParsedBody(['name' => 'Newbie', 'is_active' => '1']);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    expect($this->repo->count())->toBe(1);
});

test('create with bad data stores errors in session and redirects to /new', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns');
    $req = $req->withParsedBody(['name' => '', 'slug' => 'a!b']); // bad slug + empty name
    $resp = $this->controller->create($req, new Response());
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns/new');
    expect($_SESSION['_errors'] ?? [])->not->toBeEmpty();
});

test('update changes fields', function (): void {
    $c = $this->repo->create(['name' => 'Old', 'slug' => 'updctl']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $c->id);
    $req = $req->withParsedBody(['name' => 'Renamed', 'slug' => 'updctl', 'is_active' => '1', 'trash_mode' => 0]);
    $resp = $this->controller->update($req, new Response(), $c->id);
    expect($resp->getStatusCode())->toBe(302);
    expect($this->repo->findById($c->id)->name)->toBe('Renamed');
});

test('a campaign whose slug is already a reserved word stays editable under that slug', function (): void {
    $c = $this->repo->create(['name' => 'Legacy', 'slug' => 'admin']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $c->id);
    $req = $req->withParsedBody(['name' => 'Legacy renamed', 'slug' => 'admin', 'is_active' => '1', 'trash_mode' => 0]);
    $resp = $this->controller->update($req, new Response(), $c->id);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    expect($this->repo->findById($c->id)->name)->toBe('Legacy renamed');
    expect($this->repo->findById($c->id)->slug)->toBe('admin');
});

test('a new campaign cannot claim a reserved word as its alias', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns');
    $req = $req->withParsedBody(['name' => 'New guy', 'slug' => 'admin']);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns/new');
    expect($_SESSION['_errors'] ?? [])->not->toBeEmpty();
    expect($this->repo->findBySlug('admin'))->toBeNull();
});

test('delete removes campaign', function (): void {
    $c = $this->repo->create(['name' => 'Doomed', 'slug' => 'delctl']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $c->id . '/delete');
    $resp = $this->controller->delete($req, new Response(), $c->id);
    expect($resp->getStatusCode())->toBe(302);
    expect($this->repo->findById($c->id))->toBeNull();
});

test('expanded aliases can be created and edited without changing internal UUIDs', function (string $alias): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns')
        ->withParsedBody(['name' => 'Synthetic campaign', 'slug' => $alias, 'is_active' => '1']);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    $campaign = $this->repo->findBySlug($alias);
    expect($campaign)->not->toBeNull();
    expect($campaign->id)->toBeUuid();

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $campaign->id)
        ->withParsedBody(['name' => 'Synthetic renamed', 'slug' => $alias, 'is_active' => '1']);
    $resp = $this->controller->update($req, new Response(), $campaign->id);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    expect($this->repo->findById($campaign->id)->name)->toBe('Synthetic renamed');
    expect($this->repo->findById($campaign->id)->slug)->toBe($alias);
})->with(['a', 'qz', 'ravo-nex', 'demo_01', str_repeat('a', 17), str_repeat('Z', 50)]);

test('invalid custom aliases cannot be created or claimed by editing', function (string $alias): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns')
        ->withParsedBody(['name' => 'Synthetic rejected', 'slug' => $alias]);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns/new');
    expect($_SESSION['_errors'])->toHaveKey('slug');
    expect($this->repo->count())->toBe(0);

    $campaign = $this->repo->create(['name' => 'Synthetic original', 'slug' => 'original']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $campaign->id)
        ->withParsedBody(['name' => 'Rejected edit', 'slug' => $alias]);
    $resp = $this->controller->update($req, new Response(), $campaign->id);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns/' . $campaign->id . '/edit');
    expect($_SESSION['_errors'])->toHaveKey('slug');
    expect($this->repo->findById($campaign->id)->slug)->toBe('original');
    expect($this->repo->findById($campaign->id)->name)->toBe('Synthetic original');
})->with([str_repeat('a', 51), 'a/b', 'a%2Fb', 'a b', ' a', 'qz ', "a\n", "qz\t", "a\r", "\ta", "a\0", ' ', "\t\n", 'промо', "a\0b", "a\nb", 'admin', 'mcp', 'postback', '__health', '__HEALTH', 'p.js']);

test('empty custom input still generates a six character Base58 alias', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns')
        ->withParsedBody(['name' => 'Synthetic generated', 'slug' => '']);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    $campaign = $this->repo->page(1, 1)[0];
    expect($campaign->slug)->toMatch('/^[1-9A-HJ-NP-Za-km-z]{6}$/');
    expect($campaign->id)->toBeUuid();
});

test('expanded aliases retain case insensitive uniqueness', function (): void {
    $campaign = $this->repo->create(['name' => 'Synthetic original', 'slug' => 'Mix_ed-1']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns')
        ->withParsedBody(['name' => 'Synthetic duplicate', 'slug' => 'mix_ED-1']);
    $resp = $this->controller->create($req, new Response());
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns/new');
    expect($_SESSION['_errors']['slug'])->toBe('campaigns.slug_taken');
    expect($this->repo->count())->toBe(1);

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $campaign->id)
        ->withParsedBody(['name' => 'Synthetic renamed', 'slug' => 'Mix_ed-1']);
    expect($this->controller->update($req, new Response(), $campaign->id)->getHeaderLine('Location'))
        ->toBe('/admin/campaigns');
});

test('grandfathered health alias stays editable', function (): void {
    $campaign = $this->repo->create(['name' => 'Synthetic legacy', 'slug' => '__health']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $campaign->id)
        ->withParsedBody(['name' => 'Synthetic renamed', 'slug' => '__health']);
    $resp = $this->controller->update($req, new Response(), $campaign->id);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/campaigns');
    expect($this->repo->findById($campaign->id)->name)->toBe('Synthetic renamed');
    expect($this->repo->findById($campaign->id)->slug)->toBe('__health');
});
