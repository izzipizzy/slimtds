<?php

declare(strict_types=1);

use App\Admin\Controller\FlowController;
use App\Admin\Form\FlowForm;
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
    $GLOBALS['__old_bag__'] = [];
    $this->pdo = pdo();
    $this->pdo->beginTransaction();
    $db = new Connection($this->pdo);
    $campaigns = new CampaignRepository($db, new CampaignIdGenerator());
    $this->campaign = $campaigns->create(['name' => 'Ember', 'slug' => 'query-editor']);
    $this->flows = new FlowRepository($db);
    $this->controller = new FlowController($this->flows, $campaigns, new OfferRepository($db), new FlowForm());
    $root = dirname(__DIR__, 3);
    $this->view = new View($root . '/resources/views', new Manifest($root . '/public/assets/manifest.json'), new I18n((new TranslatorFactory($root . '/resources/translations'))->create()));
});

afterEach(function (): void {
    $this->pdo->rollBack();
    $GLOBALS['__old_bag__'] = [];
    $_SESSION = [];
});

test('creates updates and reopens named filter JSON', function (): void {
    $cid = $this->campaign->id;
    $data = ['name' => 'Ember', 'schema_id' => 2, 'target_type' => 'none', 'filters' => json_encode([[['field' => 'param:brand', 'op' => 'eq', 'value' => 'ember']]])];
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withParsedBody($data);
    expect($this->controller->create($request, new Response(), $cid)->getHeaderLine('Location'))->toBe("/admin/campaigns/{$cid}/edit#flows");
    $flow = $this->flows->forCampaign($cid)[0];
    expect($flow->filters)->toEqual(json_decode($data['filters'], true));
    $data['filters'] = json_encode([[['field' => 'param:brand', 'op' => 'neq', 'value' => 'cinder']]]);
    expect($this->controller->update($request->withParsedBody($data), new Response(), $cid, $flow->id)->getStatusCode())->toBe(302);
    expect($this->flows->findById($flow->id)->filters)->toEqual(json_decode($data['filters'], true));
    $html = (string)$this->controller->edit($request, new Response(), $this->view, $cid, $flow->id)->getBody();
    expect(html_entity_decode($html, ENT_QUOTES))->toContain('param:brand', 'cinder', 'neq');
});

test('rejects invalid create and update and renders retained escaped filters', function (string $name): void {
    $cid = $this->campaign->id;
    $data = ['name' => 'Ember', 'schema_id' => 2, 'target_type' => 'none', 'filters' => json_encode([[['field' => 'param:' . $name, 'op' => 'neq', 'value' => 'cinder']]])];
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/')->withParsedBody($data);
    expect($this->controller->create($request, new Response(), $cid)->getHeaderLine('Location'))->toBe("/admin/campaigns/{$cid}/flows/new");
    expect($this->flows->forCampaign($cid))->toBe([]);
    expect($_SESSION['_errors'])->toHaveKey('filters', 'validation.pattern');
    expect($_SESSION['_old']['filters'])->toBe($data['filters']);
    // Same per-request snapshot as SessionMiddleware.
    $GLOBALS['__old_bag__'] = $_SESSION['_old'];
    $html = (string)$this->controller->new_($request, new Response(), $this->view, $cid)->getBody();
    expect(html_entity_decode($html, ENT_QUOTES))->toContain('param:' . $name, 'cinder', 'neq');
    expect($html)->not->toContain('<img src=x onerror=alert(1)>')->toContain('form-error');
    $flow = $this->flows->create($cid, ['name' => 'Saved', 'schema_id' => 2, 'filters' => '[]']);
    expect($this->controller->update($request, new Response(), $cid, $flow->id)->getHeaderLine('Location'))->toBe("/admin/campaigns/{$cid}/flows/{$flow->id}/edit");
    expect($this->flows->findById($flow->id)->filters)->toBe([]);
    $GLOBALS['__old_bag__'] = $_SESSION['_old'];
    $html = (string)$this->controller->edit($request, new Response(), $this->view, $cid, $flow->id)->getBody();
    expect(html_entity_decode($html, ENT_QUOTES))->toContain('param:' . $name, 'cinder', 'neq');
})->with(['', 'bad.name', str_repeat('a', 65), '<img src=x onerror=alert(1)>']);
