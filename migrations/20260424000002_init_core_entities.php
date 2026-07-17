<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitCoreEntities extends AbstractMigration
{
    public function up(): void
    {
        // UUIDv7 — в PG18 есть нативный uuidv7(); если нет — пробуем pg_uuidv7, иначе полифил на PL/pgSQL
        $this->execute(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname='uuidv7') THEN
                    BEGIN
                        CREATE EXTENSION IF NOT EXISTS pg_uuidv7;
                    EXCEPTION WHEN OTHERS THEN
                        CREATE OR REPLACE FUNCTION uuidv7() RETURNS uuid AS $f$
                        DECLARE
                            ts  bytea;
                            rnd bytea;
                        BEGIN
                            ts  := substring(int8send((extract(epoch from clock_timestamp()) * 1000)::bigint) from 3 for 6);
                            rnd := gen_random_bytes(10);
                            -- version=7 в старших 4 битах байта 6, variant=10xxxxxx в старших 2 битах байта 8
                            rnd := set_byte(rnd, 0, (get_byte(rnd, 0) & 15) | 112);
                            rnd := set_byte(rnd, 2, (get_byte(rnd, 2) & 63) | 128);
                            RETURN encode(ts || rnd, 'hex')::uuid;
                        END;
                        $f$ LANGUAGE plpgsql VOLATILE;
                    END;
                END IF;
            END $$
        SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE core.campaigns (
                id               uuid        PRIMARY KEY DEFAULT uuidv7(),
                slug             citext      NOT NULL UNIQUE,
                name             text        NOT NULL,
                notes            text,
                is_active        boolean     NOT NULL DEFAULT true,
                default_schema   smallint,
                trash_mode       smallint    NOT NULL DEFAULT 0,
                trash_url        text,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_campaigns_active ON core.campaigns (is_active)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.offers (
                id             uuid        PRIMARY KEY DEFAULT uuidv7(),
                campaign_id    uuid        NOT NULL REFERENCES core.campaigns(id) ON DELETE CASCADE,
                name           text        NOT NULL,
                url            text        NOT NULL,
                postback_token text        NOT NULL DEFAULT encode(gen_random_bytes(16), 'hex'),
                payout_default numeric(10,2),
                currency       text        NOT NULL DEFAULT 'USD',
                is_active      boolean     NOT NULL DEFAULT true,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_offers_campaign ON core.offers (campaign_id)");
        $this->execute("CREATE UNIQUE INDEX idx_offers_postback_token ON core.offers (postback_token)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.flows (
                id              uuid        PRIMARY KEY DEFAULT uuidv7(),
                campaign_id     uuid        NOT NULL REFERENCES core.campaigns(id) ON DELETE CASCADE,
                name            text        NOT NULL,
                filters         jsonb       NOT NULL DEFAULT '[]',
                target_type     text        NOT NULL CHECK (target_type IN ('offers','none')),
                target_offers   jsonb       NOT NULL DEFAULT '[]',
                schema_id       smallint    NOT NULL,
                schema_config   jsonb       NOT NULL DEFAULT '{}',
                weight          int         NOT NULL DEFAULT 100,
                position        int         NOT NULL DEFAULT 0,
                is_active       boolean     NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_flows_campaign_position ON core.flows (campaign_id, position)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.bot_ips (
                ip          inet    NOT NULL PRIMARY KEY,
                bot_name    text    NOT NULL,
                source      text    NOT NULL DEFAULT 'myip.ms',
                updated_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_bot_ips_name ON core.bot_ips (bot_name)");

        $this->execute(<<<'SQL'
            CREATE TABLE core.conversions (
                id            uuid        PRIMARY KEY DEFAULT uuidv7(),
                click_id      uuid        NOT NULL UNIQUE,
                campaign_id   uuid        NOT NULL,
                offer_id      uuid        NOT NULL,
                payout        numeric(10,2) NOT NULL DEFAULT 0,
                currency      text        NOT NULL DEFAULT 'USD',
                status        text        NOT NULL CHECK (status IN ('approved','pending','hold','rejected')),
                external_id   text,
                source_ip     inet,
                raw_query     text,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        $this->execute("CREATE INDEX idx_conversions_campaign ON core.conversions (campaign_id, created_at DESC)");
        $this->execute("CREATE INDEX idx_conversions_offer_status ON core.conversions (offer_id, status)");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS core.conversions");
        $this->execute("DROP TABLE IF EXISTS core.bot_ips");
        $this->execute("DROP TABLE IF EXISTS core.flows");
        $this->execute("DROP TABLE IF EXISTS core.offers");
        $this->execute("DROP TABLE IF EXISTS core.campaigns");
    }
}
