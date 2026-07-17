<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CampaignPostbackToken extends AbstractMigration
{
    public function up(): void
    {
        // Campaign-level postback token: lets a single partner network
        // configure ONE postback URL for the whole campaign (catch-all)
        // instead of one URL per offer. Per-offer tokens remain — controller
        // tries offer.postback_token first, falls back to campaigns.postback_token.
        //
        // Step 1: add nullable column.
        // Step 2: backfill each existing row with its OWN uuidv7() — a column
        //   DEFAULT is evaluated once at ALTER time, so all existing rows
        //   would otherwise share the same token (security hole). We do an
        //   UPDATE per row to mint a fresh token for each campaign.
        // Step 3: lock the column NOT NULL + add a DEFAULT for new rows.
        $this->execute('ALTER TABLE core.campaigns ADD COLUMN IF NOT EXISTS postback_token text');
        $this->execute(<<<'SQL'
            UPDATE core.campaigns
            SET postback_token = replace(uuidv7()::text, '-', '')
            WHERE postback_token IS NULL OR postback_token = ''
        SQL);
        $this->execute(<<<'SQL'
            ALTER TABLE core.campaigns
                ALTER COLUMN postback_token SET NOT NULL,
                ALTER COLUMN postback_token SET DEFAULT replace(uuidv7()::text, '-', '')
        SQL);
        $this->execute('CREATE UNIQUE INDEX IF NOT EXISTS idx_campaigns_postback_token ON core.campaigns (postback_token)');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.idx_campaigns_postback_token');
        $this->execute('ALTER TABLE core.campaigns DROP COLUMN IF EXISTS postback_token');
    }
}
