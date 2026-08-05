<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PixelEventsEntryReferer extends AbstractMigration
{
    public function up(): void
    {
        // The referer of the visit's ENTRY page, captured once by the pixel and
        // replayed on every later pageview of the same tab. `referer` alone is
        // useless for attribution once the visitor navigates inside the lander —
        // it then points at the lander itself, which is exactly how genuine
        // search traffic ends up indistinguishable from direct.
        $this->execute('ALTER TABLE stats.pixel_events ADD COLUMN IF NOT EXISTS entry_referer text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.pixel_events DROP COLUMN IF EXISTS entry_referer');
    }
}
