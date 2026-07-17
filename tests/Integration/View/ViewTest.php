<?php

declare(strict_types=1);

use App\Shared\Asset\Manifest;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;

beforeEach(function (): void {
    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

test('render with layout wraps content', function (): void {
    $html = $this->view->render('admin/login', [
        'csrf_token' => 'deadbeef',
        'lang' => 'ru',
        '__layout__' => 'layouts/public',
        'title' => 'Login',
    ]);
    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('Login · slimTDS');
    expect($html)->toContain('Логин');   // t('auth.login_field') in ru
    expect($html)->toContain('deadbeef'); // csrf token echoed
});

test('render without layout returns just template output', function (): void {
    $html = $this->view->render('admin/login', [
        'csrf_token' => 'x',
        'lang' => 'ru',
        '__layout__' => null,
    ]);
    expect($html)->not->toContain('<!DOCTYPE html>');
    expect($html)->toContain('Логин');   // t('auth.login_field') in ru
});

test('missing view throws', function (): void {
    $this->view->render('no/such/view');
})->throws(RuntimeException::class);

test('e() escapes html', function (): void {
    expect(e('<script>alert(1)</script>'))->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect(e("it's"))->toBe('it&#039;s');
});

test('asset() resolves from manifest', function (): void {
    // This test requires the manifest to exist — skip if build hasn't run
    $manifestPath = dirname(__DIR__, 3) . '/public/assets/manifest.json';
    if (!is_file($manifestPath)) {
        $this->markTestSkipped('manifest.json missing — run bun run build');
    }

    $html = $this->view->render('admin/login', [
        'csrf_token' => 'x',
        'lang' => 'en',
        '__layout__' => 'layouts/public',
        'title' => 'Login',
    ]);
    expect($html)->toMatch('~/assets/app\.[a-f0-9]+\.css~');
    expect($html)->toMatch('~/assets/app\.[a-f0-9]+\.js~');
});

test('url() normalizes slashes', function (): void {
    expect(url('/admin'))->toBe('/admin');
    expect(url('admin'))->toBe('/admin');
    expect(url('/admin/'))->toBe('/admin/');
});

test('flash_push + flash round-trip', function (): void {
    $_SESSION = [];
    flash_push('success', 'ok');
    flash_push('success', 'still ok');
    $msgs = flash('success');
    expect($msgs)->toBe(['ok', 'still ok']);
    expect(flash('success'))->toBe([]);  // cleared after read
});
