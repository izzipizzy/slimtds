<?php

declare(strict_types=1);

use App\Shared\Ua\BrowserLabel;

dataset('browser-uas', [
    'iOS Chrome (CriOS)' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/174.0.6 Mobile/15E148 Safari/604.1',
        'Chrome 174',
    ],
    'iOS Safari' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        'Safari 17',
    ],
    'iOS Firefox (FxiOS)' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 FxiOS/121.0 Mobile/15E148 Safari/605.1.15',
        'Firefox 121',
    ],
    'iOS Edge (EdgiOS)' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 EdgiOS/120.0 Mobile/15E148 Safari/605.1.15',
        'Edge 120',
    ],
    'Windows Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        'Chrome 120',
    ],
    'Android Chrome' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
        'Chrome 120',
    ],
    'Desktop Edge' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0',
        'Edge 120',
    ],
    'Desktop Opera' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0',
        'Opera 105',
    ],
    'macOS Firefox' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Firefox 121',
    ],
    'curl (no browser)' => ['curl/8.0', null],
    'empty' => ['', null],
]);

test('labels browser with major version', function (string $ua, ?string $expected): void {
    expect(BrowserLabel::make($ua))->toBe($expected);
})->with('browser-uas');
