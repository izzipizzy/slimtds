<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Stats\StatsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:seed-stats', description: 'Populate stats (clicks/conversions/pixel events/visitors) for the demo')]
final class SeedStatsCommand extends Command
{
    private const SEED = 20260610;
    private const WINDOW_DAYS = 30;
    private const TOTAL_CLICKS = 6000;
    private const TOTAL_PIXEL = 3000;
    private const VISITOR_POOL = 1500;
    private const CR = 0.035; // conversion rate over non-bot clicks
    private const BOT_RATE = 0.12;

    /** click share per campaign slug (must sum to 1.0 over redirect campaigns) */
    private const SHARES = ['casino' => 0.30, 'dating' => 0.50, 'nutra' => 0.20];

    public function __construct(
        private readonly Connection $db,
        private readonly Partitions $partitions,
        private readonly StatsRepository $stats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Truncate seeded stats before generating');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        mt_srand(self::SEED);
        $now = new DateTimeImmutable('now');

        // 1. precondition
        $campaigns = $this->campaignsBySlug();
        if (!isset($campaigns['casino'], $campaigns['dating'], $campaigns['nutra'], $campaigns['site'])) {
            $out->writeln('<error>curated campaigns missing — run `db:seed` first</error>');
            return self::FAILURE;
        }
        $offers = $this->offersByName();

        if (!$in->getOption('fresh')) {
            $existing = (int) $this->db->fetchScalar('SELECT count(*) FROM stats.clicks');
            if ($existing > 0) {
                $out->writeln('<comment>stats already present; re-run with --fresh to regenerate</comment>');
                return self::SUCCESS;
            }
        }

        // 2. partitions covering last/this/next month
        $this->partitions->ensureAhead(2, new DateTimeImmutable('first day of last month 00:00:00'));

        // 3. fresh wipe
        if ($in->getOption('fresh')) {
            $this->db->execute('TRUNCATE stats.clicks, stats.pixel_events, stats.visitors_fingerprints, core.conversions CASCADE');
        }

        // 4. visitor pool
        $visitors = $this->buildVisitorPool();

        // 5. clicks
        $flowByOffer = $this->flowByOffer();
        $clicks = $this->seedClicks($campaigns, $offers, $flowByOffer, $visitors, $now);

        // 6. conversions
        $conversions = $this->seedConversions($now);

        // 7. pixel events + visitor fingerprints
        $pixel = $this->seedPixelEvents($campaigns, $visitors, $now);
        $fp = $this->seedFingerprints($visitors, $now);

        // 8. finalize — refresh matview so /admin/statistics shows data immediately
        $this->stats->refreshClicksHourly();

        $out->writeln(sprintf(
            '<info>clicks:</info> %d  <info>conversions:</info> %d  <info>pixel:</info> %d  <info>fp:</info> %d',
            $clicks, $conversions, $pixel, $fp
        ));
        return self::SUCCESS;
    }

    /** @return array<string,string> slug => campaign uuid */
    private function campaignsBySlug(): array
    {
        $rows = $this->db->fetchAll('SELECT id, slug FROM core.campaigns');
        $map = [];
        foreach ($rows as $r) { $map[(string) $r['slug']] = (string) $r['id']; }
        return $map;
    }

    /** @return array<string,array{id:string,payout:string,currency:string}> name => offer */
    private function offersByName(): array
    {
        $rows = $this->db->fetchAll('SELECT id, name, payout_default, currency FROM core.offers');
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['name']] = [
                'id' => (string) $r['id'],
                'payout' => (string) $r['payout_default'],
                'currency' => (string) $r['currency'],
            ];
        }
        return $map;
    }

    /**
     * Map each campaign's offers to the active flow that targets them, so seeded
     * clicks carry a flow_id (the clicks list hides flow_id IS NULL as "trash").
     * First flow by position that targets an offer wins (mirrors first-match routing).
     *
     * @return array<string,array<string,string>> campaignId => (offerId => flowId)
     */
    private function flowByOffer(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, campaign_id, target_offers FROM core.flows
             WHERE is_active AND target_type = 'offers'
             ORDER BY position, created_at"
        );
        $map = [];
        foreach ($rows as $r) {
            $cid = (string) $r['campaign_id'];
            $targets = json_decode((string) $r['target_offers'], true);
            if (!is_array($targets)) {
                continue;
            }
            foreach ($targets as $t) {
                $oid = is_array($t) ? (string) ($t['offer_id'] ?? '') : '';
                if ($oid === '') {
                    continue;
                }
                $map[$cid][$oid] ??= (string) $r['id'];
            }
        }
        return $map;
    }

    /** @return list<string> */
    private function buildVisitorPool(): array
    {
        // Determinism note: mt_srand fixes the *shape* (counts, shares, geo/device mix),
        // but uuid7() uses random_bytes — so visitor identities differ each run while
        // the distribution stays stable. The pool is rebuilt fresh within each invocation.
        $pool = [];
        for ($i = 0; $i < self::VISITOR_POOL; $i++) {
            $pool[] = Uuid::uuid7()->toString();
        }
        return $pool;
    }

    /**
     * @param array<string,string> $campaigns
     * @param array<string,array{id:string,payout:string,currency:string}> $offers
     * @param array<string,array<string,string>> $flowByOffer
     * @param list<string> $visitors
     */
    private function seedClicks(array $campaigns, array $offers, array $flowByOffer, array $visitors, DateTimeImmutable $now): int
    {
        $profiles = $this->clickProfiles();
        $seen = [];
        $count = 0;

        $this->db->transactional(function (Connection $db) use ($campaigns, $offers, $flowByOffer, $visitors, $profiles, $now, &$seen, &$count): void {
            $stmt = $db->pdo->prepare(<<<'SQL'
                INSERT INTO stats.clicks
                    (id, campaign_id, flow_id, offer_id, visitor_uuid, ip, country, device, os, browser, lang,
                     is_bot, is_uniq, schema_id, http_status, created_at)
                VALUES
                    (:id, :cid, :flow, :oid, :vid, :ip, :country, :device, :os, :browser, :lang,
                     :is_bot, :is_uniq, 2, :http, :ts)
            SQL);

            foreach (self::SHARES as $slug => $share) {
                $n = (int) round(self::TOTAL_CLICKS * $share);
                $p = $profiles[$slug];
                for ($i = 0; $i < $n; $i++) {
                    $country = $this->pickWeighted($p['geo']);
                    $device = $this->pickWeighted($p['device']);
                    $isBot = (mt_rand() / mt_getrandmax()) < self::BOT_RATE;
                    $visitor = $visitors[mt_rand(0, count($visitors) - 1)];
                    $isUniq = !isset($seen[$visitor]);
                    $seen[$visitor] = true;
                    $offerName = $this->offerFor($slug, $country);
                    $offerId = $offers[$offerName]['id'];
                    $ts = $this->randomTs($now);

                    $stmt->execute([
                        'id' => Uuid::uuid7()->toString(),
                        'cid' => $campaigns[$slug],
                        'flow' => $flowByOffer[$campaigns[$slug]][$offerId] ?? null,
                        'oid' => $offerId,
                        'vid' => $visitor,
                        'ip' => $this->randomIp(),
                        'country' => $country,
                        'device' => $device,
                        'os' => $this->osFor($device),
                        'browser' => $this->browserFor($device),
                        'lang' => $this->langFor($country),
                        'is_bot' => $isBot ? 'true' : 'false',
                        'is_uniq' => $isUniq ? 'true' : 'false',
                        'http' => 302,
                        'ts' => $ts->format('Y-m-d H:i:sP'),
                    ]);
                    $count++;
                }
            }
        });

        return $count;
    }

    /** @return array<string,array{geo:array<string,int>,device:array<string,int>}> */
    private function clickProfiles(): array
    {
        return [
            'casino' => [
                'geo' => ['RU' => 30, 'UA' => 12, 'KZ' => 8, 'BY' => 5, 'DE' => 18, 'US' => 15, 'GB' => 12],
                'device' => ['mobile' => 55, 'desktop' => 40, 'tablet' => 5],
            ],
            'dating' => [
                'geo' => ['US' => 20, 'DE' => 12, 'FR' => 10, 'BR' => 12, 'IN' => 14, 'RU' => 10, 'GB' => 10, 'ID' => 12],
                'device' => ['mobile' => 75, 'desktop' => 20, 'tablet' => 5],
            ],
            'nutra' => [
                'geo' => ['MX' => 70, 'CO' => 12, 'CL' => 8, 'PE' => 10],
                'device' => ['mobile' => 85, 'desktop' => 12, 'tablet' => 3],
            ],
        ];
    }

    private function offerFor(string $slug, string $country): string
    {
        return match ($slug) {
            'casino' => in_array($country, ['RU', 'UA', 'KZ', 'BY'], true) ? 'CIS Casino' : 'EU Casino',
            'dating' => (mt_rand(0, 1) === 0) ? 'Dating A' : 'Dating B',
            'nutra' => (mt_rand() / mt_getrandmax()) < 0.90 ? 'Nutra COD' : 'CIS Casino', // intentional cross-campaign reuse (mirrors "Fallback → CIS Casino" flow in db:seed)
            default => 'CIS Casino',
        };
    }

    private function langFor(string $country): string
    {
        return match ($country) {
            'RU', 'BY', 'KZ' => 'ru',
            'UA' => 'uk',
            'DE' => 'de',
            'FR' => 'fr',
            'BR' => 'pt',
            'MX', 'CO', 'CL', 'PE' => 'es',
            'IN', 'ID', 'US', 'GB' => 'en',
            default => 'en',
        };
    }

    private function osFor(string $device): string
    {
        return match ($device) {
            'mobile' => (mt_rand(0, 2) === 0) ? 'iOS' : 'Android',
            'tablet' => (mt_rand(0, 1) === 0) ? 'iPadOS' : 'Android',
            default => (mt_rand(0, 2) === 0) ? 'macOS' : 'Windows',
        };
    }

    private function browserFor(string $device): string
    {
        return $device === 'desktop'
            ? (mt_rand(0, 2) === 0 ? 'Firefox' : 'Chrome')
            : (mt_rand(0, 2) === 0 ? 'Safari' : 'Chrome');
    }

    /** @param array<string,int> $weights @return string chosen key */
    private function pickWeighted(array $weights): string
    {
        $total = array_sum($weights);
        $r = mt_rand(1, $total);
        $acc = 0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($r <= $acc) {
                return (string) $key;
            }
        }
        return (string) array_key_first($weights);
    }

    private function randomTs(DateTimeImmutable $now): DateTimeImmutable
    {
        // bias toward recent days: sqrt skews daysAgo toward 0
        $r = mt_rand() / mt_getrandmax();
        $daysAgo = (int) floor(self::WINDOW_DAYS * (1.0 - sqrt($r)));
        $hour = $this->pickHour();
        $ts = $now->modify("-{$daysAgo} days")->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
        return $ts > $now ? $now : $ts;
    }

    private function pickHour(): int
    {
        // day/night rhythm, peak ~20:00
        /** @var array<string, int> $weights */
        $weights = [];
        for ($h = 0; $h < 24; $h++) {
            $weights[(string) $h] = (int) round(10 + 9 * sin(($h - 8) / 24 * 2 * M_PI) + 9);
        }
        return (int) $this->pickWeighted($weights);
    }

    private function randomIp(): string
    {
        return mt_rand(1, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
    }

    /**
     * @param array<string,string> $campaigns
     * @param list<string> $visitors
     */
    private function seedPixelEvents(array $campaigns, array $visitors, DateTimeImmutable $now): int
    {
        // 80% of events belong to the pixel-only "site" campaign, 20% to redirect campaigns
        $campaignWeights = ['site' => 80, 'casino' => 6, 'dating' => 9, 'nutra' => 5];
        $eventWeights = ['pageview' => 70, 'docs_view' => 12, 'download_click' => 10, 'demo_launch' => 8];
        $pages = ['/', '/docs', '/demo', '/download', '/#features'];

        $this->db->transactional(function (Connection $db) use ($campaigns, $visitors, $campaignWeights, $eventWeights, $pages, $now): void {
            $stmt = $db->pdo->prepare(<<<'SQL'
                INSERT INTO stats.pixel_events
                    (campaign_id, visitor_uuid, event_name, page_url, country, lang, is_bot, created_at)
                VALUES
                    (:cid, :vid, :event, :page, :country, :lang, :is_bot, :ts)
            SQL);

            for ($i = 0; $i < self::TOTAL_PIXEL; $i++) {
                $slug = $this->pickWeighted($campaignWeights);
                $event = $this->pickWeighted($eventWeights);
                $country = $this->pickWeighted(['US' => 30, 'DE' => 15, 'RU' => 15, 'GB' => 12, 'FR' => 10, 'BR' => 10, 'IN' => 8]);
                $isBot = (mt_rand() / mt_getrandmax()) < self::BOT_RATE;

                $stmt->execute([
                    'cid' => $campaigns[$slug],
                    'vid' => $visitors[mt_rand(0, count($visitors) - 1)],
                    'event' => $event,
                    'page' => $pages[mt_rand(0, count($pages) - 1)],
                    'country' => $country,
                    'lang' => $this->langFor($country),
                    'is_bot' => $isBot ? 'true' : 'false',
                    'ts' => $this->randomTs($now)->format('Y-m-d H:i:sP'),
                ]);
            }
        });

        return self::TOTAL_PIXEL;
    }

    /** @param list<string> $visitors */
    private function seedFingerprints(array $visitors, DateTimeImmutable $now): int
    {
        // fingerprint the first ~40% of the pool (these are the recurring visitors)
        $n = (int) round(count($visitors) * 0.40);

        $this->db->transactional(function (Connection $db) use ($visitors, $n, $now): void {
            $stmt = $db->pdo->prepare(<<<'SQL'
                INSERT INTO stats.visitors_fingerprints (fp_hash, visitor_uuid, created_at)
                VALUES (decode(:hex, 'hex'), :vid, :ts)
            SQL);

            for ($i = 0; $i < $n; $i++) {
                $stmt->execute([
                    'hex' => bin2hex(random_bytes(16)),
                    'vid' => $visitors[$i],
                    'ts' => $this->randomTs($now)->format('Y-m-d H:i:sP'),
                ]);
            }
        });

        return $n;
    }

    private function seedConversions(DateTimeImmutable $now): int
    {
        $rows = $this->db->fetchAll(<<<'SQL'
            SELECT c.id, c.campaign_id, c.offer_id, c.created_at,
                   o.payout_default, o.currency
            FROM stats.clicks c
            JOIN core.offers o ON o.id = c.offer_id
            WHERE NOT c.is_bot AND c.offer_id IS NOT NULL
              AND c.created_at >= now() - interval '31 days'
        SQL);

        $statusWeights = ['approved' => 70, 'pending' => 20, 'rejected' => 10];
        $count = 0;

        $this->db->transactional(function (Connection $db) use ($rows, $statusWeights, $now, &$count): void {
            $stmt = $db->pdo->prepare(<<<'SQL'
                INSERT INTO core.conversions
                    (click_id, campaign_id, offer_id, payout, currency, status, created_at, updated_at)
                VALUES
                    (:click_id, :cid, :oid, :payout, :currency, :status, :ts, :ts)
            SQL);

            foreach ($rows as $r) {
                if ((mt_rand() / mt_getrandmax()) >= self::CR) {
                    continue;
                }
                $base = (float) $r['payout_default'];
                $jitter = 0.8 + (mt_rand() / mt_getrandmax()) * 0.4; // 0.8..1.2
                $payout = round($base * $jitter, 2);
                if ($payout <= 0) {
                    $payout = $base > 0 ? $base : 1.0;
                }

                $delayMin = mt_rand(1, 6 * 60); // up to 6h after the click
                $ts = (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->modify("+{$delayMin} minutes");
                if ($ts > $now) {
                    $ts = $now;
                }

                $stmt->execute([
                    'click_id' => (string) $r['id'],
                    'cid' => (string) $r['campaign_id'],
                    'oid' => (string) $r['offer_id'],
                    'payout' => number_format($payout, 2, '.', ''),
                    'currency' => (string) $r['currency'],
                    'status' => $this->pickWeighted($statusWeights),
                    'ts' => $ts->format('Y-m-d H:i:sP'),
                ]);
                $count++;
            }
        });

        return $count;
    }
}
