<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserViewPrefs extends AbstractMigration
{
    public function up(): void
    {
        // Per-operator default filter state for a list view. Deliberately NOT a
        // global default: hiding traffic by default is a per-person preference,
        // never something the product decides for everyone.
        // Column visibility stays in the session (see Clicks\ColumnPreferences) —
        // this table is only about which rows the list starts out showing.
        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS core.user_view_prefs (
                admin_id   bigint      NOT NULL REFERENCES core.admins(id) ON DELETE CASCADE,
                view       text        NOT NULL,
                prefs      jsonb       NOT NULL DEFAULT '{}'::jsonb,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (admin_id, view)
            )
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.user_view_prefs');
    }
}
