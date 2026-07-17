<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsDuration extends AbstractMigration
{
    public function up(): void
    {
        // First/last rrweb event timestamps (epoch ms) across the whole visit.
        // duration = last - first, matching the player's getMetaData().totalTime.
        // bigint — epoch-ms exceeds int32.
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS first_event_ms bigint');
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS last_event_ms bigint');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS first_event_ms');
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS last_event_ms');
    }
}
