<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Hardware security keys (YubiKey / any FIDO2 authenticator) per admin.
 * An admin with at least one row here can only sign in with a key —
 * password login is refused for them (see LoginController).
 */
final class AdminWebauthnCredentials extends AbstractMigration
{
    private const PREV = "'login_success','login_fail','password_change','logout','rate_limited','mcp_key_generated','mcp_key_revoked'";

    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.admin_webauthn_credentials (
                id             bigserial   PRIMARY KEY,
                admin_id       bigint      NOT NULL REFERENCES core.admins(id) ON DELETE CASCADE,
                credential_id  text        NOT NULL UNIQUE,
                public_key     text        NOT NULL,
                sign_count     bigint      NOT NULL DEFAULT 0,
                name           text        NOT NULL,
                aaguid         text,
                created_at     timestamptz NOT NULL DEFAULT now(),
                last_used_at   timestamptz
            )
        SQL);
        $this->execute('CREATE INDEX idx_admin_webauthn_admin ON core.admin_webauthn_credentials (admin_id)');

        $this->execute('ALTER TABLE core.auth_events DROP CONSTRAINT IF EXISTS auth_events_event_type_check');
        $this->execute(
            'ALTER TABLE core.auth_events ADD CONSTRAINT auth_events_event_type_check CHECK (event_type IN ('
            . self::PREV . ",'webauthn_key_added','webauthn_key_removed'))"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.auth_events WHERE event_type IN ('webauthn_key_added','webauthn_key_removed')");
        $this->execute('ALTER TABLE core.auth_events DROP CONSTRAINT IF EXISTS auth_events_event_type_check');
        $this->execute(
            'ALTER TABLE core.auth_events ADD CONSTRAINT auth_events_event_type_check CHECK (event_type IN (' . self::PREV . '))'
        );
        $this->execute('DROP TABLE IF EXISTS core.admin_webauthn_credentials');
    }
}
