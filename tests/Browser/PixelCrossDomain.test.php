<?php

declare(strict_types=1);

// Cross-domain pixel test — visits each lander served by docker-compose.pixel-test.yml
// and verifies that real browser-fired pageview + custom events land in stats.pixel_events
// for the right campaign and with the originating lander host as referrer.
//
// Enable with: BROWSER_TESTS=1 pest --filter=PixelCrossDomain
// Requires the pixel-test compose to be up:
//   docker compose -f docker-compose.pixel-test.yml up -d

if (getenv('BROWSER_TESTS') !== '1') {
    test('pixel cross-domain skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}


$matrix = [
    ['lander' => 'a', 'campaign_slug' => 'demo01', 'event' => 'purchase',    'button_text' => 'Fire test purchase'],
    ['lander' => 'b', 'campaign_slug' => 'demo01', 'event' => 'signup',      'button_text' => 'Fire test signup'],
    ['lander' => 'c', 'campaign_slug' => 'mixab',  'event' => 'add_to_cart', 'button_text' => 'Fire add to cart'],
    ['lander' => 'd', 'campaign_slug' => 'ruonly', 'event' => 'engagement',  'button_text' => 'Fire engagement ping'],
];

beforeAll(function (): void {
    $pdo = browserPdo();
    foreach (['demo01', 'mixab', 'ruonly'] as $slug) {
        $stmt = $pdo->prepare("INSERT INTO core.campaigns (name, slug) VALUES (:name, :slug) ON CONFLICT (slug) DO NOTHING");
        $stmt->execute(['name' => 'Browser pixel ' . $slug, 'slug' => $slug]);
    }
    $pdo->exec("DELETE FROM stats.pixel_events WHERE page_url LIKE 'https://lander-%.local%' OR page_url LIKE 'http://lander-%'");
});

foreach ($matrix as $case) {
    test("lander-{$case['lander']}.local fires pageview + {$case['event']} into {$case['campaign_slug']}", function () use ($case): void {
        $pdo = browserPdo();
        $cid = (string)$pdo->query("SELECT id FROM core.campaigns WHERE slug = '{$case['campaign_slug']}'")->fetchColumn();
        expect($cid)->not->toBeEmpty();

        $base = sprintf(getenv('BROWSER_LANDER_URL_PATTERN') ?: 'https://lander-%s.local', $case['lander']);

        // Land on home — pixel auto-fires pageview after fingerprintjs resolves.
        // Then click an in-page nav link so the next page's document.referrer is non-empty.
        visit($base . '/')
            ->wait(2)
            ->click($case['button_text'])
            ->wait(1)
            ->click('About')
            ->wait(2)
            ->click('Pricing')
            ->wait(2);

        $container = (require dirname(__DIR__, 2) . '/config/di.php')();
        $container->get(\App\Cron\Command\InboxFlushCommand::class)->tick();

        $stmt = $pdo->prepare(
            "SELECT event_name, page_url, referer
             FROM stats.pixel_events
             WHERE campaign_id = :cid
               AND page_url LIKE :prefix
             ORDER BY created_at",
        );
        $stmt->execute([
            'cid'    => $cid,
            'prefix' => $base . '%',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $names = array_column($rows, 'event_name');
        expect($names)->toContain('pageview');
        expect($names)->toContain($case['event']);

        $aboutRow = null;
        foreach ($rows as $r) {
            expect($r['page_url'])->toStartWith($base . '/');
            if ($aboutRow === null && $r['event_name'] === 'pageview' && str_ends_with($r['page_url'], '/about')) {
                $aboutRow = $r;
            }
        }

        // /about pageview must carry the home page as document.referrer
        expect($aboutRow)->not->toBeNull();
        expect($aboutRow['referer'])->toBe($base . '/');
    });
}
