<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Admin\Repository\CampaignRepository;
use App\Engine\Context;
use App\Engine\DeviceDetector;
use App\Engine\GeoLookup;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Shared\Ua\BrowserLabel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rrweb:flush', description: 'Drain stats.rrweb_inbox into gzipped stats.rrweb_chunks + maintain stats.rrweb_sessions')]
final class RrwebFlushCommand extends Command
{
    private const BATCH = 200;
    private bool $partitionEnsured = false;

    public function __construct(
        private readonly Connection $db,
        private readonly CampaignRepository $campaigns,
        private readonly GeoLookup $geo,
        private readonly DeviceDetector $deviceDetector,
        private readonly Partitions $partitions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Tick for ~55s so supercronic can stay on a 1-minute schedule yet
        // recordings surface to the admin within ~5s (same pattern as inbox:flush).
        $deadline = time() + 55;
        $total = 0;
        $ticks = 0;
        do {
            $n = $this->drainOnce();
            $total += $n;
            $ticks++;
            if ($n === 0) {
                if (time() + 5 > $deadline) break;
                sleep(5);
            }
        } while (time() < $deadline);
        $output->writeln("rrweb:flush ticks={$ticks} processed={$total}");
        return self::SUCCESS;
    }

    /** Drain one batch. Returns number of inbox rows processed. */
    public function drainOnce(): int
    {
        if (!$this->partitionEnsured) {
            // Make sure today's partition exists before inserting.
            $this->partitions->ensureDailyAhead(1);
            $this->partitionEnsured = true;
        }

        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT id, payload, host(ip) AS ip, user_agent, visitor_uuid, created_at
                FROM stats.rrweb_inbox
                ORDER BY created_at
                LIMIT :lim
            SQL,
            ['lim' => self::BATCH],
        );
        if ($rows === []) {
            return 0;
        }

        $slugToCampaign = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : null;
            if (!is_array($payload) || !is_string($payload['c'] ?? null) || !is_string($payload['sid'] ?? null)) {
                continue; // malformed → drop (id already queued for delete)
            }

            $slug = $payload['c'];
            if (!array_key_exists($slug, $slugToCampaign)) {
                $c = $this->campaigns->findBySlug($slug);
                $slugToCampaign[$slug] = $c?->id;
            }
            $campaignId = $slugToCampaign[$slug];
            if ($campaignId === null) {
                continue; // campaign vanished → drop
            }

            $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
            $eventCount = count($events);
            // First rrweb Meta event (type 4) carries the page href — store it so
            // the sessions list can show the domain without decoding chunks.
            $pageUrl = null;
            // First/last event timestamps (epoch ms) → replay duration across the visit.
            $firstMs = null;
            $lastMs = null;
            foreach ($events as $ev) {
                if (!is_array($ev)) {
                    continue;
                }
                if ($pageUrl === null && ($ev['type'] ?? null) === 4 && is_string($ev['data']['href'] ?? null)) {
                    $pageUrl = $ev['data']['href'];
                }
                if (is_numeric($ev['timestamp'] ?? null)) {
                    $ts = (int)$ev['timestamp'];
                    $firstMs = $firstMs === null ? $ts : min($firstMs, $ts);
                    $lastMs = $lastMs === null ? $ts : max($lastMs, $ts);
                }
            }
            $gz = gzencode(json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', 6);
            $b64 = base64_encode($gz !== false ? $gz : '');
            $bytes = strlen($gz !== false ? $gz : '');
            $sid = $payload['sid'];
            // session_id is a uuid column — skip non-uuid sids (e.g. a client
            // fallback id) so one bad row can't poison the whole batch.
            if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $sid)) {
                continue;
            }
            $seq = isset($payload['seq']) && is_numeric($payload['seq']) ? (int)$payload['seq'] : 0;
            $fp = is_string($payload['fp'] ?? null) && $payload['fp'] !== '' ? $payload['fp'] : null;
            $ref = is_string($payload['ref'] ?? null) && $payload['ref'] !== '' ? $payload['ref'] : null;
            $vu = $row['visitor_uuid'] ?: null;
            $createdAt = (string)$row['created_at'];

            // Any insert failure on a single row is isolated: the row id is already
            // queued for delete, so we skip it instead of aborting (and re-looping)
            // the whole batch.
            try {
                $this->db->execute(
                    <<<'SQL'
                        INSERT INTO stats.rrweb_chunks (session_id, campaign_id, visitor_uuid, seq, payload, created_at)
                        VALUES (:sid, :cid, :vu, :seq, decode(:b64,'base64'), :created_at)
                    SQL,
                    ['sid' => $sid, 'cid' => $campaignId, 'vu' => $vu, 'seq' => $seq, 'b64' => $b64, 'created_at' => $createdAt],
                );

                $ip = (string)($row['ip'] ?? '');
                $ip = $ip !== '' ? $ip : null;
                [$country, $device, $os, $browser] = $this->enrich($ip ?? '', (string)($row['user_agent'] ?? ''), $createdAt);

                $this->db->execute(
                    <<<'SQL'
                        INSERT INTO stats.rrweb_sessions
                            (session_id, campaign_id, visitor_uuid, fp_js, page_url, started_at, last_at, chunk_count, event_count, bytes, country, device, ip, os, browser, first_event_ms, last_event_ms, referer)
                        VALUES
                            (:sid, :cid, :vu, :fp, :purl, :created_at, :created_at, 1, :ec, :bytes, :country, :device, :ip, :os, :browser, :fms, :lms, :ref)
                        ON CONFLICT (session_id) DO UPDATE SET
                            last_at        = GREATEST(stats.rrweb_sessions.last_at, EXCLUDED.last_at),
                            chunk_count    = stats.rrweb_sessions.chunk_count + 1,
                            event_count    = stats.rrweb_sessions.event_count + EXCLUDED.event_count,
                            bytes          = stats.rrweb_sessions.bytes + EXCLUDED.bytes,
                            fp_js          = COALESCE(stats.rrweb_sessions.fp_js, EXCLUDED.fp_js),
                            page_url       = COALESCE(stats.rrweb_sessions.page_url, EXCLUDED.page_url),
                            ip             = COALESCE(stats.rrweb_sessions.ip, EXCLUDED.ip),
                            os             = COALESCE(stats.rrweb_sessions.os, EXCLUDED.os),
                            browser        = COALESCE(stats.rrweb_sessions.browser, EXCLUDED.browser),
                            first_event_ms = LEAST(stats.rrweb_sessions.first_event_ms, EXCLUDED.first_event_ms),
                            last_event_ms  = GREATEST(stats.rrweb_sessions.last_event_ms, EXCLUDED.last_event_ms),
                            referer        = COALESCE(stats.rrweb_sessions.referer, EXCLUDED.referer)
                    SQL,
                    [
                        'sid' => $sid, 'cid' => $campaignId, 'vu' => $vu, 'fp' => $fp, 'purl' => $pageUrl, 'created_at' => $createdAt,
                        'ec' => $eventCount, 'bytes' => $bytes, 'country' => $country, 'device' => $device,
                        'ip' => $ip, 'os' => $os, 'browser' => $browser, 'fms' => $firstMs, 'lms' => $lastMs, 'ref' => $ref,
                    ],
                );
            } catch (\Throwable $e) {
                continue;
            }
        }

        $placeholders = implode(',', array_map(fn ($i) => ':id' . $i, array_keys($ids)));
        $params = [];
        foreach ($ids as $i => $id) {
            $params['id' . $i] = $id;
        }
        $this->db->execute("DELETE FROM stats.rrweb_inbox WHERE id IN ({$placeholders})", $params);

        return count($ids);
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} [country, device, os, browser] */
    private function enrich(string $ip, string $ua, string $createdAt): array
    {
        $ctx = new Context(ip: $ip, userAgent: $ua, campaignSlug: '', timestamp: (int)strtotime($createdAt));
        if ($this->geo->isAvailable() && $ip !== '') {
            $this->geo->lookup($ctx);
        }
        $this->deviceDetector->detect($ctx, '');
        // device + os reuse the engine detector; browser uses the display-only
        // label (with version + iOS variants) rather than $ctx->browser.
        return [$ctx->country, $ctx->device, $ctx->os, BrowserLabel::make($ua)];
    }
}
