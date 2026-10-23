<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Per-request context flowing through the engine pipeline.
 * Fields are populated by the resolvers in order:
 *   VisitorResolver → DeviceDetector → GeoLookup → BotDetector → FlowMatcher → OfferPicker
 * Any subsequent stage can read what previous stages wrote.
 */
final class Context
{
    public ?string $visitorUuid = null;       // UUIDv7 from cookie or fresh
    public bool $isUniqVisitor = true;         // true when newly issued

    public ?string $country = null;            // ISO-2 lowercase, e.g. "ru"
    public ?string $region = null;             // ISO subdivision code lowercase
    public ?string $city = null;               // lowercase ASCII name
    public ?int $asn = null;
    public ?string $isp = null;

    public ?string $device = null;             // "mobile" | "tablet" | "desktop"
    public ?string $os = null;                 // "iOS", "Android", "Windows", ...
    public ?string $browser = null;            // "Chrome", "Safari", ...
    public ?string $lang = null;               // 2-char from Accept-Language

    public bool $isBot = false;
    public ?string $botName = null;            // "google" | "yandex" | ...

    public ?string $referer = null;
    public ?string $refererDomain = null;

    // Lander context (from X-Lander-Host / X-Lander-Path forwarded by SEO-sites' nginx).
    public ?string $landerHost = null;     // full hostname, e.g. "lander.example.com"
    public ?string $landerDomain = null;   // host without TLD, e.g. "lander"
    public ?string $landerButton = null;   // /play/<button>/ segment, e.g. "betsson"

    /** @var array<string,string> */ public array $utm = [];
    /** @var array<string,string> */ public array $query = [];

    public ?string $matchedFlowId = null;
    public ?string $matchedOfferId = null;
    public ?string $clickId = null;            // UUIDv7 minted by ClickHandler
    public ?string $fpJs = null;               // FingerprintJS visitor id, from prior pixel event

    public function __construct(
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $campaignSlug,
        public readonly int $timestamp,        // unix
    ) {}

    public function queryParam(string $name): ?string
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/', $name)) return null;
        $value = $this->query[$name] ?? null;
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) return null;
        return $value;
    }
}
