<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RrwebSessionsStartedIndexFix extends AbstractMigration
{
    public function up(): void
    {
        // The sessions list orders by `started_at DESC NULLS LAST, session_id DESC`.
        // The plain (started_at DESC) index from the previous migration is
        // NULLS FIRST and lacks the session_id tiebreaker, so the planner ignored
        // it and did a full seq scan + top-N sort (~16k pages). Recreate it to
        // match the ORDER BY exactly → Index Scan + Limit (a handful of pages).
        $this->execute('DROP INDEX IF EXISTS stats.idx_rrweb_sessions_started');
        $this->execute(
            'CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_started
             ON stats.rrweb_sessions (started_at DESC NULLS LAST, session_id DESC)'
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS stats.idx_rrweb_sessions_started');
        $this->execute(
            'CREATE INDEX IF NOT EXISTS idx_rrweb_sessions_started
             ON stats.rrweb_sessions (started_at DESC)'
        );
    }
}
