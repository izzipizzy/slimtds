<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Engine\BotDetector;
use App\Engine\Context;
use App\Engine\DeviceDetector;
use App\Engine\GeoLookup;
use App\Shared\Db\Connection;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Referer\SearchEngine;
use App\Shared\Telegram\TelegramNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'inbox:flush', description: 'Drain stats.pixel_events_inbox into stats.pixel_events (UA + GeoIP enrich, batch insert)')]
final class InboxFlushCommand extends Command
{
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly GeoLookup $geo,
        private readonly DeviceDetector $deviceDetector,
        private readonly BotDetector $botDetector,
        private readonly TelegramNotifier $tg,
        private readonly SettingsRepository $settings,
        private readonly NotificationRegistry $notifications,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Keep ticking inside one process for ~55s so supercronic can stay on a
        // 1-minute schedule yet events surface to /admin/pixel within ~5s.
        // (supercronic in our image rejects @every Ns and 6-field schedules at
        // runtime even when -test says they're valid.)
        $deadline = time() + 55;
        $totalProcessed = 0;
        $ticks = 0;
        do {
            $processed = $this->tick();
            $totalProcessed += $processed;
            $ticks++;
            if ($processed === 0) {
                // Nothing waiting — sleep 5s before peeking again.
                if (time() + 5 > $deadline) break;
                sleep(5);
            }
            // If we drained a full batch there might be more; loop again immediately.
        } while (time() < $deadline);
        $output->writeln("inbox:flush ticks={$ticks} processed={$totalProcessed}");
        return self::SUCCESS;
    }

    private function tick(): int
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT id, payload, host(ip) AS ip, user_agent, accept_lang, visitor_uuid, created_at
                FROM stats.pixel_events_inbox
                ORDER BY created_at
                LIMIT :lim
            SQL,
            ['lim' => self::BATCH],
        );
        if ($rows === []) {
            return 0;
        }

        $geoOk = $this->geo->isAvailable();
        $ids = [];
        foreach ($rows as $row) {
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : $row['payload'];
            if (!is_array($payload)) {
                $ids[] = $row['id'];
                continue;
            }

            $ua = (string)($row['user_agent'] ?? '');
            $ip = (string)($row['ip'] ?? '');

            $ctx = new Context(
                ip: $ip,
                userAgent: $ua,
                campaignSlug: '',
                timestamp: (int)strtotime((string)$row['created_at']),
            );
            if ($geoOk && $ip !== '') {
                $this->geo->lookup($ctx);
            }
            $this->deviceDetector->detect($ctx, (string)($row['accept_lang'] ?? ''));
            // Bot detection runs AFTER geo (needs ASN+ISP); same logic as the engine.
            $this->botDetector->detect($ctx);

            $this->db->execute(
                <<<'SQL'
                    INSERT INTO stats.pixel_events
                        (campaign_id, visitor_uuid, fp_js, event_name, page_url,
                         referer, entry_referer, ip, country, city, asn, user_agent, device, os, browser,
                         screen_w, screen_h, timezone, lang, props, is_bot, bot_name, created_at)
                    VALUES
                        (:campaign_id, :visitor_uuid, :fp_js, :event_name, :page_url,
                         :referer, :entry_referer, :ip::inet, :country, :city, :asn, :user_agent, :device, :os, :browser,
                         :screen_w, :screen_h, :timezone, :lang, :props::jsonb, :is_bot, :bot_name, :created_at)
                SQL,
                [
                    'campaign_id'  => (string)$payload['campaign_id'],
                    'visitor_uuid' => (string)$row['visitor_uuid'],
                    'fp_js'        => $payload['fp'] ?? null,
                    'event_name'   => (string)($payload['event'] ?? 'pageview'),
                    'page_url'     => $payload['url'] ?? null,
                    'referer'      => $payload['ref'] ?? null,
                    'entry_referer' => $payload['eref'] ?? null,
                    'ip'           => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                    'country'      => $ctx->country,
                    'city'         => $ctx->city,
                    'asn'          => $ctx->asn,
                    'user_agent'   => $ua !== '' ? $ua : null,
                    'device'       => $ctx->device,
                    'os'           => $ctx->os,
                    'browser'      => $ctx->browser,
                    'screen_w'     => $payload['sw'] ?? null,
                    'screen_h'     => $payload['sh'] ?? null,
                    'timezone'     => $payload['tz'] ?? null,
                    'lang'         => $payload['lang'] ?? null,
                    'props'        => isset($payload['props']) && is_array($payload['props'])
                        ? json_encode($payload['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : null,
                    'is_bot'       => $ctx->isBot ? 'true' : 'false',
                    'bot_name'     => $ctx->botName,
                    'created_at'   => (string)$row['created_at'],
                ],
            );
            $ids[] = $row['id'];

            // Backfill fp_js onto any recent clicks for the same visitor that
            // were logged BEFORE this pixel event made it out of the inbox
            // (the lookup in ClickHandler::resolveFpJs hits stats.pixel_events,
            // which is populated here — there's a window of up to ~60s where a
            // click can land between the pixel POST and this drain).
            $fp = is_string($payload['fp'] ?? null) ? (string)$payload['fp'] : '';
            if ($fp !== '' && !empty($row['visitor_uuid'])) {
                $this->db->execute(
                    "UPDATE stats.clicks
                     SET fp_js = :fp
                     WHERE visitor_uuid = :v
                       AND fp_js IS NULL
                       AND created_at >= now() - interval '24 hours'",
                    ['fp' => $fp, 'v' => (string)$row['visitor_uuid']],
                );
            }

            // Low-volume "AI traffic" signal: TG alert when a pageview's referer
            // is ChatGPT or Google. By the nature of document.referrer, only the
            // entry pageview of a session carries the external host, so dedup is
            // implicit. Goal here is observability while traffic is small.
            $this->maybeNotifyAiReferer($payload, $row, $ctx);
        }

        // Single bulk delete of processed inbox rows
        $placeholders = implode(',', array_map(fn ($i) => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
        }
        $this->db->execute("DELETE FROM stats.pixel_events_inbox WHERE id IN ({$placeholders})", $params);

        return count($ids);
    }

    /**
     * @param array<string,mixed> $payload pixel event payload
     * @param array<string,mixed> $row inbox row (host(ip), visitor_uuid, created_at, …)
     */
    private function maybeNotifyAiReferer(array $payload, array $row, Context $ctx): void
    {
        if (!$this->tg->isConfigured()) return;
        if (!$this->settings->getBool('notif_ai_pixel_enabled', true)) return;

        $ref = is_string($payload['ref'] ?? null) ? (string)$payload['ref'] : '';
        if ($ref === '') return;

        $engine = SearchEngine::classify($ref);
        if ($engine === null) return;

        $sources = $this->sources('notif_ai_pixel_sources');
        if (!in_array($engine, $sources, true)) return;

        $emoji = in_array($engine, ['chatgpt', 'perplexity', 'claude', 'gemini', 'copilot'], true) ? '🤖' : '🔍';
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
        $url = is_string($payload['url'] ?? null) ? (string)$payload['url'] : '';
        $event = is_string($payload['event'] ?? null) ? (string)$payload['event'] : 'pageview';
        $visitorUuid = (string)($row['visitor_uuid'] ?? '');
        $fpJs = is_string($payload['fp'] ?? null) ? (string)$payload['fp'] : '';

        $route = [];
        if ($ctx->country) $route[] = strtoupper($ctx->country);
        if ($ctx->device)  $route[] = $ctx->device;

        $msg = $this->notifications->render(
            NotificationRegistry::AI_PIXEL,
            $this->settings->get('notif_ai_pixel_template', ''),
            [
                'emoji'        => $emoji,
                'source'       => $engine,
                'event'        => $event,
                'page_url'     => $url !== '' ? self::truncate($url, 80) : '(no url)',
                'route'        => $route ? implode(' · ', $route) : 'unknown',
                'visitor_uuid' => substr($visitorUuid, 0, 8),
                'fp_js'        => $fpJs !== '' ? substr($fpJs, 0, 10) : '',
                'domain'       => $url !== '' ? rawurlencode((string)parse_url($url, PHP_URL_HOST)) : '',
                'country'      => $ctx->country ? strtoupper($ctx->country) : '',
                'device'       => $ctx->device ?? '',
                'app_url'      => $appUrl,
            ],
        );
        $this->tg->send($msg);
    }

    /**
     * Read a JSON-array sources setting, defaulting to chatgpt+google.
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
