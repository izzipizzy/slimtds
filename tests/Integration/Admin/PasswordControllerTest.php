<?php

declare(strict_types=1);

use App\Admin\Controller\PasswordController;
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
        ['l' => 'alice', 'h' => $this->hasher->hash('oldpassword123'), 'm' => 'true'],
    );
    $aliceId = (int)$this->db->fetchScalar("SELECT id FROM core.admins WHERE login = 'alice'");
    $_SESSION['admin_id'] = $aliceId;

    $this->controller = new PasswordController(
        new AdminRepository($this->db),
        $this->hasher,
        new AuthEventLogger($this->db),
    );

    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n   = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

test('GET renders password form', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/password');
    $resp = $this->controller->get($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(200);
    $body = (string)$resp->getBody();
    expect($body)->toMatch('/(Смена пароля|Change password)/');
    expect($body)->toContain('name="current"');
    expect($body)->toContain('name="new_password"');
    expect($body)->toContain('name="confirm"');
});

test('GET redirects to login when no session', function (): void {
    $_SESSION = [];
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/password');
    $resp = $this->controller->get($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/login');
});

test('POST with wrong current password fails with flash error', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/password');
    $req = $req->withParsedBody(['current' => 'wrong', 'new_password' => 'newpassword123', 'confirm' => 'newpassword123']);
    $resp = $this->controller->post($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/password');
    expect($_SESSION['_flash']['error'] ?? [])->not->toBeEmpty();
});

test('POST rejects too-short new password', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/password');
    $req = $req->withParsedBody(['current' => 'oldpassword123', 'new_password' => 'short', 'confirm' => 'short']);
    $resp = $this->controller->post($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/password');
    expect($_SESSION['_flash']['error'][0] ?? '')->toMatch('/(10|символ)/');
});

test('POST rejects mismatched confirmation', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/password');
    $req = $req->withParsedBody(['current' => 'oldpassword123', 'new_password' => 'newpassword123', 'confirm' => 'typo123typo']);
    $resp = $this->controller->post($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/password');
});

test('POST rejects same-as-old password', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/password');
    $req = $req->withParsedBody(['current' => 'oldpassword123', 'new_password' => 'oldpassword123', 'confirm' => 'oldpassword123']);
    $resp = $this->controller->post($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin/password');
});

test('POST valid change updates hash and clears flag', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/admin/password');
    $req = $req->withParsedBody(['current' => 'oldpassword123', 'new_password' => 'freshpass2026', 'confirm' => 'freshpass2026']);
    $resp = $this->controller->post($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(302);
    expect($resp->getHeaderLine('Location'))->toBe('/admin');
    expect($_SESSION['_flash']['success'] ?? [])->not->toBeEmpty();

    $admin = (new AdminRepository($this->db))->findByLogin('alice');
    expect($admin->mustChangePassword)->toBeFalse();
    expect($this->hasher->verify('freshpass2026', $admin->passwordHash))->toBeTrue();
    expect($this->hasher->verify('oldpassword123', $admin->passwordHash))->toBeFalse();

    $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.auth_events WHERE event_type = :t', ['t' => 'password_change']);
    expect($n)->toBe(1);
});
