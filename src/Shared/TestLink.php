<?php

declare(strict_types=1);

namespace App\Shared;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Test links: `?_t=<key>` marks a request as the operator's own test. Two
 * kinds of key share the parameter:
 *
 *   campaign key — routing runs exactly as for real traffic (filters, bot
 *                  detection, flow order), so it answers "would MY visit pass
 *                  the geo filter, or land in the bot trash?". Only the
 *                  country may be swapped via `_geo`, to test geo flows
 *                  without a VPN.
 *   flow key     — pins one flow and skips its filters; the flow need not
 *                  even be switched on yet. Tests the offer/schema end.
 *
 * Keys are derived, not stored: the first 12 hex chars of
 * HMAC-SHA256(APP_SECRET, "<scope>:<id>"). A key both authorises the request
 * and names its target, so one parameter is the whole link. Rotating
 * APP_SECRET rotates every key at once. Without an APP_SECRET the keys would
 * be public knowledge, so test links are then switched off entirely.
 *
 * Optional companions, honoured only next to a valid key:
 *   _geo=DE                      country used for filters, macros and the click log
 *   _dbg=1                       click answers with a plain-text trace instead
 *                                of redirecting — readable on a phone
 *
 * An invalid key is silently ignored — the visitor is routed like anyone else,
 * so a probe cannot tell the mode exists.
 */
final readonly class TestLink
{
    public const KEY = '_t';
    public const GEO = '_geo';
    public const DBG = '_dbg';
    public const PARAMS = [self::KEY, self::GEO, self::DBG];

    private const KEY_LEN = 12;

    public function __construct(
        public string $key,
        public ?string $geo = null,  // ISO-2, lowercase like Context::$country
        public bool $dry = false,    // answer with a trace, do not redirect
    ) {}

    public static function campaignKey(string $campaignId): ?string
    {
        return self::derive('campaign', $campaignId);
    }

    public static function flowKey(string $flowId): ?string
    {
        return self::derive('flow', $flowId);
    }

    public function isCampaign(string $campaignId): bool
    {
        return self::same(self::campaignKey($campaignId), $this->key);
    }

    public function isFlow(string $flowId): bool
    {
        return self::same(self::flowKey($flowId), $this->key);
    }

    /** Null when APP_SECRET is not configured — test links are then off. */
    private static function derive(string $scope, string $id): ?string
    {
        $secret = (string)($_ENV['APP_SECRET'] ?? '');
        if ($secret === '') return null;

        return substr(hash_hmac('sha256', $scope . ':' . $id, $secret), 0, self::KEY_LEN);
    }

    private static function same(?string $expected, string $given): bool
    {
        return $expected !== null && hash_equals($expected, $given);
    }

    /**
     * Read the test parameters off a request. The query string wins; a click
     * proxied from a lander's /play/<button>/ may instead carry them only in
     * the original path forwarded as X-Lander-Path.
     */
    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $link = self::fromParams($request->getQueryParams());
        if ($link !== null) return $link;

        $landerQuery = parse_url($request->getHeaderLine('X-Lander-Path'), PHP_URL_QUERY);
        if (!is_string($landerQuery) || $landerQuery === '') return null;
        parse_str($landerQuery, $params);

        return self::fromParams($params);
    }

    /** @param array<mixed> $params */
    public static function fromParams(array $params): ?self
    {
        $key = $params[self::KEY] ?? null;
        if (!is_string($key) || preg_match('/^[0-9a-f]{' . self::KEY_LEN . '}$/', $key) !== 1) {
            return null;
        }

        $geo = $params[self::GEO] ?? null;
        $geo = is_string($geo) && preg_match('/^[A-Za-z]{2}$/', $geo) === 1 ? strtolower($geo) : null;


        $dry = ($params[self::DBG] ?? null) === '1';

        return new self($key, $geo, $dry);
    }
}
