<?php

declare(strict_types=1);

namespace App\Engine;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Header-derived context shared by the click hot path (Engine\ClickHandler)
 * and the pixel engagement resolver (Pixel\PlayController): referer plus the
 * X-Lander-* headers forwarded by SEO-sites' nginx. Both entry points must
 * read these identically so flow filters match the same way for a /play/*
 * click and for the pixel's mode request that armed it.
 */
final class LanderContext
{
    public static function apply(Context $ctx, ServerRequestInterface $request): void
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $ctx->referer = $referer;
            $host = parse_url($referer, PHP_URL_HOST);
            $ctx->refererDomain = is_string($host) ? strtolower($host) : null;
        }

        // Lander attribution: SEO-sites' nginx proxies /play/<button>/ → here and
        // forwards the original Host as X-Lander-Host and original path+query as
        // X-Lander-Path. Direct hits (without the proxy) leave both null.
        $landerHost = strtolower(trim($request->getHeaderLine('X-Lander-Host')));
        if ($landerHost !== '') {
            $ctx->landerHost = $landerHost;
            $h = preg_replace('/^www\./', '', $landerHost) ?? $landerHost;
            // Strip the rightmost label as the TLD ("lander.example.com" → "lander").
            // Compound TLDs (.co.uk, etc.) aren't currently in use across our SEO sites.
            $ctx->landerDomain = preg_match('/^(.+)\.[^.]+$/', $h, $m) ? $m[1] : $h;
        }
        $landerPath = $request->getHeaderLine('X-Lander-Path');
        if ($landerPath !== '') {
            $pathOnly = strstr($landerPath, '?', true);
            if ($pathOnly === false) $pathOnly = $landerPath;
            if (preg_match('#^/play/([^/?]+)/?#', $pathOnly, $m)) {
                $ctx->landerButton = strtolower($m[1]);
            }
        }
    }
}
