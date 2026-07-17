<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebTables extends AbstractMigration
{
    public function up(): void
    {
        // Staging inbox for /p/rec — handler writes raw chunk here fast, the
        // rrweb:flush worker drains into stats.rrweb_chunks asynchronously.
        // UNLOGGED skips WAL (fast inserts, lost on PG crash — acceptable, the
        // staging window is seconds).
        $this->execute(<<<'SQL'
            CREATE UNLOGGED TABLE IF NOT EXISTS stats.rrweb_inbox (
                id           uuid        NOT NULL DEFAULT uuidv7() PRIMARY KEY,
                payload      jsonb       NOT NULL,
                ip           inet,
                user_agent   text,
                visitor_uuid uuid,
                created_at   timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute('CREATE INDEX IF NOT EXISTS idx_rrweb_inbox_created ON stats.rrweb_inbox (created_at)');

        // Heavy data — gzipped rrweb event batches. DAILY RANGE partitions so a
        // 7-day retention can drop whole days and return disk to the OS cheaply.
        // Concrete partitions + per-partition indexes are created by
        // App\Shared\Db\Partitions::ensureDailyAhead().
        $this->execute(<<<'SQL'
            CREATE TABLE stats.rrweb_chunks (
                id           uuid        NOT NULL DEFAULT uuidv7(),
                session_id   uuid        NOT NULL,
                campaign_id  uuid        NOT NULL,
                visitor_uuid uuid,
                seq          int         NOT NULL,
                payload      bytea       NOT NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (created_at, id)
            ) PARTITION BY RANGE (created_at)
        SQL);

        // Lightweight per-session index for the admin list. Small (one row per
        // session) — plain table, cleaned by DELETE in partitions:rotate.
        $this->execute(<<<'SQL'
            CREATE TABLE stats.rrweb_sessions (
                session_id   uuid        PRIMARY KEY,
                campaign_id  uuid        NOT NULL,
                visitor_uuid uuid,
                started_at   timestamptz NOT NULL,
                last_at      timestamptz NOT NULL,
                chunk_count  int         NOT NULL DEFAULT 0,
                event_count  int         NOT NULL DEFAULT 0,
                bytes        bigint      NOT NULL DEFAULT 0,
                country      text,
                device       text
            )
        SQL);
        $this->execute('CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_campaign ON stats.rrweb_sessions (campaign_id, started_at DESC)');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_visitor ON stats.rrweb_sessions (visitor_uuid)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS stats.rrweb_sessions');
        $this->execute('DROP TABLE IF EXISTS stats.rrweb_chunks CASCADE');
        $this->execute('DROP TABLE IF EXISTS stats.rrweb_inbox');
    }
}
