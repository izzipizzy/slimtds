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
    $pdo->exec("UPDATE core.admins SET password_hash = '" . password_hash('browserpass456', PASSWORD_ARGON2ID) . "', must_change_password = false WHERE login = 'admin'");
    $pdo->exec('DELETE FROM core.rate_limits');
    $pdo->exec('DELETE FROM core.campaigns');
});

test('can create a campaign and see it listed', function () {
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    $page = visit('https://slimtds.local/admin/campaigns');
    $page->click('a[href="/admin/campaigns/new"]');
    $page->fill('name', 'BrowserCampaign')->press('button[type=submit]');

    $page->assertPathIs('/admin/campaigns');
    $page->assertSee('BrowserCampaign');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');
