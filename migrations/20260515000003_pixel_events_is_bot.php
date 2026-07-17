<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add is_bot + bot_name to stats.pixel_events so the admin can hide bot
 * traffic by default in /admin/pixel — same model as stats.clicks.
 *
 * Populated by InboxFlushCommand (BotDetector after GeoLookup) for new
 * events; historical rows are backfilled out-of-band against bot_cidrs /
 * bot_asns.
 *
 * ADD COLUMN on a partitioned table propagates to all partitions in PG11+.
 */
final class PixelEventsIsBot extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN is_bot   boolean NOT NULL DEFAULT false');
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN bot_name text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS bot_name');
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS is_bot');
    }
}
