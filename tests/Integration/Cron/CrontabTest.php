<?php

declare(strict_types=1);

test('crontab file exists and has at least two job lines', function (): void {
    $path = dirname(__DIR__, 3) . '/docker/supercronic/crontab';
    expect(is_file($path))->toBeTrue();

    $lines = array_values(array_filter(
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        fn ($l) => !str_starts_with(ltrim($l), '#'),
    ));

    expect(count($lines))->toBeGreaterThanOrEqual(2);

    // Each job line should have 5+1 fields (cron expr + command)
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line, 6);
        expect(count($parts))->toBeGreaterThanOrEqual(6, "bad cron line: {$line}");
    }
});

test('crontab references existing bin/console commands', function (): void {
    $path = dirname(__DIR__, 3) . '/docker/supercronic/crontab';
    $content = file_get_contents($path);
    expect($content)->toContain('partitions:rotate');
    expect($content)->toContain('rate_limits:cleanup');
});
