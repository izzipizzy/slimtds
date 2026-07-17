<?php

declare(strict_types=1);

use App\Admin\Repository\Admin;
use App\Admin\Repository\AdminRepository;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    // Clean admins table for isolation — tests run sequentially
    $pdo->exec('DELETE FROM core.admins');
    $this->db = new Connection($pdo);
    $this->repo = new AdminRepository($this->db);
    $this->hasher = new PasswordHasher();

    // Seed a known admin
    $this->db->execute(
        'INSERT INTO core.admins (login, password_hash, must_change_password) VALUES (:l, :h, :m)',
        ['l' => 'alice', 'h' => $this->hasher->hash('secret123'), 'm' => 'true'],
    );
});

test('findByLogin returns Admin DTO for existing login', function (): void {
    $admin = $this->repo->findByLogin('alice');
    expect($admin)->toBeInstanceOf(Admin::class);
    expect($admin->login)->toBe('alice');
    expect($admin->mustChangePassword)->toBeTrue();
    expect($admin->uiLang)->toBe('ru');
});

test('findByLogin returns null for unknown login', function (): void {
    expect($this->repo->findByLogin('nobody'))->toBeNull();
});

test('findById works', function (): void {
    $alice = $this->repo->findByLogin('alice');
    expect($alice)->not->toBeNull();
    $same = $this->repo->findById($alice->id);
    expect($same)->not->toBeNull();
    expect($same->login)->toBe('alice');
});

test('updatePassword changes hash and clears must_change_password', function (): void {
    $alice = $this->repo->findByLogin('alice');
    $newHash = $this->hasher->hash('newpass456');
    expect($this->repo->updatePassword($alice->id, $newHash))->toBeTrue();

    $fresh = $this->repo->findById($alice->id);
    expect($fresh->passwordHash)->toBe($newHash);
    expect($fresh->mustChangePassword)->toBeFalse();
    expect($this->hasher->verify('newpass456', $fresh->passwordHash))->toBeTrue();
});

test('updatePassword returns false for unknown admin id', function (): void {
    expect($this->repo->updatePassword(999_999, 'anyhash'))->toBeFalse();
});

test('updateUiLang accepts ru/en only', function (): void {
    $alice = $this->repo->findByLogin('alice');
    expect($this->repo->updateUiLang($alice->id, 'en'))->toBeTrue();
    expect($this->repo->findById($alice->id)->uiLang)->toBe('en');

    $this->repo->updateUiLang($alice->id, 'fr');
})->throws(InvalidArgumentException::class);

test('flagPasswordChange sets must_change_password=true', function (): void {
    $alice = $this->repo->findByLogin('alice');
    // Update to false first
    $this->repo->updatePassword($alice->id, $this->hasher->hash('x'));
    expect($this->repo->findById($alice->id)->mustChangePassword)->toBeFalse();

    expect($this->repo->flagPasswordChange($alice->id))->toBeTrue();
    expect($this->repo->findById($alice->id)->mustChangePassword)->toBeTrue();
});

test('all returns list of admins ordered by id', function (): void {
    $this->db->execute(
        'INSERT INTO core.admins (login, password_hash) VALUES (:l, :h)',
        ['l' => 'bob', 'h' => $this->hasher->hash('x')],
    );
    $list = $this->repo->all();
    expect($list)->toHaveCount(2);
    expect($list[0]->login)->toBe('alice');
    expect($list[1]->login)->toBe('bob');
});
