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

test('delete removes campaign', function (): void {
    $c = $this->repo->create(['name' => 'Doomed', 'slug' => 'delctl']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/campaigns/' . $c->id . '/delete');
    $resp = $this->controller->delete($req, new Response(), $c->id);
    expect($resp->getStatusCode())->toBe(302);
    expect($this->repo->findById($c->id))->toBeNull();
});
