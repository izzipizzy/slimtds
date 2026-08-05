<?php

declare(strict_types=1);

use App\Admin\Repository\ViewPreferenceRepository;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Db\Connection;

const VP_ALLOWED = ['is_trash', 'bot_view', 'search', 'entry_ref', 'fp_js_has'];

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.user_view_prefs');
    $pdo->exec('DELETE FROM core.admins');
    $this->db = new Connection($pdo);
    $this->repo = new ViewPreferenceRepository($this->db);

    $hasher = new PasswordHasher();
    $this->adminId = (int)$this->db->fetchScalar(
        'INSERT INTO core.admins (login, password_hash) VALUES (:l, :h) RETURNING id',
        ['l' => 'viewprefs', 'h' => $hasher->hash('secret123')],
    );
});

test('no saved preference reads back as an empty default', function (): void {
    expect($this->repo->get($this->adminId, 'clicks', VP_ALLOWED))->toBe([]);
});

test('saved preferences round-trip', function (): void {
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'hide', 'entry_ref' => 'google'], VP_ALLOWED);

    expect($this->repo->get($this->adminId, 'clicks', VP_ALLOWED))
        ->toBe(['bot_view' => 'hide', 'entry_ref' => 'google']);
});

test('keys outside the allow-list never reach storage', function (): void {
    $this->repo->save(
        $this->adminId,
        'clicks',
        ['bot_view' => 'only', 'campaign_id' => 'smuggled', 'page' => '7'],
        VP_ALLOWED,
    );

    expect($this->repo->get($this->adminId, 'clicks', VP_ALLOWED))->toBe(['bot_view' => 'only']);
});

test('a narrowed allow-list also filters on read', function (): void {
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'hide', 'search' => 'any'], VP_ALLOWED);

    expect($this->repo->get($this->adminId, 'clicks', ['search']))->toBe(['search' => 'any']);
});

test('saving twice replaces rather than duplicates', function (): void {
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'hide'], VP_ALLOWED);
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'only'], VP_ALLOWED);

    expect($this->repo->get($this->adminId, 'clicks', VP_ALLOWED))->toBe(['bot_view' => 'only'])
        ->and((int)$this->db->fetchScalar('SELECT count(*) FROM core.user_view_prefs'))->toBe(1);
});

test('clear removes the preference', function (): void {
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'hide'], VP_ALLOWED);
    $this->repo->clear($this->adminId, 'clicks');

    expect($this->repo->get($this->adminId, 'clicks', VP_ALLOWED))->toBe([]);
});

test('preferences are scoped per view key', function (): void {
    $this->repo->save($this->adminId, 'clicks', ['bot_view' => 'hide'], VP_ALLOWED);

    expect($this->repo->get($this->adminId, 'pixel', VP_ALLOWED))->toBe([]);
});
