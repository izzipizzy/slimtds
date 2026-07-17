<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ClicksLanderFields extends AbstractMigration
{
    public function up(): void
    {
        // Lander context forwarded by site nginx via X-Lander-Host / X-Lander-Path
        // when traffic is proxied through /play/ on the SEO sites. Used to attribute
        // a click to "which lander, which CTA button" for partner-network sub-IDs.
        // Both nullable — direct hits (without the proxy) have neither.
        $this->execute('ALTER TABLE stats.clicks ADD COLUMN lander_host   text');
        $this->execute('ALTER TABLE stats.clicks ADD COLUMN lander_button text');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE stats.clicks DROP COLUMN IF EXISTS lander_button');
        $this->execute('ALTER TABLE stats.clicks DROP COLUMN IF EXISTS lander_host');
    }
}
