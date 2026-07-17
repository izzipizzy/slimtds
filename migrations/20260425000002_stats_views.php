<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class StatsViews extends AbstractMigration
{
    public function up(): void
    {
        // Hourly clicks aggregate — heavy to compute on-the-fly across 365 days.
        $this->execute(<<<'SQL'
            CREATE MATERIALIZED VIEW stats.clicks_hourly AS
            SELECT
                campaign_id,
                date_trunc('hour', created_at) AS hour,
                count(*)                                          AS clicks,
                count(*) FILTER (WHERE NOT is_bot)                AS clicks_human,
                count(*) FILTER (WHERE is_uniq AND NOT is_bot)    AS clicks_uniq,
                count(*) FILTER (WHERE is_bot)                    AS clicks_bot
            FROM stats.clicks
            WHERE created_at >= now() - interval '90 days'
            GROUP BY 1, 2
        SQL);
        $this->execute("CREATE UNIQUE INDEX clicks_hourly_pk ON stats.clicks_hourly (campaign_id, hour)");
        $this->execute("CREATE INDEX clicks_hourly_hour_idx ON stats.clicks_hourly (hour DESC)");

        // Conversions hourly (small enough — keep as live view, not materialized)
        $this->execute(<<<'SQL'
            CREATE VIEW core.conversions_hourly AS
            SELECT
                campaign_id, offer_id,
                date_trunc('hour', created_at) AS hour,
                count(*)                                                 AS conv,
                count(*) FILTER (WHERE status = 'approved')              AS conv_approved,
                COALESCE(sum(payout) FILTER (WHERE status = 'approved'), 0) AS payout
            FROM core.conversions
            GROUP BY 1, 2, 3
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS core.conversions_hourly');
        $this->execute('DROP MATERIALIZED VIEW IF EXISTS stats.clicks_hourly');
    }
}
