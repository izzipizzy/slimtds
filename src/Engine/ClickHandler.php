<?php

declare(strict_types=1);

namespace App\Engine;

use App\Admin\Repository\Campaign;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\Offer;
use App\Admin\Repository\OfferRepository;
use App\Engine\Schema\SchemaRegistry;
use App\Shared\Db\Connection;
use App\Shared\Referer\SearchEngine;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Telegram\TelegramNotifier;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

final class ClickHandler
{
    /**
     * Offer ids reach `core.offers.id`, which is a `uuid` column: a malformed id
     * is not a miss, it aborts the whole lookup. Anything that does not match is
     * dropped from the candidate list instead, the way OfferPicker already drops
     * an empty id.
     */
    private const OFFER_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
        private readonly VisitorResolver $visitor,
        private readonly DeviceDetector $device,
        private readonly GeoLookup $geo,
        private readonly BotDetector $bot,
        private readonly FlowMatcher $matcher,
        private readonly OfferPicker $picker,
        private readonly MacroExpander $macros,
        private readonly SchemaRegistry $schemas,
        private readonly Connection $db,
        private readonly TelegramNotifier $tg,
        private readonly SettingsRepository $settings,
        private readonly NotificationRegistry $notifications,
    ) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $campaign = $this->campaigns->findBySlug($slug);
        if ($campaign === null || !$campaign->isActive) {
            return $this->trash($campaign, '', $response);
        }

        $ctx = $this->buildContext($request, $slug);

        $needCookie = $this->visitor->resolve($request, $ctx);
        $ctx->fpJs = $this->resolveFpJs($ctx->visitorUuid);
        $this->device->detect($ctx, $request->getHeaderLine('Accept-Language'));
        $this->geo->lookup($ctx);
        $this->bot->detect($ctx);

        $flow = $this->matcher->match($campaign->id, $ctx);

        // Mint clickId early so macros like {click_id} resolve during URL expansion
        $ctx->clickId = Uuid::uuid7()->toString();

        // No flow matched — fall through to the campaign's trash mode so the
        // operator-configured fallback (302/403/404/200) actually fires.
        // Without this we'd silently use schema 13 (NoAction → 200), which
        // both leaks "campaign exists" and ignores trash_url.
        if ($flow === null) {
            $trashUrl = $this->resolveTrashUrl($campaign, $ctx);
            $resp = $this->trash($campaign, $trashUrl, $response);
            $schemaId = (int)($campaign->defaultSchema ?? 13);
            // For redirect-style trash modes pass the resolved trash URL as out_url
            // so /admin/clicks shows where the visitor was actually sent.
            $trashOut = in_array($campaign->trashMode, [1, 4, 5, 6, 7], true)
                ? ($trashUrl ?: null)
                : null;
            $this->logClick($ctx, $campaign, $trashOut, $resp->getStatusCode(), $schemaId);
            if ($needCookie && $ctx->visitorUuid !== null) {
                $resp = $this->visitor->attachCookie($resp, $ctx->visitorUuid, $request->getUri()->getScheme() === 'https');
            }
            return $resp;
        }

        $outUrl = null;
        $schemaId = $flow->schemaId;
        $schemaConfig = $flow->schemaConfig ?? [];

        if ($flow !== null && $flow->targetType === 'offers' && $flow->targetOffers !== []) {
            // Flow override wins; null = inherit the campaign default.
            $sticky = $flow->stickyOffer ?? $campaign->stickyOffer;
            $targetIds = array_map(
                self::candidateOfferId(...),
                $flow->targetOffers,
            );
            $activeOffers = $this->offers->findActiveByIds($targetIds);
            $activeTargets = array_values(array_filter(
                $flow->targetOffers,
                static fn (array $target): bool => isset($activeOffers[self::candidateOfferId($target)]),
            ));
            $offerId = $this->picker->pick($activeTargets, $ctx, $sticky);
            if ($offerId !== null) {
                $offer = $activeOffers[$offerId] ?? null;
                if ($offer !== null) {
                    $outUrl = $this->macros->expand($offer->url, $ctx);
                }
            }
        }

        // Macro-expand body/url-config too (used by HTML, ShowText, Curl, etc.)
        if (isset($schemaConfig['body'])) {
            $schemaConfig['body'] = $this->macros->expand((string)$schemaConfig['body'], $ctx);
        }
        if (isset($schemaConfig['url'])) {
            $schemaConfig['url'] = $this->macros->expand((string)$schemaConfig['url'], $ctx);
        }

        $resp = $this->schemas->get($schemaId)->respond($ctx, $outUrl, $schemaConfig, $response);

        $this->logClick($ctx, $campaign, $outUrl, $resp->getStatusCode(), $schemaId);
        $this->notifyIfAiSourced($ctx, $campaign, $outUrl);

        if ($needCookie && $ctx->visitorUuid !== null) {
            $resp = $this->visitor->attachCookie($resp, $ctx->visitorUuid, $request->getUri()->getScheme() === 'https');
        }
        return $resp;
    }

    /**
     * Look up the most recent FingerprintJS visitor id for this visitor_uuid.
     * One indexed read against (visitor_uuid, created_at DESC) — sub-ms.
     * Returns null when the visitor never fired the lander pixel.
     */
    private function resolveFpJs(?string $visitorUuid): ?string
    {
        if ($visitorUuid === null) return null;
        try {
            $val = $this->db->fetchScalar(
                "SELECT fp_js FROM stats.pixel_events
                 WHERE visitor_uuid = :v AND fp_js IS NOT NULL
                   AND created_at >= now() - interval '30 days'
                 ORDER BY created_at DESC LIMIT 1",
                ['v' => $visitorUuid],
            );
            return is_string($val) && $val !== '' ? $val : null;
        } catch (\Throwable $e) {
            error_log('[engine] fp_js lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private function buildContext(ServerRequestInterface $request, string $slug): Context
    {
        $ip = \App\Shared\RealIp::from($request);
        $ua = $request->getHeaderLine('User-Agent') ?: '-';
        $ctx = new Context($ip, $ua, $slug, time());

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

        $query = $request->getQueryParams();
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $name) {
            $key = 'utm_' . $name;
            if (isset($query[$key]) && is_string($query[$key])) {
                $ctx->utm[$name] = $query[$key];
            }
        }
        $ctx->query = array_filter($query, fn ($v, $k) => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH);
        return $ctx;
    }

    /**
     * Resolve a campaign's trash_url to a concrete redirect target. An
     * {offer:<name|id>} reference resolves to that offer's macro-expanded URL
     * (so the offer's own {spin}/{click_id} fire); anything else is
     * macro-expanded as-is. Empty or unresolvable → '' (→ trash 204).
     */
    private function resolveTrashUrl(Campaign $c, Context $ctx): string
    {
        $raw = trim((string)($c->trashUrl ?? ''));
        if ($raw === '') return '';
        if (preg_match('/^\{offer:\s*([^}]+?)\s*\}$/', $raw, $m)) {
            $offer = $this->resolveOffer($m[1]);
            return $offer === null ? '' : $this->macros->expand($offer->url, $ctx);
        }
        return $this->macros->expand($raw, $ctx);
    }

    /** @param array<string,mixed> $target */
    private static function candidateOfferId(array $target): string
    {
        $offerId = $target['offer_id'] ?? null;
        return is_string($offerId) && preg_match(self::OFFER_ID, $offerId) ? $offerId : '';
    }

    /** Resolve an active {offer:X} reference by UUID id, falling back to exact name. */
    private function resolveOffer(string $ref): ?Offer
    {
        if (preg_match(self::OFFER_ID, $ref)) {
            $byId = $this->offers->findById($ref);
            if ($byId !== null) return $byId->isActive ? $byId : null;
        }
        $byName = $this->offers->findByName($ref);
        return $byName !== null && $byName->isActive ? $byName : null;
    }

    private function trash(?Campaign $c, string $url, ResponseInterface $response): ResponseInterface
    {
        // Slug missing or campaign disabled — that's a hard 404. Trash modes
        // only apply to EXISTING active campaigns where no flow matched the
        // visitor (i.e. the operator deliberately configured a fallback).
        if ($c === null || !$c->isActive) {
            return $response->withStatus(404);
        }
        $hasUrl = $url !== '';

        switch ($c->trashMode) {
            case 1: // 302 Redirect
                return $hasUrl
                    ? $response->withStatus(302)->withHeader('Location', $url)
                    : $response->withStatus(204);
            case 2: // HTTP 403
                return $response->withStatus(403);
            case 3: // HTTP 404
                return $response->withStatus(404);
            case 4: // 301 Redirect (permanent)
                return $hasUrl
                    ? $response->withStatus(301)->withHeader('Location', $url)
                    : $response->withStatus(204);
            case 5: // 307 Redirect (preserve method)
                return $hasUrl
                    ? $response->withStatus(307)->withHeader('Location', $url)
                    : $response->withStatus(204);
            case 6: // Meta refresh
                if (!$hasUrl) return $response->withStatus(204);
                $h = htmlspecialchars($url, ENT_QUOTES);
                $body = "<!DOCTYPE html><html><head><meta http-equiv=\"refresh\" content=\"0;url={$h}\"></head><body></body></html>";
                $response->getBody()->write($body);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            case 7: // JS Redirect
                if (!$hasUrl) return $response->withStatus(204);
                $j = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $h = htmlspecialchars($url, ENT_QUOTES);
                $body = "<!DOCTYPE html><html><head><meta charset=\"utf-8\">"
                      . "<meta name=\"referrer\" content=\"no-referrer\">"
                      . "<title>Redirecting…</title>"
                      . "<script>window.location.replace({$j})</script>"
                      . "<noscript><meta http-equiv=\"refresh\" content=\"0;url={$h}\"></noscript>"
                      . "</head><body></body></html>";
                $response->getBody()->write($body);
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            default: // 0 = blank 200
                return $response->withStatus(200);
        }
    }

    private function logClick(Context $ctx, Campaign $campaign, ?string $outUrl, int $httpStatus, int $schemaId): void
    {
        $sql = <<<'SQL'
            INSERT INTO stats.clicks (
                id, campaign_id, flow_id, offer_id, visitor_uuid, ip,
                country, region, city, asn, isp, device, os, browser, lang,
                is_bot, bot_name, is_uniq, user_agent, referer,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                schema_id, out_url, http_status,
                lander_host, lander_button, fp_js
            ) VALUES (
                :id, :campaign_id, :flow_id, :offer_id, :visitor_uuid, :ip,
                :country, :region, :city, :asn, :isp, :device, :os, :browser, :lang,
                :is_bot, :bot_name, :is_uniq, :ua, :referer,
                :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                :schema_id, :out_url, :http_status,
                :lander_host, :lander_button, :fp_js
            )
        SQL;
        $params = [
            'id'           => $ctx->clickId,
            'campaign_id'  => $campaign->id,
            'flow_id'      => $ctx->matchedFlowId,
            'offer_id'     => $ctx->matchedOfferId,
            'visitor_uuid' => $ctx->visitorUuid,
            'ip'           => $ctx->ip,
            'country'      => $ctx->country,
            'region'       => $ctx->region,
            'city'         => $ctx->city,
            'asn'          => $ctx->asn,
            'isp'          => $ctx->isp,
            'device'       => $ctx->device,
            'os'           => $ctx->os,
            'browser'      => $ctx->browser,
            'lang'         => $ctx->lang,
            'is_bot'       => $ctx->isBot ? 'true' : 'false',
            'bot_name'     => $ctx->botName,
            'is_uniq'      => $ctx->isUniqVisitor ? 'true' : 'false',
            'ua'           => $ctx->userAgent,
            'referer'      => $ctx->referer,
            'utm_source'   => $ctx->utm['source']   ?? null,
            'utm_medium'   => $ctx->utm['medium']   ?? null,
            'utm_campaign' => $ctx->utm['campaign'] ?? null,
            'utm_term'     => $ctx->utm['term']     ?? null,
            'utm_content'  => $ctx->utm['content']  ?? null,
            'schema_id'    => $schemaId,
            'out_url'      => $outUrl,
            'http_status'  => $httpStatus,
            'lander_host'  => $ctx->landerHost,
            'lander_button' => $ctx->landerButton,
            'fp_js'        => $ctx->fpJs,
        ];
        try {
            $this->db->execute($sql, $params);
        } catch (\Throwable $e) {
            error_log('[engine] click insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Fire a TG alert if this visitor previously hit the lander pixel from one
     * of the operator-selected AI/search sources in the last 24 hours.
     * Fire-and-forget — never throws into the redirect path.
     */
    private function notifyIfAiSourced(Context $ctx, Campaign $campaign, ?string $outUrl): void
    {
        if (!$this->tg->isConfigured() || $ctx->visitorUuid === null) return;
        if (!$this->settings->getBool('notif_ai_click_enabled', true)) return;

        try {
            $sources = $this->sources('notif_ai_click_sources');
            [$frag, $bind] = SearchEngine::sqlFilterEngines($sources, 'referer', 'se');
            if ($frag === '') return; // no sources selected

            $row = $this->db->fetchOne(
                "SELECT referer, page_url, created_at
                 FROM stats.pixel_events
                 WHERE visitor_uuid = :v
                   AND created_at >= now() - interval '24 hours'
                   AND {$frag}
                 ORDER BY created_at ASC
                 LIMIT 1",
                ['v' => $ctx->visitorUuid] + $bind,
            );
            if ($row === null) return;

            $src = SearchEngine::classify((string)($row['referer'] ?? '')) ?? 'ai';
            $emoji = in_array($src, ['chatgpt', 'perplexity', 'claude', 'gemini', 'copilot'], true) ? '🤖' : '🔍';
            $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');

            $route = [];
            if ($ctx->landerHost) {
                $route[] = $ctx->landerHost . ($ctx->landerButton ? ' → ' . $ctx->landerButton : '');
            }
            if ($ctx->country) $route[] = strtoupper($ctx->country);
            if ($ctx->device)  $route[] = $ctx->device;

            $msg = $this->notifications->render(
                NotificationRegistry::AI_CLICK,
                $this->settings->get('notif_ai_click_template', ''),
                [
                    'emoji'     => $emoji,
                    'source'    => $src,
                    'campaign'  => $campaign->slug,
                    'route'     => $route ? implode(' · ', $route) : 'direct',
                    'offer_url' => $outUrl ? self::truncate($outUrl, 80) : '(no offer URL)',
                    'country'   => $ctx->country ? strtoupper($ctx->country) : '',
                    'device'    => $ctx->device ?? '',
                    'click_id'  => $ctx->clickId ?? '',
                    'app_url'   => $appUrl,
                ],
            );
            $this->tg->send($msg);
        } catch (\Throwable $e) {
            error_log('[engine] AI-source TG check failed: ' . $e->getMessage());
        }
    }

    /**
     * Read a JSON-array sources setting, defaulting to chatgpt+google (the
     * pre-settings hardcoded behavior).
     *
     * @return list<string>
     */
    private function sources(string $key): array
    {
        $raw = $this->settings->get($key, '["chatgpt","google"]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ['chatgpt', 'google'];
        return array_values(array_filter($decoded, 'is_string'));
    }

    private static function truncate(string $s, int $max): string
    {
        return strlen($s) <= $max ? $s : substr($s, 0, $max - 1) . '…';
    }
}
