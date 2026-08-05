<?php

declare(strict_types=1);

namespace App\Pixel;

use App\Admin\Repository\CampaignRepository;
use App\Engine\Context;
use App\Engine\VisitorResolver;
use App\Shared\Db\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /p/event — receive pixel payload and insert into stats.pixel_events.
 *
 * Payload (JSON):
 *   c     string   campaign slug (required)
 *   url   string   page URL
 *   ref   string   referrer of the current page
 *   eref  string   referrer of the visit's entry page (external only, may be absent)
 *   ua    string   user-agent
 *   lang  string   browser language
 *   tz    string   timezone
 *   sw    int      screen width
 *   sh    int      screen height
 *   fp    string   FingerprintJS visitorId
 *   t     int      unix timestamp (client side, informational)
 *   event string   event name (default: pageview)
 *   props object   custom props
 *
 * Responses:
 *   400  missing or invalid `c`
 *   404  campaign not found
 *   204  success (+ Set-Cookie: vu=... if new visitor)
 */
final class EventController
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly VisitorResolver $visitorResolver,
        private readonly Connection $db,
    ) {}

    private function withCors(ServerRequestInterface $request, ResponseInterface $r): ResponseInterface
    {
        return \App\Shared\Http\PixelCors::apply($request, $r);
    }

    public function preflight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->withCors($request, $response->withStatus(204));
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // ── Parse JSON body ──────────────────────────────────────────────
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $raw = (string)$request->getBody();
            $data = json_decode($raw, true);
        } else {
            $data = $request->getParsedBody() ?? [];
        }

        if (!is_array($data)) {
            $data = [];
        }

        // ── Validate campaign slug ───────────────────────────────────────
        $slug = isset($data['c']) && is_string($data['c']) && trim($data['c']) !== ''
            ? trim($data['c'])
            : null;

        if ($slug === null) {
            $response->getBody()->write(json_encode(['error' => 'missing campaign slug']));
            return $this->withCors($request, $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json'));
        }

        $campaign = $this->campaigns->findBySlug($slug);
        if ($campaign === null) {
            $response->getBody()->write(json_encode(['error' => 'campaign not found']));
            return $this->withCors($request, $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'application/json'));
        }

        // ── Resolve visitor identity (sync, fast) — needed for Set-Cookie now ─
        $ip = $this->resolveIp($request);
        $ua = is_string($data['ua'] ?? null) ? $data['ua'] : $request->getHeaderLine('User-Agent');

        $ctx = new Context(
            ip: $ip,
            userAgent: $ua,
            campaignSlug: $slug,
            timestamp: time(),
        );
        $isNew = $this->visitorResolver->resolve($request, $ctx);

        // ── Stage raw payload for the async drain worker (inbox:flush) ───
        // No GeoIP / UA parse / partitioned-table insert in the request path —
        // the cron worker enriches and writes to stats.pixel_events.
        $this->db->execute(
            <<<'SQL'
                INSERT INTO stats.pixel_events_inbox
                    (payload, ip, user_agent, accept_lang, visitor_uuid, is_new_visitor)
                VALUES (:payload::jsonb, :ip::inet, :ua, :al, :vu, :nv)
            SQL,
            [
                'payload' => json_encode([
                    'campaign_id' => $campaign->id,
                    'fp'          => is_string($data['fp'] ?? null) && $data['fp'] !== '' ? $data['fp'] : null,
                    'event'       => is_string($data['event'] ?? null) && $data['event'] !== '' ? $data['event'] : 'pageview',
                    'url'         => is_string($data['url'] ?? null) ? $data['url'] : null,
                    'ref'         => is_string($data['ref'] ?? null) ? $data['ref'] : null,
                    'eref'        => is_string($data['eref'] ?? null) && $data['eref'] !== '' ? $data['eref'] : null,
                    'sw'          => isset($data['sw']) && is_numeric($data['sw']) ? (int)$data['sw'] : null,
                    'sh'          => isset($data['sh']) && is_numeric($data['sh']) ? (int)$data['sh'] : null,
                    'tz'          => is_string($data['tz'] ?? null) && $data['tz'] !== '' ? $data['tz'] : null,
                    'lang'        => is_string($data['lang'] ?? null) && $data['lang'] !== '' ? $data['lang'] : null,
                    'props'       => isset($data['props']) && is_array($data['props']) ? $data['props'] : null,
                    '_dbg'        => [
                        'xsi'  => $request->getHeaderLine('X-Slim-IP'),
                        'xri'  => $request->getHeaderLine('X-Real-IP'),
                        'xff'  => $request->getHeaderLine('X-Forwarded-For'),
                        'cfip' => $request->getHeaderLine('CF-Connecting-IP'),
                        'tcip' => $request->getHeaderLine('True-Client-IP'),
                        'ra'   => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'ua' => $ua !== '' ? $ua : null,
                'al' => $request->getHeaderLine('Accept-Language') ?: null,
                'vu' => $ctx->visitorUuid,
                'nv' => $isNew ? 'true' : 'false',
            ],
        );

        // ── 204 + Set-Cookie for new visitor ────────────────────────────
        $response = $response->withStatus(204);
        if ($isNew) {
            $response = $this->visitorResolver->attachCookie($response, $ctx->visitorUuid);
        }
        return $this->withCors($request, $response);
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        return \App\Shared\RealIp::from($request);
    }
}
