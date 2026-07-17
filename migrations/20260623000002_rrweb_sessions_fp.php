<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsFp extends AbstractMigration
{
    public function up(): void
    {
        // FingerprintJS visitorId carried by the recorder. It's the durable
        // cross-domain link between a replay session and its clicks/pixel events
        // (the vu cookie is unreliable third-party on the lander's origin).
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS fp_js text');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_fp ON stats.rrweb_sessions (fp_js) WHERE fp_js IS NOT NULL');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_rrweb_sessions_fp');
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS fp_js');
    }
}
