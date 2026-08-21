<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsPerfIndexes extends AbstractMigration
{
    public function up(): void
    {
        // /admin/sessions was slow: on a 600k-row stats.rrweb_sessions it ran a
        // full seq scan + top-N sort for the default list (ORDER BY started_at
        // DESC) and a full-scan DISTINCT per filter dropdown (the host one — a
        // regexp substring over every row — took up to ~6s). These two indexes
        // fix both. rrweb_sessions is written only by the rrweb:flush cron (rows
        // buffer in stats.rrweb_inbox), so the non-concurrent build's ShareLock
        // does not affect the request path.
        $this->execute(
            'CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_started
             ON stats.rrweb_sessions (started_at DESC)'
        );

        // Same host expression the domain filter and its dropdown use
        // (RrwebSessionRepository::HOST_EXPR). A btree on it makes the
        // `substring(...) = :domain` filter an index lookup and the DISTINCT an
        // index/skip scan instead of a regexp-per-row seq scan.
        $this->execute(
            "CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_host
             ON stats.rrweb_sessions ((substring(page_url from '://([^/]+)')))"
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_rrweb_sessions_host');
        $this->execute('DROP INDEX IF EXISTS stats.idx_rrweb_sessions_started');
    }
}
