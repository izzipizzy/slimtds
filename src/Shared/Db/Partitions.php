<?php

declare(strict_types=1);

namespace App\Shared\Db;

use DateTimeImmutable;
use PDO;

/**
 * Менеджер RANGE-партиций по месяцам для таблиц stats.*.
 */
final class Partitions
{
    /** @var array<string, array{retention_days: int, brin: bool, extra_indexes: list<string>}> */
    private const DAILY_TABLES = [
        'stats.rrweb_chunks' => [
            'retention_days' => 30,
            'brin'           => true,
            'extra_indexes'  => [
                'CREATE INDEX IF NOT EXISTS %name%_session_idx ON %table% (session_id)',
            ],
        ],
    ];

    /** @var array<string, array{retention_days: int, brin: bool, extra_indexes: list<string>}> */
    private const TABLES = [
        'stats.clicks' => [
            'retention_days' => 365,
            'brin'           => true,
            'extra_indexes'  => [
                'CREATE INDEX IF NOT EXISTS %name%_campaign_idx ON %table% (campaign_id, created_at DESC)',
                'CREATE INDEX IF NOT EXISTS %name%_visitor_idx ON %table% (visitor_uuid)',
                'CREATE INDEX IF NOT EXISTS %name%_id_idx ON %table% (id)',
            ],
        ],
        'stats.pixel_events' => [
            'retention_days' => 90,
            'brin'           => true,
            'extra_indexes'  => [
                'CREATE INDEX IF NOT EXISTS %name%_campaign_idx ON %table% (campaign_id, created_at DESC)',
                'CREATE INDEX IF NOT EXISTS %name%_visitor_idx ON %table% (visitor_uuid)',
                'CREATE INDEX IF NOT EXISTS %name%_fpjs_idx ON %table% (fp_js) WHERE fp_js IS NOT NULL',
            ],
        ],
        'stats.visitors_fingerprints' => [
            'retention_days' => 30,
            'brin'           => false,
            'extra_indexes'  => [
                'CREATE INDEX IF NOT EXISTS %name%_hash_idx ON %table% (fp_hash)',
            ],
        ],
    ];

    /** @var array<string,int> */
    private array $overrides = [];

    public function __construct(private readonly PDO $pdo) {}

    public function setRetention(string $parent, int $days): void
    {
        if ($days < 1 || $days > 3650) {
            throw new \InvalidArgumentException('retention out of bounds');
        }
        $this->overrides[$parent] = $days;
    }

    private function retentionFor(string $parent): int
    {
        return $this->overrides[$parent]
            ?? self::TABLES[$parent]['retention_days']
            ?? self::DAILY_TABLES[$parent]['retention_days'];
    }

    /**
     * Создаёт партиции на месяцы от now() до now()+$aheadMonths.
     * @return list<string> Имена созданных партиций.
     */
    public function ensureAhead(int $aheadMonths = 2, ?DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        if ($now === null) {
            $now = new DateTimeImmutable('first day of this month 00:00:00', $utc);
        } else {
            // Caller-supplied datetimes: re-anchor the date portion to UTC midnight
            // so partition month boundaries are always whole calendar months in UTC.
            $now = new DateTimeImmutable($now->format('Y-m-01 00:00:00'), $utc);
        }
        $created = [];

        foreach (array_keys(self::TABLES) as $parent) {
            for ($i = 0; $i <= $aheadMonths; $i++) {
                $start = $now->modify("+{$i} months");
                $end   = $start->modify('+1 month');
                if ($this->createPartition($parent, $start, $end)) {
                    $created[] = $this->partitionName($parent, $start);
                }
            }
        }
        return $created;
    }

    /**
     * Дропает партиции старше ретеншена для каждой таблицы.
     * @return list<string> Имена дропнутых партиций.
     */
    public function dropOlderThanRetention(?DateTimeImmutable $now = null): array
    {
        $utc = new \DateTimeZone('UTC');
        $now = $now !== null
            ? new DateTimeImmutable($now->format('Y-m-d H:i:s'), $utc)
            : new DateTimeImmutable('now', $utc);
        $dropped = [];

        foreach (self::TABLES as $parent => $cfg) {
            $retentionDays = $this->retentionFor($parent);
            $cutoff = $now->modify("-{$retentionDays} days");

            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT i.inhrelid::regclass::text AS part_name,
                       pg_get_expr(c.relpartbound, i.inhrelid) AS bound
                FROM pg_inherits i
                JOIN pg_class c ON c.oid = i.inhrelid
                WHERE i.inhparent = (:parent)::regclass
            SQL);
            $stmt->execute(['parent' => $parent]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!preg_match("/TO \\('([^']+)'\\)/", $row['bound'], $m)) {
                    continue;
                }
                $endDate = new DateTimeImmutable($m[1]);
                if ($endDate <= $cutoff) {
                    $this->pdo->exec("DROP TABLE IF EXISTS {$row['part_name']}");
                    $dropped[] = $row['part_name'];
                }
            }
        }
        return $dropped;
    }

    /**
     * Создаёт дневные партиции от today до today+$aheadDays для DAILY_TABLES.
     * @return list<string> Имена созданных партиций.
     */
    public function ensureDailyAhead(int $aheadDays = 3, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('today 00:00:00');
        $created = [];
        foreach (self::DAILY_TABLES as $parent => $cfg) {
            for ($i = 0; $i <= $aheadDays; $i++) {
                $start = $now->modify("+{$i} days");
                $end   = $start->modify('+1 day');
                $name  = "{$parent}_" . $start->format('Y_m_d');
                if ($this->createConcretePartition($parent, $cfg, $name, $start, $end)) {
                    $created[] = $name;
                }
            }
        }
        return $created;
    }

    /**
     * Дропает дневные партиции DAILY_TABLES старше ретеншена.
     * @return list<string>
     */
    public function dropDailyOlderThanRetention(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $dropped = [];
        foreach (array_keys(self::DAILY_TABLES) as $parent) {
            $cutoff = $now->modify('-' . $this->retentionFor($parent) . ' days');
            $stmt = $this->pdo->prepare(<<<'SQL'
                SELECT i.inhrelid::regclass::text AS part_name,
                       pg_get_expr(c.relpartbound, i.inhrelid) AS bound
                FROM pg_inherits i
                JOIN pg_class c ON c.oid = i.inhrelid
                WHERE i.inhparent = (:parent)::regclass
            SQL);
            $stmt->execute(['parent' => $parent]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!preg_match("/TO \\('([^']+)'\\)/", $row['bound'], $m)) {
                    continue;
                }
                if (new DateTimeImmutable($m[1]) <= $cutoff) {
                    $this->pdo->exec("DROP TABLE IF EXISTS {$row['part_name']}");
                    $dropped[] = $row['part_name'];
                }
            }
        }
        return $dropped;
    }

    private function createPartition(string $parent, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $name = $this->partitionName($parent, $start);
        return $this->createConcretePartition($parent, self::TABLES[$parent], $name, $start, $end);
    }

    /**
     * Создаёт одну партицию с BRIN + extra-индексами. Возвращает false если уже есть.
     * @param array{retention_days:int,brin:bool,extra_indexes:list<string>} $cfg
     */
    private function createConcretePartition(string $parent, array $cfg, string $name, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        [$_schema, $table] = explode('.', $name);
        $exists = (bool)$this->pdo->query(
            "SELECT 1 FROM pg_class WHERE relname = " . $this->pdo->quote($table)
        )->fetchColumn();
        if ($exists) {
            return false;
        }
        // Boundaries are pinned to UTC midnight explicitly. A bare 'Y-m-d' literal
        // would be parsed in the session timezone (the PDO factory sets it to APP_TZ,
        // e.g. Europe/Moscow), shifting the bound off UTC and overlapping the
        // UTC-aligned partitions created by migrations. Convert to UTC first (in case
        // the bound was built in another tz) and then pin midnight+00. Storage is UTC
        // (D-tz), so partition ranges must be too.
        $startStr = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') . ' 00:00:00+00';
        $endStr   = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') . ' 00:00:00+00';
        $this->pdo->exec("CREATE TABLE {$name} PARTITION OF {$parent} FOR VALUES FROM ('{$startStr}') TO ('{$endStr}')");
        if ($cfg['brin']) {
            $this->pdo->exec("CREATE INDEX ON {$name} USING brin (created_at)");
        }
        $baseName = str_replace('.', '_', $name);
        foreach ($cfg['extra_indexes'] as $tpl) {
            $this->pdo->exec(str_replace(['%name%', '%table%'], [$baseName, $name], $tpl));
        }
        return true;
    }

    private function partitionName(string $parent, DateTimeImmutable $start): string
    {
        [$schema, $table] = explode('.', $parent);
        return "{$schema}.{$table}_" . $start->format('Y_m');
    }
}
