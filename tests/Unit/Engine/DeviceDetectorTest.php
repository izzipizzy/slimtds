<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\DeviceDetector;

dataset('user-agents', [
    'iPhone 15 Safari' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        'mobile', 'iOS', 'Safari',
    ],
    'iPad Safari' => [
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1',
        'tablet', 'iOS', 'Safari',
    ],
    'Android Chrome' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
        'mobile', 'Android', 'Chrome',
    ],
    'Windows Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        'desktop', 'Windows', 'Chrome',
    ],
    'macOS Firefox' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0) Gecko/20100101 Firefox/121.0',
        'desktop', 'macOS', 'Firefox',
    ],
    'curl' => [
        'curl/8.0',
        'desktop', null, null,
    ],
]);

test('detects device/os/browser', function (string $ua, string $device, ?string $os, ?string $browser): void {
    $ctx = new Context('0.0.0.0', $ua, 'demo', time());
    (new DeviceDetector())->detect($ctx, 'ru-RU,en;q=0.9');
    expect($ctx->device)->toBe($device);
    expect($ctx->os)->toBe($os);
    expect($ctx->browser)->toBe($browser);
    expect($ctx->lang)->toBe('ru');
})->with('user-agents');
