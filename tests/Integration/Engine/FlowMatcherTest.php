<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Engine\Context;
use App\Engine\FilterCompiler;
use App\Engine\FlowMatcher;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $this->camps = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->flows = new FlowRepository($this->db);
    $this->matcher = new FlowMatcher($this->flows, new FilterCompiler());
    $this->camp = $this->camps->create(['name' => 'C', 'slug' => 'fmatch', 'is_active' => '1']);
});

test('first matching flow wins by position', function (): void {
    $this->flows->create($this->camp->id, [
        'name' => 'RU only',
        'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'ru']]],
        'target_type' => 'none', 'schema_id' => 2,
        'is_active' => '1',
    ]);
    $this->flows->create($this->camp->id, [
        'name' => 'Catch-all',
        'filters' => [],
        'target_type' => 'none', 'schema_id' => 14,
        'is_active' => '1',
    ]);

    $ru = new Context('1.1.1.1', 'curl', 'fmatch', time());
    $ru->country = 'ru';
    $matched = $this->matcher->match($this->camp->id, $ru);
    expect($matched->name)->toBe('RU only');

    $us = new Context('2.2.2.2', 'curl', 'fmatch', time());
    $us->country = 'us';
    $matched2 = $this->matcher->match($this->camp->id, $us);
    expect($matched2->name)->toBe('Catch-all');
});

test('returns null when no flow matches', function (): void {
    $this->flows->create($this->camp->id, [
        'name' => 'RU',
        'filters' => [[['field' => 'country', 'op' => 'eq', 'value' => 'ru']]],
        'target_type' => 'none', 'schema_id' => 2,
        'is_active' => '1',
    ]);
    $ctx = new Context('1.1.1.1', 'curl', 'fmatch', time());
    $ctx->country = 'us';
    expect($this->matcher->match($this->camp->id, $ctx))->toBeNull();
});

test('inactive flow skipped', function (): void {
    $this->flows->create($this->camp->id, [
        'name' => 'inactive',
        'filters' => [],
        'target_type' => 'none', 'schema_id' => 2,
        'is_active' => '0',
    ]);
    $ctx = new Context('1.1.1.1', 'curl', 'fmatch', time());
    expect($this->matcher->match($this->camp->id, $ctx))->toBeNull();
});
