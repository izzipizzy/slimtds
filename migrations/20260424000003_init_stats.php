<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitStats extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("CREATE SCHEMA IF NOT EXISTS stats");

        $this->execute(<<<'SQL'
            CREATE TABLE stats.clicks (
                id                uuid        NOT NULL DEFAULT uuidv7(),
                campaign_id       uuid        NOT NULL,
                flow_id           uuid,
                offer_id          uuid,
                visitor_uuid      uuid        NOT NULL,
                ip                inet        NOT NULL,
                country           text,
                region            text,
                city              text,
                asn               int,
                isp               text,
                device            text,
                os                text,
                browser           text,
                lang              text,
                is_bot            boolean     NOT NULL DEFAULT false,
                bot_name          text,
                is_uniq           boolean     NOT NULL DEFAULT true,
                user_agent        text,
                referer           text,
                utm_source        text,
                utm_medium        text,
                utm_campaign      text,
                utm_term          text,
                utm_content       text,
                schema_id         smallint,
                out_url           text,
                http_status       smallint,
                created_at        timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (created_at, id)
            ) PARTITION BY RANGE (created_at)
        SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE stats.pixel_events (
                id               uuid        NOT NULL DEFAULT uuidv7(),
                campaign_id      uuid        NOT NULL,
                visitor_uuid     uuid        NOT NULL,
                fp_js            text,
                event_name       text        NOT NULL DEFAULT 'pageview',
                page_url         text,
                referer          text,
                ip               inet,
                country          text,
                city             text,
                asn              int,
                user_agent       text,
                screen_w         int,
                screen_h         int,
                timezone         text,
                lang             text,
                props            jsonb,
                created_at       timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (created_at, id)
            ) PARTITION BY RANGE (created_at)
        SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE stats.visitors_fingerprints (
                fp_hash       bytea       NOT NULL,
                visitor_uuid  uuid        NOT NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (created_at, fp_hash)
            ) PARTITION BY RANGE (created_at)
        SQL);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS stats.visitors_fingerprints CASCADE");
        $this->execute("DROP TABLE IF EXISTS stats.pixel_events CASCADE");
        $this->execute("DROP TABLE IF EXISTS stats.clicks CASCADE");
        $this->execute("DROP SCHEMA IF EXISTS stats CASCADE");
    }
}
