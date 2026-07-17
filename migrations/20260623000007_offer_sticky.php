<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class OfferSticky extends AbstractMigration
{
    public function up(): void
    {
        // Offer assignment mode. Campaign default = sticky (current behaviour:
        // consistent-hash by visitor_uuid → one visitor always gets one offer).
        $this->execute('ALTER TABLE core.campaigns ADD COLUMN IF NOT EXISTS sticky_offer boolean NOT NULL DEFAULT true');
        // Per-flow override: NULL = inherit campaign; true = force sticky;
        // false = rotate per click by weight (weighted random each visit).
        $this->execute('ALTER TABLE core.flows ADD COLUMN IF NOT EXISTS sticky_offer boolean');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.campaigns DROP COLUMN IF EXISTS sticky_offer');
        $this->execute('ALTER TABLE core.flows DROP COLUMN IF EXISTS sticky_offer');
    }
}
