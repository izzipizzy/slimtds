<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PixelEventsUa extends AbstractMigration
{
    public function up(): void
    {
        // Adds device/os/browser to pixel_events. user_agent already exists.
        // Partitioned table — DDL propagates to existing partitions automatically.
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN IF NOT EXISTS device  text');
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN IF NOT EXISTS os      text');
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN IF NOT EXISTS browser text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS browser');
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS os');
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS device');
    }
}
