<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsClient extends AbstractMigration
{
    public function up(): void
    {
        // Client details for the sessions list: visitor IP, OS, and browser+version.
        // device + country already exist; these complete the "client" column.
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS ip text');
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS os text');
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN IF NOT EXISTS browser text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS ip');
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS os');
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN IF EXISTS browser');
    }
}
