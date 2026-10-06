<?php

declare(strict_types=1);

namespace App\Mcp;

/** Report data leaves for an LLM provider; by default an address is cut to its network. */
final class IpMask
{
    public static function mask(?string $ip): ?string
    {
        if ($ip === null || ($bin = @inet_pton($ip)) === false) {
            return null;
        }
        if (strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") . '/24';
        }
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }
}
