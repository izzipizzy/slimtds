<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsUrl extends AbstractMigration
{
    public function up(): void
    {
        // Entry page URL of the session (first rrweb Meta event's href). Lets the
        // sessions list show the domain without decoding chunks.
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS page_url text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS page_url');
    }
}
