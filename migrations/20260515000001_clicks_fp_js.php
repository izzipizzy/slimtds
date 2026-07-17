<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ClicksFpJs extends AbstractMigration
{
    public function up(): void
    {
        // FingerprintJS visitor ID resolved at click time by looking up the
        // most recent pixel event for the same visitor_uuid. Lets the admin
        // filter clicks + pixels by the same stable cross-domain identity —
        // analogous to how conversions tie clicks together by subid/click_id.
        // Nullable: clicks from visitors that never fired the pixel keep it null.
        // ADD COLUMN on a partitioned table auto-propagates to all partitions in PG11+.
        $this->execute('ALTER TABLE stats.clicks ADD COLUMN fp_js text');
        $this->execute('CREATE INDEX idx_clicks_fp_js ON stats.clicks (fp_js) WHERE fp_js IS NOT NULL');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_clicks_fp_js');
        $this->execute('ALTER TABLE stats.clicks DROP COLUMN IF EXISTS fp_js');
    }
}
