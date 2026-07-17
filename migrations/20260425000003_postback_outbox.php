<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PostbackOutbox extends AbstractMigration
{
    public function up(): void
    {
        // Add postback_urls column to core.offers
        $this->execute(<<<'SQL'
            ALTER TABLE core.offers
            ADD COLUMN postback_urls jsonb NOT NULL DEFAULT '[]'
        SQL);

        // Create postback_deliveries table for outbound S2S postback queue
        $this->execute(<<<'SQL'
            CREATE TABLE core.postback_deliveries (
                id                  uuid        PRIMARY KEY DEFAULT uuidv7(),
                conversion_id       uuid        NOT NULL REFERENCES core.conversions(id) ON DELETE CASCADE,
                target_url          text        NOT NULL,
                attempts            int         NOT NULL DEFAULT 0,
                last_status         int,
                last_response_excerpt text,
                delivered_at        timestamptz,
                next_attempt_at     timestamptz NOT NULL DEFAULT now(),
                created_at          timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // Index for worker: picks up rows pending delivery ordered by next_attempt_at
        $this->execute(<<<'SQL'
            CREATE INDEX idx_postback_deliveries_pending
            ON core.postback_deliveries (next_attempt_at)
            WHERE delivered_at IS NULL
        SQL);

        // Index for looking up all deliveries for a conversion
        $this->execute(<<<'SQL'
            CREATE INDEX idx_postback_deliveries_conversion
            ON core.postback_deliveries (conversion_id)
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.postback_deliveries');
        $this->execute('ALTER TABLE core.offers DROP COLUMN IF EXISTS postback_urls');
    }
}
