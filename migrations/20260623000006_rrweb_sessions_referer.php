<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsReferer extends AbstractMigration
{
    public function up(): void
    {
        // Entry referer of the visit (document.referrer on the first page) — lets
        // the sessions list classify the traffic source (Google/ChatGPT/…) the
        // same way clicks/pixel do, via Shared\Referer\SearchEngine.
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS referer text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS referer');
    }
}
