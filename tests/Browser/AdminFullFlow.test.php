<?php

declare(strict_types=1);

// Full admin flow browser test — opt-in, requires running dev stack at https://slimtds.local
// Enable with: BROWSER_TESTS=1 pest --testsuite=Browser
// Requires pest-plugin-browser + Chromium (playwright downloads on first use)

if (getenv('BROWSER_TESTS') !== '1') {
    test('admin full-flow browser e2e skipped', fn () => null)->skip('set BROWSER_TESTS=1');
    return;
}


beforeAll(function (): void {
    $pdo = browserPdo();

    // Reset admin password to a known value for the test session
    browserAdmin('e2e-pass');

    // Wipe state that this test will populate
    $pdo->exec('DELETE FROM core.rate_limits');
    $pdo->exec('DELETE FROM core.postback_deliveries');
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec("DELETE FROM core.campaigns WHERE slug = 'e2efull'");
});

test('full flow: login → campaign → offer → flow → click → postback → conversions', function (): void {
    // ── Step 1: Login as admin ────────────────────────────────────────────────
    $page = visit(browserUrl('/admin/login'));
    $page->fill('login', 'admin')->fill('password', 'e2e-pass')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    // ── Step 2: Create campaign via UI ────────────────────────────────────────
    $page->navigate(browserUrl('/admin/campaigns/new'));
    $page->fill('name', 'E2E Full')->fill('slug', 'e2efull')->press('button[type=submit]');
    $page->assertPathIs('/admin/campaigns');

    // ── Step 3: Pull campaign id via direct PDO ───────────────────────────────
    // Direct DB inserts for offer + flow (walking the full nested UI is comprehensive
    // but redundant for this smoke test; the CRUD UI is covered in CampaignsBrowser.test.php)
    $pdo = browserPdo();
    $cid = (string) $pdo->query("SELECT id FROM core.campaigns WHERE slug = 'e2efull'")->fetchColumn();

    // ── Step 4: INSERT offer + flow via direct PDO ────────────────────────────
    // Generate a valid UUIDv7-shaped id for the offer (random time-ordered UUID)
    $oid = '019dc500-aaaa-7000-9000-' . str_pad((string) mt_rand(100000000000, 999999999999), 12, '0', STR_PAD_LEFT);
    $pdo->exec(
        "INSERT INTO core.offers (id, name, url, is_active) "
        . "VALUES ('{$oid}', 'E2E Offer', 'https://example.com/?cid={click_id}&p={payout}', true)",
    );

    // Fetch the auto-generated postback token so we can fire it later
    $tok = (string) $pdo->query("SELECT postback_token FROM core.offers WHERE id = '{$oid}'")->fetchColumn();

    // Insert a flow routing all traffic to our offer (schema_id 2 = HTTP 302)
    $pdo->exec(
        "INSERT INTO core.flows (campaign_id, name, filters, target_type, target_offers, schema_id, is_active) "
        . "VALUES ('{$cid}', 'F', '[]'::jsonb, 'offers', '[{\"offer_id\":\"{$oid}\",\"weight\":100}]'::jsonb, 2, true)",
    );

    // ── Step 5: Hit the slug URL and verify a click row appears ───────────────
    // Use file_get_contents with SSL context that skips verification for the self-signed dev cert.
    // The engine returns 302; keep the request local by disabling redirects.
    // we care only that the request was processed and a click was logged.
    stream_context_set_default(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    file_get_contents(browserUrl('/e2efull'), false, stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]));

    $clicks = (int) $pdo->query("SELECT count(*) FROM stats.clicks WHERE campaign_id = '{$cid}'")->fetchColumn();
    expect($clicks)->toBeGreaterThan(0);

    // ── Step 6: POST a postback with click_id + token → verify conversion ─────
    $clickId = (string) $pdo->query(
        "SELECT id FROM stats.clicks WHERE campaign_id = '{$cid}' ORDER BY created_at DESC LIMIT 1",
    )->fetchColumn();

    // Fire the postback endpoint
    @file_get_contents(
        browserUrl("/postback?subid={$clickId}&token={$tok}&payout=10.00&status=approved"),
    );

    $convCount = (int) $pdo->query(
        "SELECT count(*) FROM core.conversions WHERE click_id = '{$clickId}'",
    )->fetchColumn();
    expect($convCount)->toBe(1);

    // ── Step 7: Visit /admin/conversions and assertSee the payout ─────────────
    $page->navigate(browserUrl('/admin/conversions'));
    $page->assertSee('10.00');
});
