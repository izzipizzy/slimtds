<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class EngagementMode extends AbstractMigration
{
    /**
     * Pixel-side engagement (clickunder) mode, resolved per pageview by
     * /p/play and executed by the proxied pixel on the lander:
     *
     *   core.campaigns.engagement_mode
     *     overlay  — invisible full-page layer; the visitor's first click
     *                opens /play/overlay in a new tab (lander stays open);
     *     redirect — the first pointer event forces location.replace to
     *                /play/redirect (same tab, lander leaves the history);
     *     random   — the server flips a coin per request, answering overlay
     *                or redirect, so consecutive visits differ;
     *     off      — engagement disabled (default).
     *
     *   core.flows.engagement_mode (NULL = inherit the campaign default)
     *     'off'    — SUPPRESSION: a visitor matching ANY flow marked 'off'
     *                gets no clickunder, even when an earlier flow enables
     *                one (exclusion flows must not be shadowed by broad
     *                fallback flows that match first);
     *     mode     — the first matching flow carrying a mode overrides the
     *                campaign default for its traffic.
     */
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE core.campaigns
             ADD COLUMN IF NOT EXISTS engagement_mode text NOT NULL DEFAULT 'off'"
        );
        $this->execute(
            'ALTER TABLE core.campaigns
             ADD CONSTRAINT ck_campaigns_engagement_mode
             CHECK (engagement_mode IN (\'off\', \'overlay\', \'redirect\', \'random\'))'
        );

        $this->execute(
            'ALTER TABLE core.flows
             ADD COLUMN IF NOT EXISTS engagement_mode text'
        );
        $this->execute(
            'ALTER TABLE core.flows
             ADD CONSTRAINT ck_flows_engagement_mode
             CHECK (engagement_mode IS NULL
                    OR engagement_mode IN (\'off\', \'overlay\', \'redirect\', \'random\'))'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.flows DROP CONSTRAINT IF EXISTS ck_flows_engagement_mode');
        $this->execute('ALTER TABLE core.flows DROP COLUMN IF EXISTS engagement_mode');
        $this->execute('ALTER TABLE core.campaigns DROP CONSTRAINT IF EXISTS ck_campaigns_engagement_mode');
        $this->execute('ALTER TABLE core.campaigns DROP COLUMN IF EXISTS engagement_mode');
    }
}
