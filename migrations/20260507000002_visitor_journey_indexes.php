<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class VisitorJourneyIndexes extends AbstractMigration
{
    public function up(): void
    {
        // Composite indexes for "give me everything by visitor in chronological order".
        // Without these the journey query has to scan every monthly partition's BRIN
        // — fine for tiny datasets, painful once we accumulate months of pixel events.
        // Postgres declarative partitioning propagates these to existing partitions.
        $this->execute('CREATE INDEX IF NOT EXISTS idx_pixel_events_visitor_time ON stats.pixel_events (visitor_uuid, created_at DESC)');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_clicks_visitor_time ON stats.clicks (visitor_uuid, created_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_pixel_events_visitor_time');
        $this->execute('DROP INDEX IF EXISTS stats.idx_clicks_visitor_time');
    }
}
