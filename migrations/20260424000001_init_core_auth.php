<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitCoreAuth extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE SCHEMA IF NOT EXISTS core");
        $this->execute("CREATE EXTENSION IF NOT EXISTS citext");
        $this->execute("CREATE EXTENSION IF NOT EXISTS pgcrypto");

        $this->execute(<<<'SQL'
            CREATE TABLE core.admins (
                id             bigserial PRIMARY KEY,
                login          text        NOT NULL UNIQUE,
                password_hash  text        NOT NULL,
                ui_lang        text        NOT NULL DEFAULT 'ru' CHECK (ui_lang IN ('ru','en')),
                must_change_password boolean NOT NULL DEFAULT false,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE core.sessions (
                id          text        PRIMARY KEY,
                admin_id    bigint      REFERENCES core.admins(id) ON DELETE CASCADE,
                data        bytea       NOT NULL,
                ip          inet,
                user_agent  text,
                expires_at  timestamptz NOT NULL,
                updated_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_sessions_expires ON core.sessions (expires_at)");
        $this->execute("CREATE INDEX idx_sessions_admin ON core.sessions (admin_id)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.rate_limits (
                key         text        NOT NULL,
                window_end  timestamptz NOT NULL,
                count       int         NOT NULL DEFAULT 1,
                PRIMARY KEY (key, window_end)
            )
        SQL);
        $this->execute("CREATE INDEX idx_rate_limits_window ON core.rate_limits (window_end)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.auth_events (
                id          bigserial PRIMARY KEY,
                event_type  text NOT NULL CHECK (event_type IN ('login_success','login_fail','password_change','logout','rate_limited')),
                admin_login text,
                ip          inet,
                user_agent  text,
                details     jsonb,
                created_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_auth_events_created ON core.auth_events (created_at DESC)");
        $this->execute("CREATE INDEX idx_auth_events_login ON core.auth_events (admin_login)");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS core.auth_events");
        $this->execute("DROP TABLE IF EXISTS core.rate_limits");
        $this->execute("DROP TABLE IF EXISTS core.sessions");
        $this->execute("DROP TABLE IF EXISTS core.admins");
    }
}
