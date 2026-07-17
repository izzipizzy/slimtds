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

    $this->controller = new LoginController(
        new AdminRepository($this->db),
        $this->hasher,
        new AuthEventLogger($this->db),
    );

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
