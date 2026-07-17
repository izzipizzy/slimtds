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
    $this->campaign = $camps->create(['name' => 'Test', 'slug' => 'flowtc', 'is_active' => '1']);
    $this->offers = new OfferRepository($this->db);
    $this->offer = $this->offers->create(['name' => 'Off1', 'url' => 'https://example.com', 'is_active' => '1']);
    $this->repo = new FlowRepository($this->db);
});

test('create stores filters and target_offers as jsonb', function (): void {
    $schemaConfig = ['status_code' => 302];

    $f = $this->repo->create($this->campaign->id, [
        'name' => 'F1',
        'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'RU']]],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $this->offer->id, 'weight' => 100]],
        'schema_id' => 2,
        'schema_config' => $schemaConfig,
        'weight' => 100,
        'is_active' => '1',
    ]);

    expect($f->name)->toBe('F1');
    // PG jsonb re-orders keys alphabetically; check values, not exact key order
    expect($f->filters[0][0]['field'])->toBe('country');
    expect($f->filters[0][0]['op'])->toBe('eq');
    expect($f->filters[0][0]['value'])->toBe('RU');
    expect($f->targetOffers[0]['offer_id'])->toBe($this->offer->id);
    expect($f->targetOffers[0]['weight'])->toBe(100);
    expect($f->schemaId)->toBe(2);
    expect($f->schemaConfig)->toBe($schemaConfig);
});

test('accepts filters as JSON string (from form submit)', function (): void {
    $filtersJson = json_encode([[['field' => 'device', 'op' => 'eq', 'value' => 'mobile']]]);
    $f = $this->repo->create($this->campaign->id, [
        'name' => 'F2',
        'filters' => $filtersJson,
        'target_type' => 'none',
        'target_offers' => '[]',
        'schema_id' => 9,
        'schema_config' => '{"body":"<h1>hi</h1>"}',
        'is_active' => '1',
    ]);
    expect($f->filters[0][0]['field'])->toBe('device');
    expect($f->schemaConfig['body'])->toBe('<h1>hi</h1>');
});

test('position auto-increments', function (): void {
    $a = $this->repo->create($this->campaign->id, ['name' => 'A', 'target_type' => 'none', 'schema_id' => 2]);
    $b = $this->repo->create($this->campaign->id, ['name' => 'B', 'target_type' => 'none', 'schema_id' => 2]);
    $c = $this->repo->create($this->campaign->id, ['name' => 'C', 'target_type' => 'none', 'schema_id' => 2]);
    expect($a->position)->toBe(1);
    expect($b->position)->toBe(2);
    expect($c->position)->toBe(3);
});

test('forCampaign orders by position', function (): void {
    $a = $this->repo->create($this->campaign->id, ['name' => 'A', 'target_type' => 'none', 'schema_id' => 2]);
    $b = $this->repo->create($this->campaign->id, ['name' => 'B', 'target_type' => 'none', 'schema_id' => 2]);
    $list = $this->repo->forCampaign($this->campaign->id);
    expect($list[0]->name)->toBe('A');
    expect($list[1]->name)->toBe('B');
});

test('reorder updates positions in transaction', function (): void {
    $a = $this->repo->create($this->campaign->id, ['name' => 'A', 'target_type' => 'none', 'schema_id' => 2]);
    $b = $this->repo->create($this->campaign->id, ['name' => 'B', 'target_type' => 'none', 'schema_id' => 2]);
    $c = $this->repo->create($this->campaign->id, ['name' => 'C', 'target_type' => 'none', 'schema_id' => 2]);

    $this->repo->reorder($this->campaign->id, [$c->id, $a->id, $b->id]);
    $list = $this->repo->forCampaign($this->campaign->id);
    expect($list[0]->name)->toBe('C');
    expect($list[1]->name)->toBe('A');
    expect($list[2]->name)->toBe('B');
});

test('update replaces all fields', function (): void {
    $f = $this->repo->create($this->campaign->id, ['name' => 'Old', 'target_type' => 'none', 'schema_id' => 2]);
    $u = $this->repo->update($f->id, [
        'name' => 'New',
        'filters' => [[['field' => 'country', 'op' => 'in', 'value' => ['RU', 'UA']]]],
        'target_type' => 'offers',
        'target_offers' => [['offer_id' => $this->offer->id, 'weight' => 50]],
        'schema_id' => 9,
        'schema_config' => ['body' => 'x'],
        'weight' => 50,
        'is_active' => '1',
    ]);
    expect($u->name)->toBe('New');
    expect($u->schemaId)->toBe(9);
    expect($u->filters[0][0]['value'])->toBe(['RU', 'UA']);
});

test('cascading delete from campaign removes flows', function (): void {
    $this->repo->create($this->campaign->id, ['name' => 'A', 'target_type' => 'none', 'schema_id' => 2]);
    $this->db->execute('DELETE FROM core.campaigns WHERE id = :id', ['id' => $this->campaign->id]);
    expect($this->repo->forCampaign($this->campaign->id))->toBeEmpty();
});

test('countsByCampaign works', function (): void {
    $this->repo->create($this->campaign->id, ['name' => 'A', 'target_type' => 'none', 'schema_id' => 2]);
    $this->repo->create($this->campaign->id, ['name' => 'B', 'target_type' => 'none', 'schema_id' => 2]);
    $counts = $this->repo->countsByCampaign([$this->campaign->id]);
    expect($counts[$this->campaign->id])->toBe(2);
});
