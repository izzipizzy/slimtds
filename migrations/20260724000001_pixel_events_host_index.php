<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PixelEventsHostIndex extends AbstractMigration
{
    public function up(): void
    {
        // /admin/pixel?domain=X was slow. The domain filter used
        // regexp_replace(page_url,…) ILIKE '%domain%', which only the GIN
        // trigram index (idx_pixel_events_host_trgm) can serve — and only
        // lossily: it re-checks the regexp on every candidate heap row. On a
        // popular domain (~27k rows in the current month) a single count() took
        // ~0.85s, and the page fires several such scans (list, count, summary,
        // topDomains, topEventNames).
        //
        // A btree on the SAME host expression lets the repo's exact-match filter
        // ("… = :domain") do a cheap index lookup with no heap recheck. Created
        // non-ONLY on the partitioned parent so it cascades to all existing
        // partitions now, and to future ones (partitions:rotate does CREATE
        // TABLE … PARTITION OF, which auto-creates matching parent indexes).
        // pixel_events is written only by the inbox:flush cron (requests land in
        // stats.pixel_events_inbox), so the non-concurrent build's ShareLock
        // never touches the request/engine hot path.
        $this->execute(
            "CREATE INDEX IF NOT EXISTS idx_pixel_events_host
             ON stats.pixel_events ((regexp_replace(page_url, '^https?://([^/]+).*', '\\1')))"
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_pixel_events_host');
    }
}
