<?php

declare(strict_types=1);

use App\Mcp\IpMask;

test('ipv4 is cut to /24', fn () => expect(IpMask::mask('203.0.113.77'))->toBe('203.0.113.0/24'));
test('ipv6 is cut to /48', fn () => expect(IpMask::mask('2001:db8:abcd:12:34:56:78:9a'))->toBe('2001:db8:abcd::/48'));
test('null and garbage stay null', function (): void {
    expect(IpMask::mask(null))->toBeNull();
    expect(IpMask::mask('not-an-ip'))->toBeNull();
});
