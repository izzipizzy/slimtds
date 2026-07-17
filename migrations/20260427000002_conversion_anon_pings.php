<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ConversionAnonPings extends AbstractMigration
{
    public function up(): void
    {
        // Allow click_id/offer_id to be NULL so the campaign-level postback
        // URL can register a conversion ping even when the partner network
        // didn't send a sub_id (e.g. their test button, or their reporting
        // pipeline that fires per-campaign without a per-click identifier).
        // The unique index on click_id treats NULLs as distinct, so multiple
        // anon pings can co-exist without conflict.
        $this->execute('ALTER TABLE core.conversions ALTER COLUMN click_id DROP NOT NULL');
        $this->execute('ALTER TABLE core.conversions ALTER COLUMN offer_id DROP NOT NULL');
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.conversions WHERE click_id IS NULL OR offer_id IS NULL");
        $this->execute('ALTER TABLE core.conversions ALTER COLUMN click_id SET NOT NULL');
        $this->execute('ALTER TABLE core.conversions ALTER COLUMN offer_id SET NOT NULL');
    }
}
