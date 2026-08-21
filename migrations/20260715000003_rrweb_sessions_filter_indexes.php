<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsFilterIndexes extends AbstractMigration
{
    /** Filter columns whose dropdowns use a loose-index-scan DISTINCT. */
    private const COLUMNS = ['country', 'browser', 'os', 'device'];

    public function up(): void
    {
        // RrwebSessionRepository::distinct() builds each filter dropdown with a
        // loose index scan (skip scan) that hops between the few distinct values
        // via a btree index, instead of a full seq scan + DISTINCT over ~600k
        // rows (which Postgres will not accelerate with an index). That needs a
        // plain btree on each filter column. The host/domain expression already
        // has its functional index (idx_rrweb_sessions_host).
        foreach (self::COLUMNS as $col) {
            $this->execute(
                "CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_{$col}
                 ON stats.rrweb_sessions ({$col})"
            );
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $col) {
            $this->execute("DROP INDEX IF EXISTS stats.idx_rrweb_sessions_{$col}");
        }
    }
}
