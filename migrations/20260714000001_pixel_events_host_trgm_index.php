<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PixelEventsHostTrgmIndex extends AbstractMigration
{
    public function up(): void
    {
        // The /admin/pixel domain filter matches on the source-page host with
        //   regexp_replace(page_url, '^https?://([^/]+).*', '\1') ILIKE '%host%'
        // which is non-sargable: a full scan recomputing the regexp per row, run
        // 5–6× per page load (count/summary/topDomains/topEventNames/page/timeline).
        // A GIN trigram index on the SAME host expression makes that ILIKE
        // index-accelerated. Non-concurrent build takes a ShareLock: SELECTs keep
        // working; only pixel writes (the inbox:flush INSERT) pause during the build,
        // and events buffer in the UNLOGGED stats.pixel_events_inbox meanwhile.
        // New partitions inherit this index automatically via CREATE TABLE … PARTITION OF.
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->execute(
            "CREATE INDEX IF NOT EXISTS idx_pixel_events_host_trgm
             ON stats.pixel_events
             USING gin ((regexp_replace(page_url, '^https?://([^/]+).*', '\\1')) gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_pixel_events_host_trgm');
    }
}
