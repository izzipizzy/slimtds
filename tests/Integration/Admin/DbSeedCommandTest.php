<?php

declare(strict_types=1);

use App\Admin\Command\DbSeedCommand;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('TRUNCATE stats.clicks, stats.pixel_events, stats.visitors_fingerprints, core.conversions CASCADE');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $cmd = new DbSeedCommand(
        $this->db,
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new OfferRepository($this->db),
        new FlowRepository($this->db),
    );
    $this->tester = new CommandTester($cmd);
});

test('seeds the four curated campaigns by slug', function (): void {
    $this->tester->execute(['--fresh' => true]);
    $slugs = $this->db->fetchAll('SELECT slug FROM core.campaigns ORDER BY slug');
    $slugs = array_column($slugs, 'slug');
    expect($slugs)->toEqualCanonicalizing(['casino', 'dating', 'nutra', 'site']);
});

test('seeds the five named global offers', function (): void {
    $this->tester->execute(['--fresh' => true]);
    $names = array_column($this->db->fetchAll('SELECT name FROM core.offers ORDER BY name'), 'name');
    expect($names)->toEqualCanonicalizing(['CIS Casino', 'Dating A', 'Dating B', 'EU Casino', 'Nutra COD']);
});

test('site campaign has no flows, others do', function (): void {
    $this->tester->execute(['--fresh' => true]);
    $site = $this->db->fetchScalar(
        "SELECT count(*) FROM core.flows f JOIN core.campaigns c ON c.id = f.campaign_id WHERE c.slug = 'site'"
    );
    $total = $this->db->fetchScalar('SELECT count(*) FROM core.flows');
    expect((int) $site)->toBe(0);
    expect((int) $total)->toBeGreaterThanOrEqual(7);
});
