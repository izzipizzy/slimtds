<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionInteraction extends AbstractMigration
{
    public function up(): void
    {
        // NULL means historical/unclassified, not "no interaction".
        $this->execute('ALTER TABLE stats.rrweb_sessions ADD COLUMN has_interaction boolean');
        $this->execute('CREATE INDEX idx_rrweb_sessions_interaction ON stats.rrweb_sessions (has_interaction, started_at DESC)');
        $this->execute('CREATE INDEX idx_rrweb_sessions_unclassified ON stats.rrweb_sessions (session_id) WHERE has_interaction IS NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.rrweb_sessions DROP COLUMN has_interaction');
    }
}
