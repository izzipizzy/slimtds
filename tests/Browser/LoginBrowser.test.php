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
});

test('can log in with valid credentials and land on dashboard', function () {
    $page = visit(browserUrl('/admin/login'), ['locale' => 'ru-RU']);
    $page->assertSee('Вход');
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->assertSee('Панель');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');

test('wrong password keeps us on /admin/login with error flash', function () {
    $page = visit(browserUrl('/admin/login'), ['locale' => 'ru-RU']);
    $page->fill('login', 'admin')->fill('password', 'definitely-wrong')->press('button[type=submit]');
    $page->assertPathIs('/admin/login');
    $page->assertSee('Неверный логин или пароль');
    $page->assertValue('login', 'admin');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');

test('clicking logout clears session', function () {
    $page = visit(browserUrl('/admin/login'), ['locale' => 'ru-RU']);
    $page->fill('login', 'admin')->fill('password', 'browserpass456')->press('button[type=submit]');
    $page->assertPathIs('/admin');
    $page->click('a[href="/admin/logout"]:visible');
    $page->assertPathIs('/admin/login');
})->skip($skipBrowser, 'set BROWSER_TESTS=1 to enable browser tests');
