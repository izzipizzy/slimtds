<?php

declare(strict_types=1);

// Browser tests are skipped by default in M1. Enable with: BROWSER_TESTS=1 pest --testsuite=Browser
// Requires pest-plugin-browser + Chromium (Playwright downloads on first use)


$skipBrowser = getenv('BROWSER_TESTS') !== '1';

beforeAll(function () use ($skipBrowser): void {
    if ($skipBrowser) {
        return;
    }
    $pdo = browserPdo();
    browserAdmin('browserpass456');
    $pdo->exec('DELETE FROM core.rate_limits');
    $pdo->exec('DELETE FROM core.campaigns');
});

test('can create a campaign and see it listed', function () {
    $page = visit(browserUrl('/admin/login'));
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    $page->navigate(browserUrl('/admin/campaigns'));
    $page->click('main a[href="/admin/campaigns/new"] >> nth=0');
    $page->assertScript('document.querySelectorAll("[name=engagement_mode]").length', 0);
    $page->fill('name', 'BrowserCampaign')->press('button[type=submit]');

    $page->assertPathIs('/admin/campaigns');
    $page->assertSee('BrowserCampaign');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');
