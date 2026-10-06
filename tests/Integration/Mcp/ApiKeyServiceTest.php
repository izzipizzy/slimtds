<?php

declare(strict_types=1);

use App\Admin\Repository\SettingsRepository;
use App\Mcp\ApiKeyService;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    $this->db->execute("DELETE FROM core.settings WHERE key LIKE 'mcp_%'");
    $this->settings = new SettingsRepository($this->db);
    $this->keys = new ApiKeyService($this->settings);
});

test('disabled until a key is generated', function (): void {
    expect($this->keys->isEnabled())->toBeFalse();
    expect($this->keys->verify('stds_anything'))->toBeFalse();
});

test('generate returns a stds_ key, stores only its hash, and verifies it', function (): void {
    $key = $this->keys->generate();
    expect($key)->toMatch('/^stds_[0-9A-Za-z]{40}$/');
    expect($this->settings->get('mcp_key_hash'))->toBe(hash('sha256', $key));
    $all = implode('|', array_map('strval', $this->settings->all()));
    expect($all)->not->toContain($key);
    expect($this->keys->verify($key))->toBeTrue();
    expect($this->keys->verify($key . 'x'))->toBeFalse();
    expect($this->keys->status()['prefix'])->toBe(substr($key, 0, 9));
});

test('regenerating invalidates the previous key', function (): void {
    $old = $this->keys->generate();
    $new = $this->keys->generate();
    expect($this->keys->verify($old))->toBeFalse();
    expect($this->keys->verify($new))->toBeTrue();
});

test('revoke disables the endpoint', function (): void {
    $key = $this->keys->generate();
    $this->keys->revoke();
    expect($this->keys->isEnabled())->toBeFalse();
    expect($this->keys->verify($key))->toBeFalse();
});

test('touch writes last_used_at at most once a minute', function (): void {
    $this->keys->generate();
    $this->keys->touch();
    $first = $this->settings->get('mcp_key_last_used_at');
    expect($first)->not->toBe('');
    $this->keys->touch();
    expect($this->settings->get('mcp_key_last_used_at'))->toBe($first);
});

test('full ip option defaults to off', function (): void {
    expect($this->keys->fullIp())->toBeFalse();
    $this->keys->setFullIp(true);
    expect($this->keys->fullIp())->toBeTrue();
});
