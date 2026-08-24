<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CoreUpdateStatus extends AbstractMigration
{
    public function up(): void
    {
        // Observed operational state, not operator configuration — so a typed
        // row rather than five core.settings keys. Five independent upserts are
        // five transactions, and a reader between them can see a new tag beside
        // an old URL. One row, written in one UPSERT, cannot tear.
        //
        // `channel` is the primary key and is CHECK-constrained to the single
        // value we support: that is what enforces "exactly one row".
        //
        // `repo` records which repository the stored data and etag came from.
        // Without it a fresh cron process cannot tell that the persisted
        // validator belongs to a different repository, and would send its
        // If-None-Match, take a 304, and serve the old repo's version forever.
        //
        // last_attempt_at and last_success_at are separate on purpose. One
        // timestamp cannot answer both "when did we last try" and "how old is
        // the answer we are showing", and conflating them is how a checker
        // broken for months keeps asserting a fresh verdict.
        $this->execute(<<<'SQL'
            CREATE TABLE core.update_status (
                channel         text PRIMARY KEY DEFAULT 'github'
                                CHECK (channel = 'github'),
                repo            text        NOT NULL,
                latest_version  text,
                latest_url      text,
                published_at    timestamptz,
                last_attempt_at timestamptz,
                last_success_at timestamptz,
                last_error      text,
                etag            text,
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.update_status');
    }
}
