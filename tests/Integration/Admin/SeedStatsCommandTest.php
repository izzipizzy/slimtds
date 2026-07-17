<?php

declare(strict_types=1);

use App\Admin\Command\DbSeedCommand;
use App\Admin\Command\SeedStatsCommand;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Stats\StatsRepository;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('TRUNCATE stats.clicks, stats.pixel_events, stats.visitors_fingerprints, core.conversions CASCADE');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);

    // config first
    (new CommandTester(new DbSeedCommand(
        $this->db,
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new OfferRepository($this->db),
        new FlowRepository($this->db),
    )))->execute(['--fresh' => true]);

    $this->stats = new CommandTester(new SeedStatsCommand(
        $this->db,
        new Partitions($pdo),
        new StatsRepository($this->db),
    ));
});

test('errors when config is absent', function (): void {
    $this->db->execute('DELETE FROM core.campaigns');
    $code = $this->stats->execute(['--fresh' => true]);
    expect($code)->not->toBe(0);
});

test('inserts clicks within the 30-day window', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $total = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');
    expect($total)->toBe(6000);

    $outOfWindow = (int) $this->db->fetchScalar(
        "SELECT count(*) FROM stats.clicks
         WHERE created_at < now() - interval '31 days' OR created_at > now()"
    );
    expect($outOfWindow)->toBe(0);
});

test('distributes clicks across campaigns 1-3 with dating largest, site empty', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $rows = $this->db->fetchAll(
        "SELECT c.slug, count(*) AS n FROM stats.clicks k
         JOIN core.campaigns c ON c.id = k.campaign_id GROUP BY c.slug"
    );
    $by = [];
    foreach ($rows as $r) { $by[$r['slug']] = (int) $r['n']; }
    expect($by['casino'] ?? 0)->toBeGreaterThan(0);
    expect($by['dating'] ?? 0)->toBeGreaterThan(0);
    expect($by['nutra'] ?? 0)->toBeGreaterThan(0);
    expect($by['site'] ?? 0)->toBe(0);
    expect($by['dating'])->toBeGreaterThan($by['casino']); // 50% > 30%
    expect($by['casino'])->toBeGreaterThan($by['nutra']);  // 30% > 20%
});

test('every click has a non-null ip and visitor', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $bad = (int) $this->db->fetchScalar(
        'SELECT count(*) FROM stats.clicks WHERE ip IS NULL OR visitor_uuid IS NULL'
    );
    expect($bad)->toBe(0);
});

test('creates conversions referencing real non-bot clicks with valid statuses', function (): void {
    $this->stats->execute(['--fresh' => true]);

    $conv = (int) $this->db->fetchScalar('SELECT count(*) FROM core.conversions');
    $nonBot = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks WHERE NOT is_bot');
    expect($conv)->toBeGreaterThan(0);
    expect($conv)->toBeLessThan($nonBot); // CR well under 100%

    // every conversion points at an existing, non-bot click
    $orphans = (int) $this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions cv
         LEFT JOIN stats.clicks c ON c.id = cv.click_id
         WHERE c.id IS NULL OR c.is_bot'
    );
    expect($orphans)->toBe(0);

    // seed intentionally emits only approved/pending/rejected ('hold' is a valid DB status but unused)
    $badStatus = (int) $this->db->fetchScalar(
        "SELECT count(*) FROM core.conversions WHERE status NOT IN ('approved','pending','rejected')"
    );
    expect($badStatus)->toBe(0);
    $badPayout = (int) $this->db->fetchScalar('SELECT count(*) FROM core.conversions WHERE payout <= 0');
    expect($badPayout)->toBe(0);

    // conversions never precede their click
    $earlyConv = (int) $this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions cv
         JOIN stats.clicks c ON c.id = cv.click_id
         WHERE cv.created_at < c.created_at'
    );
    expect($earlyConv)->toBe(0);
});

test('creates pixel events concentrated on the site campaign', function (): void {
    $this->stats->execute(['--fresh' => true]);

    $total = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.pixel_events');
    expect($total)->toBe(3000);

    $rows = $this->db->fetchAll(
        "SELECT c.slug, count(*) AS n FROM stats.pixel_events e
         JOIN core.campaigns c ON c.id = e.campaign_id GROUP BY c.slug ORDER BY n DESC"
    );
    expect($rows[0]['slug'])->toBe('site'); // site has the most pixel events

    // custom events exist alongside pageviews
    $custom = (int) $this->db->fetchScalar(
        "SELECT count(*) FROM stats.pixel_events WHERE event_name IN ('download_click','docs_view','demo_launch')"
    );
    expect($custom)->toBeGreaterThan(0);

    // events are within the window
    $bad = (int) $this->db->fetchScalar(
        "SELECT count(*) FROM stats.pixel_events
         WHERE created_at < now() - interval '31 days' OR created_at > now()"
    );
    expect($bad)->toBe(0);
});

test('writes visitor fingerprints that overlap the click visitor pool', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $fp = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.visitors_fingerprints');
    expect($fp)->toBeGreaterThan(0);

    // at least some fingerprint visitors also appear in clicks (journey linkage)
    $overlap = (int) $this->db->fetchScalar(
        'SELECT count(DISTINCT vf.visitor_uuid) FROM stats.visitors_fingerprints vf
         JOIN stats.clicks c ON c.visitor_uuid = vf.visitor_uuid'
    );
    expect($overlap)->toBeGreaterThan(0);
});

test('refreshes the clicks_hourly matview so statistics is non-empty', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $rows = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks_hourly');
    expect($rows)->toBeGreaterThan(0);
});

test('is idempotent without --fresh and re-anchors with --fresh', function (): void {
    $this->stats->execute(['--fresh' => true]);
    $first = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');

    // second run without --fresh must be a no-op
    $this->stats->execute([]);
    $afterNoop = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');
    expect($afterNoop)->toBe($first);

    // --fresh regenerates to the same shape (deterministic seed)
    $this->stats->execute(['--fresh' => true]);
    $afterFresh = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');
    expect($afterFresh)->toBe($first);
});
