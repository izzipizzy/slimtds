<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitCoreSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.settings (
                key        text PRIMARY KEY,
                value      text NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute(<<<'SQL'
            INSERT INTO core.settings (key, value) VALUES
                ('retention_clicks_days',       '365'),
                ('retention_pixel_events_days', '90'),
                ('retention_fingerprints_days', '30'),
                ('retention_auth_events_days',  '180')
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.settings');
    }
}
