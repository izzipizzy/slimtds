<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RemoveEngagementMode extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.flows DROP COLUMN IF EXISTS engagement_mode');
        $this->execute('ALTER TABLE core.campaigns DROP COLUMN IF EXISTS engagement_mode');
    }

    public function down(): void
    {
        // Removed settings cannot be recovered; restore with Clickunder disabled.
        $this->execute("ALTER TABLE core.campaigns ADD COLUMN engagement_mode text NOT NULL DEFAULT 'off'
            CONSTRAINT ck_campaigns_engagement_mode CHECK (engagement_mode IN ('off', 'overlay', 'redirect', 'random'))");
        $this->execute("ALTER TABLE core.flows ADD COLUMN engagement_mode text
            CONSTRAINT ck_flows_engagement_mode CHECK (engagement_mode IS NULL OR engagement_mode IN ('off', 'overlay', 'redirect', 'random'))");
    }
}
