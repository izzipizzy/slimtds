<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PixelEventsInbox extends AbstractMigration
{
    public function up(): void
    {
        // Outbox/inbox staging for /p/event — handler writes raw payload here in
        // ~15ms, a worker drains rows into stats.pixel_events asynchronously.
        // UNLOGGED skips WAL — fast inserts, but data is lost on PG crash. That's
        // acceptable here since we only stage events for at most a couple of seconds.
        $this->execute(<<<'SQL'
            CREATE UNLOGGED TABLE IF NOT EXISTS stats.pixel_events_inbox (
                id          uuid        NOT NULL DEFAULT uuidv7() PRIMARY KEY,
                payload     jsonb       NOT NULL,
                ip          inet,
                user_agent  text,
                accept_lang text,
                visitor_uuid uuid,
                is_new_visitor boolean   NOT NULL DEFAULT false,
                created_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute('CREATE INDEX IF NOT EXISTS idx_pixel_events_inbox_created ON stats.pixel_events_inbox (created_at)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS stats.pixel_events_inbox');
    }
}
