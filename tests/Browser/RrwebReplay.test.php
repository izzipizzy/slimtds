<?php

declare(strict_types=1);

if (getenv('BROWSER_TESTS') !== '1') {
    test('rrweb replay (browser, opt-in) skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}

test('a recorded session produces replayable events', function (): void {
    $pdo = browserPdo();
    $pdo->exec("INSERT INTO core.campaigns (name, slug) VALUES ('Browser recording', 'demo01') ON CONFLICT (slug) DO NOTHING");
    $pdo->exec("INSERT INTO core.settings (key, value) VALUES ('rrweb_sample_rate', '100') ON CONFLICT (key) DO UPDATE SET value = '100'");
    $base = sprintf(getenv('BROWSER_LANDER_URL_PATTERN') ?: 'https://lander-%s.local', 'a');
    $page = visit($base . '/')->wait(2)->click('Fire test purchase')->wait(1);
    $sid = $page->script('sessionStorage.getItem("slim_sid")');
    expect($sid)->toBeUuid();
    $page->script('window.dispatchEvent(new Event("pagehide"))');
    $page->wait(1);

    $container = (require dirname(__DIR__, 2) . '/config/di.php')();
    $container->get(\App\Cron\Command\RrwebFlushCommand::class)->drainOnce();
    $stmt = $pdo->prepare('SELECT event_count FROM stats.rrweb_sessions WHERE session_id = :id');
    $stmt->execute(['id' => $sid]);
    expect((int) $stmt->fetchColumn())->toBeGreaterThanOrEqual(2);
    $activity = $pdo->prepare('SELECT has_interaction FROM stats.rrweb_sessions WHERE session_id = :id');
    $activity->execute(['id' => $sid]);
    expect($activity->fetchColumn())->toBeTrue();
    $events = $container->get(\App\Admin\Repository\RrwebSessionRepository::class)->events($sid);
    expect(array_column($events, 'type'))->toContain(2, 4);

    browserAdmin('replay-browser-password');
    $admin = visit(browserUrl('/admin/login'), ['locale' => 'ru-RU']);
    $admin->fill('login', 'admin')->fill('password', 'replay-browser-password')->press('button[type=submit]');
    $admin->navigate(browserUrl('/admin/sessions/' . $sid));
    $admin->assertVisible('.session-player-toggle')->assertPresent('.session-player-viewport iframe');
    $admin->click('button[aria-label="Пауза"]')->assertAttribute('.session-player-toggle', 'aria-label', 'Смотреть');
    $admin->click('button[data-speed="2"]')->assertAttribute('button[data-speed="2"]', 'aria-pressed', 'true');
    $admin->script('const seek = document.querySelector(".session-player-timeline"); seek.value = "500"; seek.dispatchEvent(new Event("input")); seek.dispatchEvent(new Event("change"));');
    $admin->assertScript('document.querySelector(".session-player-timeline").value', '500');
    $admin->assertPresent('[data-session-activity="active"]');
    $admin->navigate(browserUrl('/admin/sessions?activity=active&min_dur=0'));
    $admin->assertPresent('a[href="/admin/sessions/' . $sid . '"]')->assertPresent('[data-session-activity="active"]');
    $admin->navigate(browserUrl('/admin/sessions?activity=inactive&min_dur=0'));
    $admin->assertMissing('a[href="/admin/sessions/' . $sid . '"]');
    $admin->assertNoJavaScriptErrors();
})->group('browser');
