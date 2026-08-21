<?php

declare(strict_types=1);

namespace App\Postback;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Db\Connection;
use App\Shared\RealIp;
use App\Shared\Referer\SearchEngine;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostbackController
{
    private const VALID_STATUSES = ['approved', 'pending', 'hold', 'rejected'];

    public function __construct(
        private readonly OfferRepository $offers,
        private readonly CampaignRepository $campaigns,
        private readonly Connection $db,
        private readonly TelegramNotifier $tg,
        private readonly PostbackOutbox $outbox,
        private readonly SettingsRepository $settings,
        private readonly NotificationRegistry $notifications,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Accept both GET (query string) and POST (form-encoded or JSON body)
        // since some partners default to POST. Query takes precedence; body
        // fills in keys that aren't in the query.
        $query = $request->getQueryParams();
        $body  = $request->getParsedBody();
        if (!is_array($body)) $body = [];
        // application/json bodies that PSR didn't auto-parse
        if ($body === [] && str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            $raw = (string)$request->getBody();
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        $params = $query + $body;

        $sourceIp = RealIp::from($request);
        $rawQuery = $request->getUri()->getQuery();
        if ($rawQuery === '' && $body !== []) {
            $rawQuery = http_build_query($body);
        }

        $subid      = isset($params['subid']) ? trim((string)$params['subid']) : '';
        $token      = isset($params['token']) ? trim((string)$params['token']) : '';
        $payoutRaw  = isset($params['payout']) ? $params['payout'] : '0';
        $status     = isset($params['status']) ? trim((string)$params['status']) : 'approved';
        $externalId = isset($params['external_id']) ? trim((string)$params['external_id']) : null;

        // Token is mandatory; subid is required only for offer-tokens.
        // Campaign-tokens accept subid-less pings as anonymous campaign
        // counters (no specific click attribution — handled below).
        if ($token === '') {
            return $this->json($response, [
                'ok'    => false,
                'error' => 'missing token',
                'debug' => [
                    'method'       => $request->getMethod(),
                    'content_type' => $request->getHeaderLine('Content-Type'),
                    'query_keys'   => array_keys($query),
                    'body_keys'    => array_keys($body),
                ],
            ], 400);
        }
        // Subid placeholder ('{subId1}') leaked through partner test-buttons —
        // treat as empty so we fall into anonymous-ping mode for campaign-tokens.
        if ($subid !== '' && (str_starts_with($subid, '{') || str_ends_with($subid, '}'))) {
            $subid = '';
        }

        // Validate status
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'approved';
        }

        // Validate and normalise payout
        $payout = is_numeric($payoutRaw) ? (string)(float)$payoutRaw : '0';

        // Token resolution: try per-offer first, then campaign-level catch-all.
        // Offer-token: locks conversion to that exact offer (multiple partners
        // in one campaign — each must use their own URL).
        // Campaign-token: a single URL works for any offer inside the campaign;
        // we resolve the offer from the click row (where it was bound at click
        // time). Useful when one partner network owns the whole campaign.
        $offer = $this->offers->findByToken($token);
        $tokenScope = $offer !== null ? 'offer' : null;
        $campaignFromToken = null;
        if ($offer === null) {
            $campaignFromToken = $this->campaigns->findByPostbackToken($token);
            if ($campaignFromToken !== null) {
                $tokenScope = 'campaign';
            }
        }
        if ($tokenScope === null) {
            return $this->json($response, ['ok' => false, 'error' => 'unknown token'], 404);
        }

        // Anonymous campaign ping — campaign-token without subid. Records a
        // conversion row tied only to the campaign, no specific click/offer.
        // Lets operators count partner events even when the network's test
        // mode or pipeline doesn't propagate sub_id1.
        if ($tokenScope === 'campaign' && $subid === '') {
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO core.conversions
                        (click_id, campaign_id, offer_id, payout, status, external_id, currency, source_ip, raw_query)
                    VALUES
                        (NULL, :campaign_id, NULL, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                SQL,
                [
                    'campaign_id' => $campaignFromToken->id,
                    'payout'      => $payout,
                    'status'      => $status,
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'source_ip'   => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                    'raw_query'   => $rawQuery !== '' ? $rawQuery : null,
                ],
            );
            if ($this->tg->isConfigured() && $this->settings->getBool('notif_conv_ping_enabled', true)) {
                $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
                $this->tg->send($this->notifications->render(
                    NotificationRegistry::CONV_PING,
                    $this->settings->get('notif_conv_ping_template', ''),
                    [
                        'campaign'  => $campaignFromToken->name,
                        'slug'      => $campaignFromToken->slug,
                        'status'    => $status,
                        'player_id' => $externalId !== '' && $externalId !== null ? (string)$externalId : '',
                        'ip'        => $sourceIp !== '' ? $sourceIp : '—',
                        'payout'    => $payout,
                        'app_url'   => $appUrl,
                    ],
                ));
            }
            return $this->json($response, ['ok' => true, 'mode' => 'campaign-ping']);
        }

        // From here on subid must be present (offer-token or campaign-token
        // with explicit click attribution).
        if ($subid === '') {
            return $this->json($response, ['ok' => false, 'error' => 'missing subid'], 400);
        }

        // Lookup click by subid (need offer_id when token came from a campaign).
        // Also pulls lander/geo/device fields used to build a richer Telegram message.
        $clickRow = $this->db->fetchOne(
            'SELECT id, campaign_id, offer_id, visitor_uuid, lander_host, lander_button, country, device,
                    extract(epoch from (now() - created_at))::int AS age_sec
             FROM stats.clicks WHERE id = :id',
            ['id' => $subid],
        );
        if ($clickRow === null) {
            return $this->json($response, ['ok' => false, 'error' => 'click not found'], 404);
        }

        $campaignId = (string)$clickRow['campaign_id'];

        // Campaign-token: pull offer from the click. Click without an offer
        // (trash-fallthrough) can't be attributed — there's no destination
        // offer to credit, so we 422.
        if ($tokenScope === 'campaign') {
            // Safety: token must match the campaign of the original click
            if ($campaignFromToken->id !== $campaignId) {
                return $this->json($response, ['ok' => false, 'error' => 'token/click campaign mismatch'], 409);
            }
            if (empty($clickRow['offer_id'])) {
                return $this->json($response, ['ok' => false, 'error' => 'click has no offer (trash fallthrough)'], 422);
            }
            $offer = $this->offers->findById((string)$clickRow['offer_id']);
            if ($offer === null) {
                return $this->json($response, ['ok' => false, 'error' => 'click offer no longer exists'], 410);
            }
        } elseif ($tokenScope === 'offer') {
            // Bind the click to the token's offer: an offer token may only
            // register conversions for clicks that were actually routed to
            // that offer. Without this, anyone holding one offer's token could
            // POST another offer's subid and overwrite/hijack its conversion.
            if ((string)($clickRow['offer_id'] ?? '') !== $offer->id) {
                return $this->json($response, ['ok' => false, 'error' => 'token/click offer mismatch'], 409);
            }
        }

        // Check whether a conversion already exists for this click_id
        $existing = (int)$this->db->fetchScalar(
            'SELECT count(*) FROM core.conversions WHERE click_id = :cid',
            ['cid' => $subid],
        );
        $updated = $existing > 0;

        // UPSERT conversion — RETURNING id so we can enqueue outbound postbacks
        $convRow = $this->db->fetchOne(
            <<<'SQL'
                INSERT INTO core.conversions
                    (click_id, campaign_id, offer_id, payout, status, external_id, currency, source_ip, raw_query)
                VALUES
                    (:click_id, :campaign_id, :offer_id, :payout, :status, :external_id, 'USD', :source_ip, :raw_query)
                ON CONFLICT (click_id)
                DO UPDATE SET
                    payout      = EXCLUDED.payout,
                    status      = EXCLUDED.status,
                    external_id = EXCLUDED.external_id,
                    source_ip   = EXCLUDED.source_ip,
                    raw_query   = EXCLUDED.raw_query,
                    updated_at  = now()
                RETURNING id
            SQL,
            [
                'click_id'    => $subid,
                'campaign_id' => $campaignId,
                'offer_id'    => $offer->id,
                'payout'      => $payout,
                'status'      => $status,
                'external_id' => $externalId !== '' ? $externalId : null,
                'source_ip'   => $sourceIp !== '' && $sourceIp !== '0.0.0.0' ? $sourceIp : null,
                'raw_query'   => $rawQuery !== '' ? $rawQuery : null,
            ],
        );
        $conversionId = $convRow !== null ? (string)$convRow['id'] : null;

        // Enqueue outgoing S2S postbacks (no-ops if offer has no postback_urls)
        if ($conversionId !== null) {
            $this->outbox->enqueue($conversionId, $offer->id, [
                'click_id'    => $subid,
                'payout'      => $payout,
                'status'      => $status,
                'external_id' => $externalId ?? '',
                'currency'    => $offer->currency,
            ]);
        }

        if ($this->tg->isConfigured() && $this->settings->getBool('notif_conv_click_enabled', true)) {
            $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
            $landerHost = (string)($clickRow['lander_host'] ?? '');
            $landerBtn  = (string)($clickRow['lander_button'] ?? '');
            $country    = (string)($clickRow['country'] ?? '');
            $device     = (string)($clickRow['device'] ?? '');
            $ageSec     = (int)($clickRow['age_sec'] ?? 0);
            $visitorUuid = (string)($clickRow['visitor_uuid'] ?? '');

            // route: lander → button · 🇦🇷 AR · device. Each part skipped when empty.
            $route = [];
            if ($landerHost !== '') {
                $route[] = $landerHost . ($landerBtn !== '' ? ' → ' . $landerBtn : '');
            }
            if ($country !== '') {
                $flag = self::flagOf($country);
                $route[] = trim($flag . ' ' . strtoupper($country));
            }
            if ($device !== '') $route[] = $device;

            // entry source — first pixel-event referer of this visitor.
            $source = '';
            if ($visitorUuid !== '') {
                $entryRef = (string)($this->db->fetchScalar(
                    "SELECT referer FROM stats.pixel_events
                     WHERE visitor_uuid = :v AND created_at >= now() - interval '30 days'
                     ORDER BY created_at ASC LIMIT 1",
                    ['v' => $visitorUuid],
                ) ?? '');
                if ($entryRef !== '') {
                    $src = SearchEngine::classify($entryRef);
                    $emoji = match ($src) {
                        'chatgpt', 'perplexity', 'claude', 'gemini', 'copilot' => '🤖',
                        null => '🔗',
                        default => '🔍',
                    };
                    $source = $emoji . ' ' . ($src ?? (parse_url($entryRef, PHP_URL_HOST) ?: 'direct'));
                }
            }

            $msg = $this->notifications->render(
                NotificationRegistry::CONV_CLICK,
                $this->settings->get('notif_conv_click_template', ''),
                [
                    'offer_name' => $offer->name,
                    'status'     => $status,
                    'payout'     => $payout,
                    'route'      => implode(' · ', $route),
                    'source'     => $source,
                    'player_id'  => $externalId !== '' && $externalId !== null ? (string)$externalId : '',
                    'age'        => self::humanAge($ageSec),
                    'country'    => $country !== '' ? strtoupper($country) : '',
                    'device'     => $device,
                    'click_id'   => $subid,
                    'app_url'    => $appUrl,
                ],
            );
            $this->tg->send($msg);
        }

        return $this->json($response, ['ok' => true, 'updated' => $updated]);
    }

    /** ISO-2 country → 🇦🇷-style regional-indicator flag (or '' on bad input). */
    private static function flagOf(string $cc): string
    {
        $cc = strtoupper(trim($cc));
        if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
    }

    /** Seconds → "30s" / "5m" / "2h" / "3d" — coarse age suitable for ops alerts. */
    private static function humanAge(int $sec): string
    {
        if ($sec < 60)    return $sec . 's';
        if ($sec < 3600)  return intdiv($sec, 60) . 'm';
        if ($sec < 86400) return intdiv($sec, 3600) . 'h';
        return intdiv($sec, 86400) . 'd';
    }

    /** @param array<string,mixed> $data */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
