<?php

declare(strict_types=1);

if (getenv('BROWSER_TESTS') !== '1') {
    test('engine browser e2e skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}


beforeAll(function (): void {
    $pdo = browserPdo();
    browserAdmin('e2e-pass');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
});

test('admin creates campaign+offer+flow → /<slug> redirects → click logged', function (): void {
    $page = visit(browserUrl('/admin/login'));
    $page->fill('login', 'admin')->fill('password', 'e2e-pass')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    $page->navigate(browserUrl('/admin/campaigns/new'));
    $page->fill('name', 'E2E')->fill('slug', 'e2e001')->press('button[type=submit]');
    $page->assertPathIs('/admin/campaigns');

    // Get id of e2e001 from DB and create offer + flow via direct insert (faster than walking UI)
    $pdo = browserPdo();
    $cid = $pdo->query("SELECT id FROM core.campaigns WHERE slug='e2e001'")->fetchColumn();
    $pdo->exec("INSERT INTO core.offers (id, name, url, is_active) VALUES (gen_random_uuid()::uuid, 'O', 'https://example.com/?cid={click_id}', true)");
    $oid = $pdo->query("SELECT id FROM core.offers WHERE name='O' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO core.flows (id, campaign_id, name, filters, target_type, target_offers, schema_id, is_active) VALUES (gen_random_uuid()::uuid, '{$cid}', 'F', '[]'::jsonb, 'offers', '[{\"offer_id\":\"{$oid}\",\"weight\":100}]'::jsonb, 2, true)");

    // Hit the engine
    $r = file_get_contents(browserUrl('/e2e001'), false, stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]));
    expect(http_get_last_response_headers()[0] ?? '')->toContain('302');

    // Verify click logged
    $count = (int)$pdo->query("SELECT count(*) FROM stats.clicks WHERE campaign_id='{$cid}'")->fetchColumn();
    expect($count)->toBeGreaterThan(0);
});
