<?php

declare(strict_types=1);

use App\Admin\Controller\McpSettingsController;
use App\Admin\Controller\SettingsController;
use App\Admin\Repository\AdminRepository;
use App\Admin\Repository\SettingsRepository;
use App\Mcp\ApiKeyService;
use App\Shared\Asset\Manifest;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// The admin's login is not itself in the session — only $_SESSION['admin_id']
// is (see AuthMiddleware / LoginController / PasswordController). Resolving
// the login for the audit trail means looking the admin up by that id, same
// as PasswordController does, so this fixture mirrors PasswordControllerTest.
beforeEach(function (): void {
    $_SESSION = [];
    $this->db = new Connection(pdo());
    $this->db->execute("DELETE FROM core.settings WHERE key LIKE 'mcp_%'");
    $this->db->execute("DELETE FROM core.auth_events WHERE event_type LIKE 'mcp_%'");
    $this->db->execute('DELETE FROM core.admins');

    $hasher = new PasswordHasher();
    $this->db->execute(
        'INSERT INTO core.admins (login, password_hash, must_change_password) VALUES (:l, :h, :m)',
        ['l' => 'root', 'h' => $hasher->hash('irrelevant123'), 'm' => 'false'],
    );
    $rootId = (int)$this->db->fetchScalar("SELECT id FROM core.admins WHERE login = 'root'");
    $_SESSION['admin_id'] = $rootId;

    $this->admins = new AdminRepository($this->db);
    $this->keys = new ApiKeyService(new SettingsRepository($this->db));
    $this->c = new McpSettingsController($this->keys, $this->admins, new AuthEventLogger($this->db));
    $this->req = fn (array $body = []) => (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/settings/mcp/generate')->withParsedBody($body);
});

test('generate enables mcp, parks the key in the session for five minutes, audits, and returns to the tab', function (): void {
    $before = time();
    $r = $this->c->generate(($this->req)(), new Response());
    expect($r->getStatusCode())->toBe(302);
    expect($r->getHeaderLine('Location'))->toBe('/admin/settings#mcp');
    expect($_SESSION['mcp_new_key'])->toBeArray();
    expect($_SESSION['mcp_new_key']['key'])->toMatch('/^stds_[0-9A-Za-z]{40}$/');
    expect($_SESSION['mcp_new_key']['exp'])->toBeGreaterThanOrEqual($before + 300);
    expect($this->keys->verify($_SESSION['mcp_new_key']['key']))->toBeTrue();
    $row = $this->db->fetchOne("SELECT admin_login, details::text AS d FROM core.auth_events WHERE event_type = 'mcp_key_generated'");
    expect($row['admin_login'])->toBe('root');
    expect($row['d'])->not->toContain(substr($_SESSION['mcp_new_key']['key'], 9));
});

test('revoke disables and audits', function (): void {
    $this->keys->generate();
    $this->c->revoke(($this->req)(), new Response());
    expect($this->keys->isEnabled())->toBeFalse();
    expect((int)$this->db->fetchScalar("SELECT count(*) FROM core.auth_events WHERE event_type = 'mcp_key_revoked'"))->toBe(1);
});

test('options toggles full ip by checkbox presence', function (): void {
    $this->c->options(($this->req)(['mcp_full_ip' => '1']), new Response());
    expect($this->keys->fullIp())->toBeTrue();
    $this->c->options(($this->req)([]), new Response());
    expect($this->keys->fullIp())->toBeFalse();
});

// ── Rendering through SettingsController::index, the fifth tab ────────────

beforeEach(function (): void {
    $this->settingsRepo = new SettingsRepository($this->db);
    $this->settingsCtrl = new SettingsController(
        $this->settingsRepo,
        $this->db,
        new NotificationRegistry(),
        new TelegramNotifier(null, null),
        $this->keys,
    );

    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

function mcpSettingsGet(): \Psr\Http\Message\ServerRequestInterface
{
    return (new ServerRequestFactory())->createServerRequest('GET', '/admin/settings');
}

test('render shows the fresh key once, then hides it and falls back to the placeholder', function (): void {
    $_SESSION['mcp_new_key'] = ['key' => 'stds_' . str_repeat('a', 40), 'exp' => time() + 300];

    $resp = $this->settingsCtrl->index(mcpSettingsGet(), new Response(), $this->view);
    $body = (string)$resp->getBody();
    expect($body)->toContain('stds_' . str_repeat('a', 40));
    expect($_SESSION)->not->toHaveKey('mcp_new_key');
    expect($resp->getHeaderLine('Cache-Control'))->toBe('no-store');

    $resp2 = $this->settingsCtrl->index(mcpSettingsGet(), new Response(), $this->view);
    $body2 = (string)$resp2->getBody();
    expect($body2)->not->toContain('stds_' . str_repeat('a', 40));
    expect($body2)->toContain('stds_YOUR_KEY');
    expect($resp2->getHeaderLine('Cache-Control'))->toBe('');
});

test('an expired park is never shown and is removed from the session', function (): void {
    $_SESSION['mcp_new_key'] = ['key' => 'stds_' . str_repeat('a', 40), 'exp' => time() - 1];

    $resp = $this->settingsCtrl->index(mcpSettingsGet(), new Response(), $this->view);
    $body = (string)$resp->getBody();
    expect($body)->not->toContain('stds_' . str_repeat('a', 40));
    expect($body)->toContain('stds_YOUR_KEY');
    expect($_SESSION)->not->toHaveKey('mcp_new_key');
    expect($resp->getHeaderLine('Cache-Control'))->toBe('');
});

test('render includes install snippets pointing at /mcp and the Claude Code one-liner', function (): void {
    $resp = $this->settingsCtrl->index(mcpSettingsGet(), new Response(), $this->view);
    $body = (string)$resp->getBody();
    expect($body)->toContain('/mcp');
    expect($body)->toContain('claude mcp add --transport http slimtds');
});
