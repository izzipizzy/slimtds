<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.campaigns');
    $this->db = new Connection($pdo);
    $this->repo = new CampaignRepository($this->db, new CampaignIdGenerator());
});

test('create with auto-generated slug', function (): void {
    $c = $this->repo->create(['name' => 'Test Campaign', 'is_active' => '1']);
    expect($c->name)->toBe('Test Campaign');
    expect($c->slug)->toHaveLength(6);
    expect($c->isActive)->toBeTrue();
});

test('create with custom slug', function (): void {
    $c = $this->repo->create(['name' => 'Custom', 'slug' => 'myslug01', 'is_active' => '1']);
    expect($c->slug)->toBe('myslug01');
});

test('slug uniqueness enforced on insert (via PG constraint)', function (): void {
    $this->repo->create(['name' => 'A', 'slug' => 'dupeslug']);
    $this->repo->create(['name' => 'B', 'slug' => 'dupeslug']);
})->throws(PDOException::class);

test('findBySlug works', function (): void {
    $this->repo->create(['name' => 'Hello', 'slug' => 'hello0']);
    $c = $this->repo->findBySlug('hello0');
    expect($c)->not->toBeNull();
    expect($c->name)->toBe('Hello');
});

test('update changes fields and updates updated_at', function (): void {
    $c = $this->repo->create(['name' => 'Old', 'slug' => 'upd001']);
    $updated = $this->repo->update($c->id, [
        'name' => 'New',
        'slug' => 'upd001',
        'is_active' => '1',
        'trash_mode' => 0,
    ]);
    expect($updated->name)->toBe('New');
    expect($updated->updatedAt >= $c->updatedAt)->toBeTrue();
});

test('delete removes the row', function (): void {
    $c = $this->repo->create(['name' => 'Del', 'slug' => 'del001']);
    expect($this->repo->delete($c->id))->toBeTrue();
    expect($this->repo->findById($c->id))->toBeNull();
});

test('page + count paginate correctly', function (): void {
    for ($i = 1; $i <= 7; $i++) {
        $this->repo->create(['name' => "C{$i}", 'slug' => sprintf('pg%04d', $i)]);
    }
    expect($this->repo->count())->toBe(7);
    expect($this->repo->page(1, 3))->toHaveCount(3);
    expect($this->repo->page(3, 3))->toHaveCount(1);
});

test('page search filters by name or slug ILIKE', function (): void {
    $this->repo->create(['name' => 'Alpha project', 'slug' => 'aaaaaa']);
    $this->repo->create(['name' => 'Beta project', 'slug' => 'bbbbbb']);
    $this->repo->create(['name' => 'Gamma launch', 'slug' => 'gamma1']);

    expect($this->repo->page(1, 10, 'project'))->toHaveCount(2);
    expect($this->repo->page(1, 10, 'gamma'))->toHaveCount(1);
    expect($this->repo->count('project'))->toBe(2);
});

test('slugExists check', function (): void {
    $c = $this->repo->create(['name' => 'X', 'slug' => 'exists00']);
    expect($this->repo->slugExists('exists00'))->toBeTrue();
    expect($this->repo->slugExists('exists00', $c->id))->toBeFalse();  // excludes own
    expect($this->repo->slugExists('nothere'))->toBeFalse();
});
