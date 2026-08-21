<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolve the real client IP from PSR-7 request, walking trust headers.
 * Order:
 *   1. X-Real-IP — set by a trusted upstream nginx that already resolved the
 *      real client IP (typically via ngx_http_realip_module + CF). Has to come
 *      before CF-Connecting-IP because the proxied flow
 *         client → CF → host nginx → CF → slimTDS
 *      makes the second CF hop overwrite CF-Connecting-IP with the host nginx
 *      IP, while X-Real-IP is set by host nginx with the real client IP and
 *      passes through the second CF hop intact.
 *   2. CF-Connecting-IP — Cloudflare's actual TCP peer for direct (non-proxied)
 *      tds.example.com hits.
 *   3. True-Client-IP — alternative CF header (Enterprise plans), kept as a
 *      best-effort fallback.
 *   4. X-Forwarded-For — first IP in the comma-separated chain.
 *   5. REMOTE_ADDR — last resort (dev / no proxy).
 *
 * Note: X-Real-IP can be spoofed by anyone hitting the public endpoint
 * directly, so this layer is "best effort attribution", not a security
 * boundary. Adequate for analytics / pixel events.
 */
final class RealIp
{
    public static function from(ServerRequestInterface $request): string
    {
        foreach (['X-Slim-IP', 'X-Real-IP', 'CF-Connecting-IP', 'True-Client-IP'] as $h) {
            $v = trim($request->getHeaderLine($h));
            if ($v !== '' && self::isValid($v)) {
                return self::strip($v);
            }
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '' && self::isValid($first)) {
                return self::strip($first);
            }
        }

        $params = $request->getServerParams();
        $r = (string)($params['REMOTE_ADDR'] ?? '0.0.0.0');
        return self::strip($r);
    }

    private static function isValid(string $ip): bool
    {
        $ip = self::strip($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Drop CIDR suffix (`172.64.223.69/32` → `172.64.223.69`) and any port. */
    private static function strip(string $ip): string
    {
        $ip = trim($ip);
        if (($slash = strpos($ip, '/')) !== false) {
            $ip = substr($ip, 0, $slash);
        }
        // IPv4 with port: 1.2.3.4:5678
        if (substr_count($ip, ':') === 1 && filter_var(substr($ip, 0, strpos($ip, ':')), FILTER_VALIDATE_IP)) {
            $ip = substr($ip, 0, strpos($ip, ':'));
        }
        return $ip;
    }
}
