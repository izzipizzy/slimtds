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
    $pdo = new PDO('pgsql:host=db;port=5432;dbname=slimtds', 'slimtds', 'slimtds', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $hash = password_hash('browserpass456', PASSWORD_ARGON2ID);
    $pdo->exec("UPDATE core.admins SET password_hash = '{$hash}', must_change_password = false WHERE login = 'admin'");
    $pdo->exec('DELETE FROM core.rate_limits');
});

test('user can change password and log back in with the new one', function () {
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    $page = visit('https://slimtds.local/admin/password');
    $page->fill('current', 'browserpass456')
         ->fill('new_password', 'newerpass789')
         ->fill('confirm', 'newerpass789')
         ->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->assertSee('успешно');

    $page->click('a[href="/admin/logout"]');
    $page->assertPathIs('/admin/login');
    $page->fill('login', 'admin')->fill('password', 'newerpass789')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    // Reset for next runs
    $pdo = new PDO('pgsql:host=db;port=5432;dbname=slimtds', 'slimtds', 'slimtds', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $hash = password_hash('browserpass456', PASSWORD_ARGON2ID);
    $pdo->exec("UPDATE core.admins SET password_hash = '{$hash}' WHERE login = 'admin'");
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');
