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
use App\Shared\TestLink;
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

    $this->campaign = $cRepo->create(['name' => 'Named query test', 'slug' => 'params01', 'is_active' => '1']);
    $accept = $oRepo->create(['name' => 'Accept', 'url' => 'https://accept.example/?p={param:brand}&country={country}&query={param:country}', 'is_active' => '1']);
    $fallback = $oRepo->create(['name' => 'Fallback', 'url' => 'https://fallback.example/?p={param:brand}', 'is_active' => '1']);
    $this->acceptFlow = $fRepo->create($this->campaign->id, [
        'name' => 'brand match',
        'filters' => [[['field' => 'param:brand', 'op' => 'eq', 'value' => 'ember']]],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $accept->id, 'weight' => 100]],
        'schema_id' => 2,
        'is_active' => '1',
    ]);
    $fRepo->create($this->campaign->id, [
        'name' => 'fallback',
        'filters' => [],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $fallback->id, 'weight' => 100]],
        'schema_id' => 2,
        'is_active' => '1',
    ]);
});

test('named query parameters route actual PSR requests to exact destinations', function (array $query, string $location): void {
    // Campaign test links supply deterministic geo while retaining real filter evaluation.
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/params01')
        ->withQueryParams($query + ['_t' => TestLink::campaignKey($this->campaign->id), '_geo' => 'ru'])
        ->withHeader('User-Agent', 'Mozilla/5.0 TestBrowser');
    $response = $this->handler->handle($request, new Response(), 'params01');
    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe($location);
})->with([
    'accept' => [['brand' => 'ember', 'country' => 'us'], 'https://accept.example/?p=ember&country=ru&query=us'],
    'reject' => [['brand' => 'ash'], 'https://fallback.example/?p=ash'],
    'missing' => [[], 'https://fallback.example/?p='],
    'zero' => [['brand' => '0'], 'https://fallback.example/?p=0'],
    'empty' => [['brand' => ''], 'https://fallback.example/?p='],
    'array' => [['brand' => ['ember']], 'https://fallback.example/?p='],
    'null' => [['brand' => null], 'https://fallback.example/?p='],
    'control' => [['brand' => "ember\r\nLocation: bad"], 'https://fallback.example/?p='],
    'oversize' => [['brand' => str_repeat('x', 2049)], 'https://fallback.example/?p='],
    'encoded' => [['brand' => 'a &%/é%20'], 'https://fallback.example/?p=a%20%26%25%2F%C3%A9%2520'],
]);

test('named query parameters route arbitrary field names and boundary values in the pipeline', function (string $name, string $value): void {
    $this->db->execute(
        'UPDATE core.flows SET filters = :filters::jsonb WHERE id = :id',
        ['filters' => json_encode([[['field' => 'param:' . $name, 'op' => 'eq', 'value' => $value]]], JSON_THROW_ON_ERROR), 'id' => $this->acceptFlow->id],
    );
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/params01')
        ->withQueryParams([$name => $value, 'brand' => $value, '_t' => TestLink::campaignKey($this->campaign->id), '_geo' => 'ru'])
        ->withHeader('User-Agent', 'Mozilla/5.0 TestBrowser');
    $response = $this->handler->handle($request, new Response(), 'params01');
    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe('https://accept.example/?p=' . rawurlencode($value) . '&country=ru&query=');
})->with([
    'slot 30' => ['sub_id_30', 'ember'],
    'no finite slot cap' => ['sub_id_31', 'ember'],
    'custom' => ['_custom-2', 'ember'],
    '64 name' => [str_repeat('a', 64), 'ember'],
    '2048 value' => ['brand', str_repeat('x', 2048)],
]);
