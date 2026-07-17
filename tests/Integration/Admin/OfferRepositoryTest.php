<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->campaign = $camps->create(['name' => 'Test', 'slug' => 'offer1', 'is_active' => '1']);
    $this->repo = new OfferRepository($this->db);
    $this->flows = new FlowRepository($this->db);
});

test('create generates postback_token', function (): void {
    $o = $this->repo->create(['name' => 'A', 'url' => 'https://example.com', 'currency' => 'USD', 'is_active' => '1']);
    expect($o->postbackToken)->toMatch('/^[0-9a-f]{32}$/');
});

test('create stores payout and currency', function (): void {
    $o = $this->repo->create(['name' => 'B', 'url' => 'https://x.com', 'payout_default' => '10.50', 'currency' => 'EUR', 'is_active' => '1']);
    expect($o->payoutDefault)->toBe('10.50');
    expect($o->currency)->toBe('EUR');
});

test('forCampaign returns offers wired through campaign flows', function (): void {
    $camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $other = $camps->create(['name' => 'Other', 'slug' => 'othr01']);
    $a = $this->repo->create(['name' => 'X', 'url' => 'https://a.com', 'is_active' => '1']);
    $b = $this->repo->create(['name' => 'Y', 'url' => 'https://b.com', 'is_active' => '1']);
    $c = $this->repo->create(['name' => 'Z', 'url' => 'https://c.com', 'is_active' => '1']);

    $this->flows->create($this->campaign->id, [
        'name' => 'F1', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $a->id, 'weight' => 50], ['offer_id' => $b->id, 'weight' => 50]],
        'schema_id' => 2, 'is_active' => '1',
    ]);
    $this->flows->create($other->id, [
        'name' => 'F2', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $c->id, 'weight' => 100]],
        'schema_id' => 2, 'is_active' => '1',
    ]);

    expect($this->repo->forCampaign($this->campaign->id))->toHaveCount(2);
    expect($this->repo->forCampaign($other->id))->toHaveCount(1);
});

test('forCampaign skips empty/non-uuid offer_id targets without throwing', function (): void {
    $a = $this->repo->create(['name' => 'X', 'url' => 'https://a.com', 'is_active' => '1']);
    // A target row saved without an offer picked leaves {"offer_id": ""} in the JSONB —
    // a bare ::uuid cast throws 22P02 on that empty string. forCampaign must skip it.
    $this->flows->create($this->campaign->id, [
        'name' => 'F1', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $a->id, 'weight' => 50], ['offer_id' => '', 'weight' => 50]],
        'schema_id' => 2, 'is_active' => '1',
    ]);
    $offers = $this->repo->forCampaign($this->campaign->id);
    expect($offers)->toHaveCount(1);
    expect($offers[0]->id)->toBe($a->id);
});

test('forCampaign supports cross-campaign offer reuse', function (): void {
    $camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $other = $camps->create(['name' => 'Other', 'slug' => 'shar01']);
    $shared = $this->repo->create(['name' => 'Shared', 'url' => 'https://s.com', 'is_active' => '1']);

    $this->flows->create($this->campaign->id, [
        'name' => 'F1', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $shared->id, 'weight' => 100]],
        'schema_id' => 2, 'is_active' => '1',
    ]);
    $this->flows->create($other->id, [
        'name' => 'F2', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $shared->id, 'weight' => 100]],
        'schema_id' => 2, 'is_active' => '1',
    ]);

    expect($this->repo->forCampaign($this->campaign->id))->toHaveCount(1);
    expect($this->repo->forCampaign($other->id))->toHaveCount(1);
    expect($this->repo->flowReferenceCount($shared->id))->toBe(2);
});

test('countsByCampaign counts distinct offers across flows', function (): void {
    $camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $other = $camps->create(['name' => 'Other', 'slug' => 'othr02']);
    $a = $this->repo->create(['name' => 'X', 'url' => 'https://a.com']);
    $b = $this->repo->create(['name' => 'Y', 'url' => 'https://b.com']);
    $c = $this->repo->create(['name' => 'Z', 'url' => 'https://c.com']);

    $this->flows->create($this->campaign->id, [
        'name' => 'F1', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $a->id, 'weight' => 50], ['offer_id' => $b->id, 'weight' => 50]],
        'schema_id' => 2, 'is_active' => '1',
    ]);
    $this->flows->create($other->id, [
        'name' => 'F2', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $c->id, 'weight' => 100]],
        'schema_id' => 2, 'is_active' => '1',
    ]);

    $counts = $this->repo->countsByCampaign([$this->campaign->id, $other->id]);
    expect($counts[$this->campaign->id])->toBe(2);
    expect($counts[$other->id])->toBe(1);
});

test('rotateToken changes token', function (): void {
    $o = $this->repo->create(['name' => 'R', 'url' => 'https://r.com']);
    $origTok = $o->postbackToken;
    $newTok = $this->repo->rotateToken($o->id);
    expect($newTok)->not->toBe($origTok);
    expect($newTok)->toMatch('/^[0-9a-f]{32}$/');
});

test('delete removes offer', function (): void {
    $o = $this->repo->create(['name' => 'D', 'url' => 'https://d.com']);
    expect($this->repo->delete($o->id))->toBeTrue();
    expect($this->repo->findById($o->id))->toBeNull();
});

test('campaign deletion does NOT cascade to global offers', function (): void {
    $o = $this->repo->create(['name' => 'C', 'url' => 'https://c.com']);
    $this->flows->create($this->campaign->id, [
        'name' => 'F', 'filters' => [], 'target_type' => 'offers',
        'target_offers' => [['offer_id' => $o->id, 'weight' => 100]],
        'schema_id' => 2, 'is_active' => '1',
    ]);
    $this->db->execute('DELETE FROM core.campaigns WHERE id = :id', ['id' => $this->campaign->id]);
    expect($this->repo->findById($o->id))->not->toBeNull();
});
