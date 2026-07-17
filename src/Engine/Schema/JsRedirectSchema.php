<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * JS redirect — return a tiny HTML page that immediately switches the
 * window location via JavaScript. Useful when the destination must look
 * like a normal client-side navigation (no 30x in dev tools, no Referer
 * leak from the original URL when location.replace is used) and when the
 * caller wants to defeat naive redirect-counters that only follow HTTP
 * redirects.
 *
 * Falls back to a meta-refresh inside <noscript> so non-JS clients (and
 * most crawlers) still reach the destination.
 *
 * Config:
 *   url    string  fallback URL when no offer target — same convention as
 *                  HttpRedirectSchema/MetaRefreshSchema
 *   delay  int     ms before window.location.replace fires (default 0)
 *   mode   string  'replace' (default, no history entry) | 'assign' (history)
 */
final class JsRedirectSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $target = $outUrl !== null && $outUrl !== '' ? $outUrl : (string)($config['url'] ?? '');
        if ($target === '') {
            return $response->withStatus(204);
        }

        $delay = max(0, (int)($config['delay'] ?? 0));
        $mode  = ($config['mode'] ?? 'replace') === 'assign' ? 'assign' : 'replace';

        $jsTarget   = json_encode($target, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $htmlTarget = htmlspecialchars($target, ENT_QUOTES);

        $jsCall = $delay > 0
            ? "setTimeout(function(){window.location.{$mode}({$jsTarget})},{$delay})"
            : "window.location.{$mode}({$jsTarget})";

        $body = "<!DOCTYPE html><html><head><meta charset=\"utf-8\">"
              . "<meta name=\"referrer\" content=\"no-referrer\">"
              . "<title>Redirecting…</title>"
              . "<script>{$jsCall}</script>"
              . "<noscript><meta http-equiv=\"refresh\" content=\"0;url={$htmlTarget}\"></noscript>"
              . "</head><body></body></html>";

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
