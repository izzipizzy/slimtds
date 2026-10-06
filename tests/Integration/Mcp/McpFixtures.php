<?php

declare(strict_types=1);

use App\Shared\Db\Connection;

const MCP_CAMPAIGN = '00000000-0000-7000-8000-00000000a001';
const MCP_OFFER_A  = '00000000-0000-7000-8000-00000000a002';
const MCP_OFFER_B  = '00000000-0000-7000-8000-00000000a003';
const MCP_VISITOR  = '00000000-0000-7000-8000-00000000a004';
const MCP_FLOW     = '00000000-0000-7000-8000-00000000a005';

/**
 * Six clicks today in campaign `mcptst`:
 *   k1,k2  routed, visitor MCP_VISITOR, AR, mobile, google referer, lander-a, offer A, fp_js 'fp-mcp-1' — k1 approved 10.50
 *   k3     routed, CL, desktop, lander-a, offer B — pending 7.00
 *   k4     routed, DE, desktop, is_uniq=false, offer A
 *   k5     routed BOT (bot_name Googlebot), US
 *   k6     trash (no flow), AR
 * plus two pixel pageviews for MCP_VISITOR on https://lander-a.test/ and one for a stranger.
 *
 * @return array<string,string> click ids by key
 */
function mcpSeedTraffic(Connection $db): array
{
    foreach (['core.conversions', 'stats.clicks', 'stats.pixel_events'] as $t) {
        $db->execute("DELETE FROM {$t}");
    }
    $db->execute("DELETE FROM core.campaigns WHERE id = :id OR slug = 'mcptst'", ['id' => MCP_CAMPAIGN]);
    $db->execute("INSERT INTO core.campaigns (id, slug, name, is_active) VALUES (:id, 'mcptst', 'MCP test', true)", ['id' => MCP_CAMPAIGN]);
    foreach ([MCP_OFFER_A => 'Offer A', MCP_OFFER_B => 'Offer B'] as $id => $name) {
        $db->execute(
            "INSERT INTO core.offers (id, name, url, is_active) VALUES (:id, :n, 'https://offer.test/', true) ON CONFLICT (id) DO NOTHING",
            ['id' => $id, 'n' => $name],
        );
    }
    $rows = [
        // key, visitor, country, device, referer, lander, offer, flow, bot, bot_name, uniq, fp_js
        ['k1', MCP_VISITOR, 'ar', 'mobile',  'https://www.google.com/search?q=x', 'lander-a.test', MCP_OFFER_A, MCP_FLOW, false, null, true,  'fp-mcp-1'],
        ['k2', MCP_VISITOR, 'ar', 'mobile',  'https://www.google.com/search?q=x', 'lander-a.test', MCP_OFFER_A, MCP_FLOW, false, null, false, 'fp-mcp-1'],
        ['k3', null,        'cl', 'desktop', null,                                'lander-a.test', MCP_OFFER_B, MCP_FLOW, false, null, true,  null],
        ['k4', null,        'de', 'desktop', null,                                null,            MCP_OFFER_A, MCP_FLOW, false, null, false, null],
        ['k5', null,        'us', 'desktop', null,                                null,            MCP_OFFER_A, MCP_FLOW, true,  'Googlebot', true, null],
        ['k6', null,        'ar', 'mobile',  null,                                null,            null,        null,     false, null, true,  null],
    ];
    $ids = [];
    foreach ($rows as [$k, $v, $cc, $dev, $ref, $lander, $offer, $flow, $bot, $botName, $uniq, $fpJs]) {
        $ids[$k] = (string)$db->fetchScalar(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, country, device, referer, lander_host, offer_id, flow_id,
                                       is_bot, bot_name, is_uniq, fp_js, user_agent, created_at)
             VALUES (:c, COALESCE(:v::uuid, uuidv7()), '203.0.113.77', :cc, :dev, :ref, :lander, :offer::uuid, :flow::uuid,
                     :bot, :bot_name, :uniq, :fp_js, 'Mozilla/5.0 pest', now())
             RETURNING id",
            ['c' => MCP_CAMPAIGN, 'v' => $v, 'cc' => $cc, 'dev' => $dev, 'ref' => $ref, 'lander' => $lander, 'offer' => $offer,
             'flow' => $flow, 'bot' => $bot ? 'true' : 'false', 'bot_name' => $botName, 'uniq' => $uniq ? 'true' : 'false', 'fp_js' => $fpJs],
        );
    }
    foreach ([['k1', MCP_OFFER_A, 'approved', '10.50'], ['k3', MCP_OFFER_B, 'pending', '7.00']] as [$k, $o, $s, $p]) {
        $db->execute(
            'INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status) VALUES (:k, :c, :o, :p, :s)',
            ['k' => $ids[$k], 'c' => MCP_CAMPAIGN, 'o' => $o, 'p' => $p, 's' => $s],
        );
    }
    foreach ([[MCP_VISITOR, 'pageview'], [MCP_VISITOR, 'pageview'], [null, 'pageview']] as [$v, $ev]) {
        $db->execute(
            "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, event_name, page_url, ip, country, created_at)
             VALUES (:c, COALESCE(:v::uuid, uuidv7()), :ev, 'https://lander-a.test/', '203.0.113.77', 'ar', now() - interval '1 minute')",
            ['c' => MCP_CAMPAIGN, 'v' => $v, 'ev' => $ev],
        );
    }
    return $ids;
}
