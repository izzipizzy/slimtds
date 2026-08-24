<?php

declare(strict_types=1);

use App\Admin\Controller\LoginController;
use App\Admin\Repository\AdminRepository;
use App\Shared\Asset\Manifest;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\Version\BuildInfo;
use App\Shared\Version\UpdateState;
use App\Shared\Version\UpdateStateReader;
use App\Shared\Version\UpdateStatus;
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
    $pdo->exec('DELETE FROM core.admins');
    $pdo->exec('DELETE FROM core.auth_events');

    $this->db = new Connection($pdo);
    $this->hasher = new PasswordHasher();
    $this->db->execute(
        'INSERT INTO core.admins (login, password_hash, must_change_password) VALUES (:l, :h, :m)',
        ['l' => 'alice', 'h' => $this->hasher->hash('correct-horse'), 'm' => 'false'],
    );

    // Update state is stubbed: these tests are about authentication, and the
    // login path must not depend on an update check having run.
    $this->updateState = null;
    $reader = new class ($this) implements UpdateStateReader {
        public function __construct(private object $t) {}
        public function read(): ?UpdateState { return $this->t->updateState; }
    };
    $this->makeController = function (BuildInfo $build) use ($reader): LoginController {
        return new LoginController(
            new AdminRepository($this->db),
            $this->hasher,
            new AuthEventLogger($this->db),
            new UpdateStatus($build, $reader, true, 'izzipizzy/slimtds'),
        );
    };

    $this->controller = ($this->makeController)(new BuildInfo(''));

    // View is only needed to get translator; we can pass a real one
    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n   = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

test('valid creds set session and redirect to /admin', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $resp = $this->controller->postLogin($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin');
    expect($_SESSION['admin_id'] ?? null)->toBeInt();

    $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.auth_events WHERE event_type = :t', ['t' => 'login_success']);
    expect($n)->toBe(1);
});

test('wrong password redirects to /admin/login with flash error', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody(['login' => 'alice', 'password' => 'wrong']);

    $resp = $this->controller->postLogin($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/login');
    expect($_SESSION['admin_id'] ?? null)->toBeNull();
    expect($_SESSION['_flash']['error'] ?? [])->not->toBeEmpty();
    expect($_SESSION['_old']['login'] ?? null)->toBe('alice');

    $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.auth_events WHERE event_type = :t', ['t' => 'login_fail']);
    expect($n)->toBe(1);
});

test('unknown login redirects to /admin/login with flash error', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody(['login' => 'nobody', 'password' => 'any']);

    $resp = $this->controller->postLogin($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/login');

    $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.auth_events WHERE event_type = :t AND admin_login = :l', ['t' => 'login_fail', 'l' => 'nobody']);
    expect($n)->toBe(1);
});

test('empty fields redirect with error', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody(['login' => '', 'password' => '']);

    $resp = $this->controller->postLogin($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/login');
});

test('must_change_password admin is redirected to /admin/password after login', function (): void {
    $this->db->execute('UPDATE core.admins SET must_change_password = true WHERE login = :l', ['l' => 'alice']);

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login');
    $req = $req->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $resp = $this->controller->postLogin($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/password');
});

test('logout destroys session and redirects to /admin/login', function (): void {
    $_SESSION = ['admin_id' => 1];

    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/logout');
    $resp = $this->controller->getLogout($req, new Response());

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/login');
});

// ── update toast on login ──────────────────────────────────────────────────
// The toast is owned by the controller, not the layout: layout globals render
// on every page, so a state-driven toast would follow the operator around
// instead of greeting them once.

test('a behind release build queues exactly one update toast', function (): void {
    $this->updateState = new UpdateState(
        repo: 'izzipizzy/slimtds',
        latestVersion: 'v9.9.9',
        lastAttemptAt: time(),
        lastSuccessAt: time(),
    );
    $controller = ($this->makeController)(new BuildInfo('v0.7.0', 'abc1234', null, 'release'));

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login')
        ->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $resp = $controller->postLogin($req, new Response(), $this->view);

    expect($resp->getStatusCode())->toBe(302);
    expect($_SESSION['_flash']['info'] ?? [])->toHaveCount(1);
    expect($_SESSION['_flash']['info'][0])->toContain('v9.9.9');
});

test('an up-to-date build queues no toast', function (): void {
    $this->updateState = new UpdateState(
        repo: 'izzipizzy/slimtds',
        latestVersion: 'v0.7.0',
        lastAttemptAt: time(),
        lastSuccessAt: time(),
    );
    $controller = ($this->makeController)(new BuildInfo('v0.7.0', 'abc1234', null, 'release'));

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login')
        ->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $controller->postLogin($req, new Response(), $this->view);

    expect($_SESSION['_flash']['info'] ?? [])->toBeEmpty();
});

test('a source build queues no toast even when upstream is far ahead', function (): void {
    $this->updateState = new UpdateState(
        repo: 'izzipizzy/slimtds',
        latestVersion: 'v9.9.9',
        lastAttemptAt: time(),
        lastSuccessAt: time(),
    );
    $controller = ($this->makeController)(new BuildInfo('v0.4.1-85-gb9d3407', 'b9d3407', null, 'source'));

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login')
        ->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $controller->postLogin($req, new Response(), $this->view);

    expect($_SESSION['_flash']['info'] ?? [])->toBeEmpty();
});

// A login must never fail because an update check could not be read.
test('a throwing update reader does not break the login', function (): void {
    $throwing = new class implements UpdateStateReader {
        public function read(): ?UpdateState { throw new RuntimeException('db is down'); }
    };
    $controller = new LoginController(
        new AdminRepository($this->db),
        $this->hasher,
        new AuthEventLogger($this->db),
        new UpdateStatus(new BuildInfo('v0.7.0', 'abc1234', null, 'release'), $throwing, true, 'izzipizzy/slimtds'),
    );

    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/login')
        ->withParsedBody(['login' => 'alice', 'password' => 'correct-horse']);

    $resp = $controller->postLogin($req, new Response(), $this->view);

    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin');
    expect($_SESSION['_flash']['info'] ?? [])->toBeEmpty();
});
