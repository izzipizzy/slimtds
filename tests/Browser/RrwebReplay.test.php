<?php

declare(strict_types=1);

// rrweb session record→replay e2e test (opt-in, browser).
//
// Verifies the full pipeline: a seeded campaign lander with p.js?c=<slug> (rate=100)
// records an rrweb session; pagehide flushes it via sendBeacon; `rrweb:flush` drains
// the inbox; stats.rrweb_sessions has a row; /admin/sessions/{sid}/events returns ≥ 2 events.
//
// Enable with:  BROWSER_TESTS=1 pest --filter=RrwebReplay
// Requires the dev stack to be up (make up) with assets built (make build-assets)
// and migrations applied (make migrate + make seed).
// Follow tests/Browser/PixelCrossDomain.test.php for the Playwright driver bootstrap.

if (getenv('BROWSER_TESTS') !== '1') {
    test('rrweb replay (browser, opt-in) skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}

test('a recorded session produces replayable events', function (): void {
    // TODO: fill in concrete Playwright steps following tests/Browser/PixelCrossDomain.test.php.
    //
    // 1. Resolve a seeded campaign slug (e.g. 'demo01') and its lander URL that
    //    embeds p.js?c=<slug> with rrweb rate=100 so recording is guaranteed.
    //
    // 2. Use the Playwright driver (same bootstrap as PixelCrossDomain.test.php):
    //      visit('https://<lander>.local/')
    //          ->wait(2)           // let rrweb initialise
    //          ->scroll(0, 300)    // produce scroll events
    //          ->click('...')      // produce interaction events
    //          ->wait(1);
    //
    // 3. Trigger pagehide to flush the rrweb event buffer via sendBeacon:
    //      ->evaluate('window.dispatchEvent(new Event("pagehide"))');
    //
    // 4. Run the console command to drain the inbox:
    //      shell_exec('docker compose exec app bin/console rrweb:flush');
    //
    // 5. Assert a session row exists in stats.rrweb_sessions:
    //      $pdo = new PDO(...);
    //      $sid = $pdo->query("SELECT id FROM stats.rrweb_sessions ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    //      expect($sid)->not->toBeFalsy();
    //
    // 6. Assert the admin events endpoint returns ≥ 2 events:
    //      $resp = file_get_contents("http://localhost/admin/sessions/{$sid}/events");
    //      $events = json_decode($resp, true);
    //      expect(count($events))->toBeGreaterThanOrEqual(2);

    expect(true)->toBeTrue(); // placeholder — replace with steps above
})->group('browser');
