<?php

declare(strict_types=1);

use App\Shared\System\SystemStats;

test('snapshot returns disk/mem/load keys', function (): void {
    $s = SystemStats::snapshot();
    expect($s)->toHaveKeys(['disk', 'mem', 'load']);
});

test('disk stats are sane when available', function (): void {
    $disk = SystemStats::snapshot()['disk'];
    // disk_*_space works on any normal filesystem (incl. the test container).
    expect($disk)->not->toBeNull();
    expect($disk['total'])->toBeGreaterThan(0);
    expect($disk['used'])->toBeLessThanOrEqual($disk['total']);
    expect($disk['pct'])->toBeGreaterThanOrEqual(0)->and($disk['pct'])->toBeLessThanOrEqual(100);
});
