<?php

declare(strict_types=1);

use App\Admin\Command\AdminInitCommand;
use App\Admin\Command\AdminSetPasswordCommand;
use App\Admin\Command\DbSeedCommand;
use App\Admin\Command\DemoResetCommand;
use App\Admin\Command\SeedStatsCommand;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Auth\PasswordHasher;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Stats\StatsRepository;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('TRUNCATE stats.clicks, stats.pixel_events, stats.visitors_fingerprints, core.conversions CASCADE');
    $pdo->exec('TRUNCATE core.sessions, core.rate_limits, core.auth_events, core.cron_runs, stats.pixel_events_inbox');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $pdo->exec('DELETE FROM core.admins');
    $pdo->exec('DELETE FROM core.settings');
    $this->db = new Connection($pdo);
    $hasher = new PasswordHasher();

    // demo creds for the reset
    putenv('ADMIN_LOGIN=demo');
    putenv('ADMIN_PASSWORD=demopass');
    $_ENV['ADMIN_LOGIN'] = 'demo';
    $_ENV['ADMIN_PASSWORD'] = 'demopass';
    putenv('DEMO_MODE'); // unset by default
    unset($_ENV['DEMO_MODE']);

    $app = new Application();
    $demoReset = new DemoResetCommand($this->db);
    $app->add($demoReset);
    $app->add(new DbSeedCommand(
        $this->db,
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new OfferRepository($this->db),
        new FlowRepository($this->db),
    ));
    $app->add(new SeedStatsCommand($this->db, new Partitions($pdo), new StatsRepository($this->db)));
    $app->add(new AdminInitCommand($pdo, $hasher));
    $app->add(new AdminSetPasswordCommand($pdo, $hasher));
    $this->tester = new CommandTester($demoReset);
});

afterEach(function (): void {
    putenv('DEMO_MODE');
    unset($_ENV['DEMO_MODE']);
    putenv('ADMIN_LOGIN');
    unset($_ENV['ADMIN_LOGIN']);
    putenv('ADMIN_PASSWORD');
    unset($_ENV['ADMIN_PASSWORD']);
});

test('refuses to run without DEMO_MODE and changes nothing', function (): void {
    // seed a marker campaign directly
    $this->db->execute(
        "INSERT INTO core.campaigns (slug, name, is_active, trash_mode) VALUES ('marker', 'Marker', true, 0)"
    );
    $before = (int) $this->db->fetchScalar('SELECT count(*) FROM core.campaigns');

    $code = $this->tester->execute([]); // DEMO_MODE unset
    expect($code)->toBe(Command::FAILURE);

    $after = (int) $this->db->fetchScalar('SELECT count(*) FROM core.campaigns');
    expect($after)->toBe($before);
    expect((int) $this->db->fetchScalar("SELECT count(*) FROM core.campaigns WHERE slug = 'marker'"))->toBe(1);
});

test('runs when DEMO_MODE=1', function (): void {
    putenv('DEMO_MODE=1');
    $_ENV['DEMO_MODE'] = '1';
    $code = $this->tester->execute([]);
    expect($code)->toBe(0);
});

test('restores the curated config and stats baseline', function (): void {
    putenv('DEMO_MODE=1');
    $_ENV['DEMO_MODE'] = '1';

    // dirty state: a junk campaign that must be wiped
    $this->db->execute(
        "INSERT INTO core.campaigns (slug, name, is_active, trash_mode) VALUES ('junkxx', 'Junk', true, 0)"
    );

    $code = $this->tester->execute([]);
    expect($code)->toBe(0);

    // curated 4 campaigns restored, junk gone
    $slugs = array_column($this->db->fetchAll('SELECT slug FROM core.campaigns ORDER BY slug'), 'slug');
    expect($slugs)->toEqualCanonicalizing(['casino', 'dating', 'nutra', 'site']);

    // stats seeded
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks'))->toBeGreaterThan(0);
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.conversions'))->toBeGreaterThan(0);
});

test('clears traces, resets the demo admin, and wipes settings', function (): void {
    putenv('DEMO_MODE=1');
    $_ENV['DEMO_MODE'] = '1';

    // dirty state: junk traces + a changed admin + a junk setting
    $this->db->execute("INSERT INTO core.auth_events (event_type, ip) VALUES ('login_success', '1.2.3.4')");
    $this->db->execute("INSERT INTO core.settings (key, value) VALUES ('retention_clicks_days', '7')");
    $this->db->execute(
        "INSERT INTO core.admins (login, password_hash, must_change_password) VALUES ('demo', 'STALEHASH', true)"
    );

    $code = $this->tester->execute([]);
    expect($code)->toBe(0);

    // traces cleared
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.auth_events'))->toBe(0);
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.sessions'))->toBe(0);
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.rate_limits'))->toBe(0);

    // settings wiped to defaults (empty table → code defaults)
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.settings'))->toBe(0);

    // admin reset to the public demo creds, login enabled
    $admin = $this->db->fetchOne("SELECT password_hash, must_change_password FROM core.admins WHERE login = 'demo'");
    expect($admin)->not->toBeNull();
    expect((new PasswordHasher())->verify('demopass', (string) $admin['password_hash']))->toBeTrue();
    expect($admin['must_change_password'])->toBeFalse();
});

test('is idempotent — a second reset reproduces the same baseline', function (): void {
    putenv('DEMO_MODE=1');
    $_ENV['DEMO_MODE'] = '1';

    $this->tester->execute([]);
    $firstCampaigns = (int) $this->db->fetchScalar('SELECT count(*) FROM core.campaigns');
    $firstClicks = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');

    $this->tester->execute([]);
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM core.campaigns'))->toBe($firstCampaigns);
    expect((int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks'))->toBe($firstClicks);
});
