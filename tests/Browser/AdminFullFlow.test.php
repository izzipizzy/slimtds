<?php

declare(strict_types=1);

// Full admin flow browser test — opt-in, requires running dev stack at https://slimtds.local
// Enable with: BROWSER_TESTS=1 pest --testsuite=Browser
// Requires pest-plugin-browser + Chromium (playwright downloads on first use)

if (getenv('BROWSER_TESTS') !== '1') {
    test('admin full-flow browser e2e skipped', fn () => null)->skip('set BROWSER_TESTS=1');
    return;
}

use function Pest\Browser\visit;

beforeAll(function (): void {
    // NOTE: browser tests hit the dev stack at slimtds.local, so we point at db (dev), not db-test.
    $pdo = new PDO(
        'pgsql:host=db;port=5432;dbname=slimtds',
        'slimtds',
        'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // Reset admin password to a known value for the test session
    $hash = password_hash('e2e-pass', PASSWORD_ARGON2ID);
    $pdo->exec("UPDATE core.admins SET password_hash = '{$hash}', must_change_password = false WHERE login = 'admin'");

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
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'e2e-pass')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    // ── Step 2: Create campaign via UI ────────────────────────────────────────
    $page = visit('https://slimtds.local/admin/campaigns/new');
    $page->fill('name', 'E2E Full')->fill('slug', 'e2efull')->press('button[type=submit]');
    $page->assertPathIs('/admin/campaigns');

    // ── Step 3: Pull campaign id via direct PDO ───────────────────────────────
    // Direct DB inserts for offer + flow (walking the full nested UI is comprehensive
    // but redundant for this smoke test; the CRUD UI is covered in CampaignsBrowser.test.php)
    $pdo = new PDO(
        'pgsql:host=db;port=5432;dbname=slimtds',
        'slimtds',
        'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
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
    // The engine returns 302, so file_get_contents will follow the redirect (or return empty body);
    // we care only that the request was processed and a click was logged.
    stream_context_set_default(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    @file_get_contents('https://slimtds.local/e2efull');

    $clicks = (int) $pdo->query("SELECT count(*) FROM stats.clicks WHERE campaign_id = '{$cid}'")->fetchColumn();
    expect($clicks)->toBeGreaterThan(0);

    // ── Step 6: POST a postback with click_id + token → verify conversion ─────
    $clickId = (string) $pdo->query(
        "SELECT id FROM stats.clicks WHERE campaign_id = '{$cid}' ORDER BY created_at DESC LIMIT 1",
    )->fetchColumn();

    // Fire the postback endpoint
    @file_get_contents(
        "https://slimtds.local/postback?subid={$clickId}&token={$tok}&payout=10.00&status=approved",
    );

    $convCount = (int) $pdo->query(
        "SELECT count(*) FROM core.conversions WHERE click_id = '{$clickId}'",
    )->fetchColumn();
    expect($convCount)->toBe(1);

    // ── Step 7: Visit /admin/conversions and assertSee the payout ─────────────
    $page = visit('https://slimtds.local/admin/conversions');
    $page->assertSee('10.00');
});
