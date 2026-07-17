<?php

declare(strict_types=1);

namespace App\Engine;

use App\Shared\Db\Connection;

final class BotDetector
{
    private const ASN_PATTERNS = [
        'google'    => '/google|googlebot/i',
        'yandex'    => '/yandex/i',
        'microsoft' => '/microsoft|bing/i',
        'mail'      => '/mail\.?ru/i',
        'baidu'     => '/baidu/i',
        'yahoo'     => '/yahoo/i',
    ];

    private const UA_PATTERNS = [
        'google'    => '/Googlebot|AdsBot|Mediapartners-Google/i',
        'yandex'    => '/YandexBot|YandexImages|YandexMobileBot/i',
        'microsoft' => '/bingbot|msnbot|adidxbot/i',
        'mail'      => '/Mail\.RU_Bot|Mail\.Ru/i',
        'baidu'     => '/Baiduspider/i',
        'yahoo'     => '/Slurp|Yahoo!\sSlurp/i',
        'others'    => '/bot|crawler|spider|scraper|HeadlessChrome|HTTrack|wget|curl|python-requests/i',
    ];

    public function __construct(private readonly Connection $db) {}

    public function detect(Context $ctx): void
    {
        // Stage 1: explicit bot IP list (search engines, from myip.ms)
        $row = $this->db->fetchOne(
            'SELECT bot_name FROM core.bot_ips WHERE ip = :ip',
            ['ip' => $ctx->ip],
        );
        if ($row !== null) {
            $ctx->isBot = true;
            $ctx->botName = (string)$row['bot_name'];
            return;
        }

        // Stage 2a: CIDR-range membership in datacenter / VPN list
        // (X4BNet/lists_vpn, populated daily by bots:update-extra). Uses a GiST
        // index on net, so this is a sub-ms point lookup over ~50k ranges.
        $row = $this->db->fetchOne(
            'SELECT kind FROM core.bot_cidrs WHERE :ip::inet <<= net LIMIT 1',
            ['ip' => $ctx->ip],
        );
        if ($row !== null) {
            $ctx->isBot = true;
            $ctx->botName = (string)$row['kind']; // 'datacenter' | 'vpn'
            return;
        }

        // Stage 2b: ASN in known-hosting list (brianhama/bad-asn-list)
        if ($ctx->asn !== null) {
            $row = $this->db->fetchOne(
                'SELECT name FROM core.bot_asns WHERE asn = :asn',
                ['asn' => $ctx->asn],
            );
            if ($row !== null) {
                $ctx->isBot = true;
                $ctx->botName = 'hosting:' . substr((string)($row['name'] ?? ''), 0, 40);
                return;
            }
        }

        // Stage 2c: legacy ISP-name regex for search engines (Google/Yandex/...).
        if ($ctx->isp !== null) {
            foreach (self::ASN_PATTERNS as $name => $pattern) {
                if (preg_match($pattern, $ctx->isp)) {
                    $ctx->isBot = true;
                    $ctx->botName = $name;
                    return;
                }
            }
        }

        // Stage 3: UA-based
        foreach (self::UA_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $ctx->userAgent)) {
                $ctx->isBot = true;
                $ctx->botName = $name;
                return;
            }
        }
    }
}
