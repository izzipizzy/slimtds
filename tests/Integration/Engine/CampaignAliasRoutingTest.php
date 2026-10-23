<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Engine\ClickHandler;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RoutingResults;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.visitors_fingerprints');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->repo = new CampaignRepository(new Connection($pdo), new CampaignIdGenerator());
    $this->app = (require dirname(__DIR__, 3) . '/config/app.php')();
});

test('expanded aliases reach their exact configured public 302 destination', function (string $alias): void {
    $campaign = $this->repo->create([
        'name' => 'Synthetic route', 'slug' => $alias, 'is_active' => '1',
        'trash_mode' => 1, 'trash_url' => 'https://destination.example/landing',
    ]);
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/' . $alias);
    $resp = $this->app->handle($req);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('https://destination.example/landing');
    expect((int)pdo()->query("SELECT count(*) FROM stats.clicks WHERE campaign_id = '" . $campaign->id . "'")->fetchColumn())
        ->toBe(1);
})->with(['a', 'qz', 'ravo-nex', 'demo_01', str_repeat('a', 17), str_repeat('Z', 50), 'old123', '-', '_', '0']);

test('invalid public paths never resolve to the campaign handler', function (string $path): void {
    $result = $this->app->getRouteResolver()->computeRoutingResults($path, 'GET');
    expect($result->getRouteStatus())->toBe(RoutingResults::NOT_FOUND);
    expect($this->app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode())
        ->toBe(404);
})->with(['/' . str_repeat('a', 51), '/a/b', '/a%2Fb', '/a%2fb', '/a%20b', '/%D0%BF%D1%80%D0%BE%D0%BC%D0%BE', '/a%00b', '/a%0Ab', '/a%0A']);

test('static endpoints keep their registered handlers ahead of campaign aliases', function (string $path): void {
    $this->repo->create([
        'name' => 'Synthetic legacy route', 'slug' => substr($path, 1), 'is_active' => '1',
        'trash_mode' => 1, 'trash_url' => 'https://destination.example/landing',
    ]);
    $resolver = $this->app->getRouteResolver();
    $result = $resolver->computeRoutingResults($path, 'GET');
    expect($result->getRouteStatus())->toBe(RoutingResults::FOUND);
    expect($result->getRouteArguments())->not->toHaveKey('slug');
    expect($resolver->resolveRoute($result->getRouteIdentifier())->getCallable())
        ->not->toBe([ClickHandler::class, 'handle']);
})->with(['/admin', '/mcp', '/postback', '/p.js', '/__health']);

test('health endpoint responds independently of a grandfathered health campaign', function (): void {
    $this->repo->create([
        'name' => 'Synthetic legacy health', 'slug' => '__health', 'is_active' => '1',
        'trash_mode' => 1, 'trash_url' => 'https://destination.example/landing',
    ]);
    $resp = $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', '/__health'));
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toBe('application/json');
    expect(json_decode((string)$resp->getBody(), true)['ok'])->toBeTrue();
    expect($resp->getHeaderLine('Location'))->toBe('');
});
