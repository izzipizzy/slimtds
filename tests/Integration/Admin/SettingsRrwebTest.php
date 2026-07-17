<?php

declare(strict_types=1);

use App\Admin\Controller\SettingsController;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Asset\Manifest;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $_SESSION = [];
    $this->db   = new Connection(pdo());
    $this->repo = new SettingsRepository($this->db);
    $this->ctrl = new SettingsController(
        $this->repo,
        $this->db,
        new NotificationRegistry(),
        new TelegramNotifier(null, null),
    );

    $root         = dirname(__DIR__, 3);
    $assets       = new Manifest($root . '/public/assets/manifest.json');
    $i18n         = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view   = new View($root . '/resources/views', $assets, $i18n);
});

function settingsPost(array $body): \Psr\Http\Message\ServerRequestInterface
{
    return (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/settings')
        ->withParsedBody($body);
}

test('saves valid rrweb sample rate and retention', function (): void {
    $resp = $this->ctrl->index(
        settingsPost(['rrweb_sample_rate' => '25', 'retention_rrweb_days' => '7']),
        new Response(),
        $this->view,
    );
    expect($resp->getStatusCode())->toBe(302);
    expect($this->repo->get('rrweb_sample_rate'))->toBe('25');
    expect($this->repo->get('retention_rrweb_days'))->toBe('7');
});

test('rejects out-of-range sample rate', function (): void {
    $this->repo->set('rrweb_sample_rate', '50');
    $this->ctrl->index(
        settingsPost(['rrweb_sample_rate' => '500']),
        new Response(),
        $this->view,
    );
    expect($this->repo->get('rrweb_sample_rate'))->toBe('50'); // unchanged
});
