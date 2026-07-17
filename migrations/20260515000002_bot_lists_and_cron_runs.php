<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bot detection beyond the search-engine IP list:
 *   - bot_asns:  numeric ASN list of known datacenter / hosting / VPS providers
 *                (brianhama/bad-asn-list)
 *   - bot_cidrs: CIDR ranges of datacenters + commercial VPN providers
 *                (X4BNet/lists_vpn — output/datacenter and output/vpn)
 *
 * Plus a universal cron-run journal so the admin can see what the daily
 * jobs are doing without shelling in for logs.
 */
final class BotListsAndCronRuns extends AbstractMigration
{
    public function up(): void
    {
        // ── bot_asns ───────────────────────────────────────────────────
        $this->execute(<<<'SQL'
            CREATE TABLE core.bot_asns (
                asn         integer PRIMARY KEY,
                name        text,
                source      text NOT NULL,
                updated_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // ── bot_cidrs ──────────────────────────────────────────────────
        // kind ∈ {'datacenter','vpn'} so the operator can distinguish.
        $this->execute(<<<'SQL'
            CREATE TABLE core.bot_cidrs (
                net         cidr PRIMARY KEY,
                kind        text NOT NULL,
                source      text NOT NULL,
                updated_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // GiST index over inet/cidr — required for fast `ip <<= net` lookups
        // on each click. Without it the BotDetector would scan ~50k rows per
        // click.
        $this->execute(<<<'SQL'
            CREATE INDEX idx_bot_cidrs_net ON core.bot_cidrs USING gist (net inet_ops)
        SQL);

        // ── cron_runs ──────────────────────────────────────────────────
        // Populated automatically by Shared\Cron\RunJournal (Symfony Console
        // event subscriber). One row per bin/console invocation.
        $this->execute(<<<'SQL'
            CREATE TABLE core.cron_runs (
                id           bigserial PRIMARY KEY,
                name         text NOT NULL,
                started_at   timestamptz NOT NULL DEFAULT now(),
                finished_at  timestamptz,
                duration_ms  integer,
                exit_code    integer,
                output_tail  text
            )
        SQL);

        $this->execute('CREATE INDEX idx_cron_runs_name_time ON core.cron_runs (name, started_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.cron_runs');
        $this->execute('DROP TABLE IF EXISTS core.bot_cidrs');
        $this->execute('DROP TABLE IF EXISTS core.bot_asns');
    }
}
