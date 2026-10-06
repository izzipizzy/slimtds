<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AuthEventsMcpKey extends AbstractMigration
{
    private const BASE = "'login_success','login_fail','password_change','logout','rate_limited'";

    public function up(): void
    {
        $this->execute('ALTER TABLE core.auth_events DROP CONSTRAINT IF EXISTS auth_events_event_type_check');
        $this->execute(
            'ALTER TABLE core.auth_events ADD CONSTRAINT auth_events_event_type_check CHECK (event_type IN ('
            . self::BASE . ",'mcp_key_generated','mcp_key_revoked'))"
        );
    }

    public function down(): void
    {
        // The narrower (pre-MCP) CHECK can't be re-added while any row still
        // carries an mcp_key_* event_type, so rolling back this migration
        // deletes the MCP audit rows it made possible.
        $this->execute("DELETE FROM core.auth_events WHERE event_type IN ('mcp_key_generated','mcp_key_revoked')");
        $this->execute('ALTER TABLE core.auth_events DROP CONSTRAINT IF EXISTS auth_events_event_type_check');
        $this->execute(
            'ALTER TABLE core.auth_events ADD CONSTRAINT auth_events_event_type_check CHECK (event_type IN (' . self::BASE . '))'
        );
    }
}
