<?php

declare(strict_types=1);

// Browser tests are skipped by default in M1. Enable with: BROWSER_TESTS=1 pest --testsuite=Browser
// Requires pest-plugin-browser + Chromium (Playwright downloads on first use)

use function Pest\Browser\visit;

$skipBrowser = getenv('BROWSER_TESTS') !== '1';

beforeAll(function () use ($skipBrowser): void {
    if ($skipBrowser) {
        return;
    }
    $pdo = new PDO(
        'pgsql:host=db;port=5432;dbname=slimtds',
        'slimtds',
        'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $hash = password_hash('browserpass456', PASSWORD_ARGON2ID);
    $pdo->exec("UPDATE core.admins SET password_hash = '{$hash}', must_change_password = false WHERE login = 'admin'");
    $pdo->exec('DELETE FROM core.rate_limits');
});

test('can log in with valid credentials and land on dashboard', function () {
    $page = visit('https://slimtds.local/admin/login');
    $page->assertSee('Вход');
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->assertSee('Dashboard');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');

test('wrong password keeps us on /admin/login with error flash', function () {
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'definitely-wrong')->press('button[type=submit]');
    $page->assertPathIs('/admin/login');
    $page->assertSee('Неверный логин или пароль');
    $page->assertInputValue('login', 'admin');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');

test('clicking logout clears session', function () {
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->click('a[href="/admin/logout"]');
    $page->assertPathIs('/admin/login');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');
