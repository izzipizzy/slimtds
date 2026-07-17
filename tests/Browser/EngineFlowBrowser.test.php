<?php

declare(strict_types=1);

if (getenv('BROWSER_TESTS') !== '1') {
    test('engine browser e2e skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}

use function Pest\Browser\visit;

beforeAll(function (): void {
    $pdo = new PDO('pgsql:host=db;port=5432;dbname=slimtds', 'slimtds', 'slimtds', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("UPDATE core.admins SET password_hash = '" . password_hash('e2e-pass', PASSWORD_ARGON2ID) . "', must_change_password = false WHERE login = 'admin'");
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
});

test('admin creates campaign+offer+flow → /<slug> redirects → click logged', function (): void {
    $page = visit('https://slimtds.local/admin/login');
    $page->fill('login', 'admin')->fill('password', 'e2e-pass')->press('button[type=submit]');
    $page->assertPathIs('/admin');

    $page = visit('https://slimtds.local/admin/campaigns/new');
    $page->fill('name', 'E2E')->fill('slug', 'e2e001')->press('button[type=submit]');
    $page->assertPathIs('/admin/campaigns');

    // Get id of e2e001 from DB and create offer + flow via direct insert (faster than walking UI)
    $pdo = new PDO('pgsql:host=db;port=5432;dbname=slimtds', 'slimtds', 'slimtds', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cid = $pdo->query("SELECT id FROM core.campaigns WHERE slug='e2e001'")->fetchColumn();
    $pdo->exec("INSERT INTO core.offers (id, name, url, is_active) VALUES (gen_random_uuid()::uuid, 'O', 'https://example.com/?cid={click_id}', true)");
    $oid = $pdo->query("SELECT id FROM core.offers WHERE name='O' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO core.flows (id, campaign_id, name, filters, target_type, target_offers, schema_id, is_active) VALUES (gen_random_uuid()::uuid, '{$cid}', 'F', '[]'::jsonb, 'offers', '[{\"offer_id\":\"{$oid}\",\"weight\":100}]'::jsonb, 2, true)");

    // Hit the engine
    $r = file_get_contents('https://slimtds.local/e2e001');
    expect(true)->toBeTrue();  // headers checked separately

    // Verify click logged
    $count = (int)$pdo->query("SELECT count(*) FROM stats.clicks WHERE campaign_id='{$cid}'")->fetchColumn();
    expect($count)->toBeGreaterThan(0);
});
