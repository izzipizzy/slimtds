<?php

declare(strict_types=1);

use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec("DELETE FROM core.settings WHERE key LIKE 'notif_%' OR key = 'unit_test_bool'");
    $this->repo = new SettingsRepository(new Connection($pdo));
});

test('getBool returns the default when the key is absent', function (): void {
    expect($this->repo->getBool('unit_test_bool', true))->toBeTrue();
    expect($this->repo->getBool('unit_test_bool', false))->toBeFalse();
});

test('getBool reads stored 1/0 regardless of default', function (): void {
    $this->repo->set('unit_test_bool', '1');
    expect($this->repo->getBool('unit_test_bool', false))->toBeTrue();
    $this->repo->set('unit_test_bool', '0');
    expect($this->repo->getBool('unit_test_bool', true))->toBeFalse();
});

test('notification settings round-trip through set/get', function (): void {
    $this->repo->set('notif_ai_click_enabled', '0');
    $this->repo->set('notif_ai_click_sources', json_encode(['google', 'bing']));
    $this->repo->set('notif_ai_click_template', 'hi {campaign}');

    expect($this->repo->getBool('notif_ai_click_enabled', true))->toBeFalse();
    expect(json_decode($this->repo->get('notif_ai_click_sources'), true))->toBe(['google', 'bing']);
    expect($this->repo->get('notif_ai_click_template'))->toBe('hi {campaign}');
});
