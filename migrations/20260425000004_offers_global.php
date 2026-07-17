<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class OffersGlobal extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.idx_offers_campaign');
        $this->execute('ALTER TABLE core.offers DROP COLUMN IF EXISTS campaign_id');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.offers ADD COLUMN campaign_id uuid');
        $this->execute('CREATE INDEX idx_offers_campaign ON core.offers (campaign_id)');
        // Note: orphaned offers (used in multiple campaigns) cannot be re-attached
        // automatically — operators must choose campaign manually before re-applying NOT NULL.
    }
}
