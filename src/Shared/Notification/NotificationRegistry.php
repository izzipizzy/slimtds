<?php

declare(strict_types=1);

namespace App\Shared\Notification;

/**
 * Catalog of operator-facing Telegram notifications: their default message
 * templates, the macros each one exposes, and a renderer. Pure — no I/O, no
 * dependencies — so it can be used from any layer (Engine, Cron, Postback) and
 * from the Admin settings UI alike.
 *
 * Templates are admin-trusted and may contain Telegram HTML (<b>, <code>) for
 * parse_mode=HTML. Macro *values* are external data and are HTML-escaped on
 * substitution so they can never break the parse mode.
 */
final class NotificationRegistry
{
    public const AI_CLICK   = 'ai_click';
    public const AI_PIXEL   = 'ai_pixel';
    public const CONV_CLICK = 'conv_click';
    public const CONV_PING  = 'conv_ping';

    /**
     * @return array<string, array{label:string, has_sources:bool, default:string, macros:array<string,string>}>
     */
    public function definitions(): array
    {
        return [
            self::AI_CLICK => [
                'label'       => 'AI click — visitor who arrived via an AI/search source then clicked',
                'has_sources' => true,
                'default'     => "{emoji} <b>AI click</b> · {source} → {campaign}\n🌍 {route}\n📄 {offer_url}\n{app_url}/admin/clicks?click_id={click_id}",
                'macros'      => [
                    'emoji'     => 'Source emoji (🤖 AI / 🔍 search)',
                    'source'    => 'Engine key (e.g. google, chatgpt)',
                    'campaign'  => 'Campaign slug',
                    'route'     => 'lander → button · COUNTRY · device (or "direct")',
                    'offer_url' => 'Destination offer URL (truncated)',
                    'country'   => 'ISO-2 country code',
                    'device'    => 'Device type',
                    'click_id'  => 'Click UUID',
                    'app_url'   => 'Admin base URL',
                ],
            ],
            self::AI_PIXEL => [
                'label'       => 'AI pixel — pageview whose referer is an AI/search source',
                'has_sources' => true,
                'default'     => "{emoji} <b>AI pixel</b> · {source} · {event}\n📄 {page_url}\n🌍 {route}\n👤 vu <code>{visitor_uuid}</code>\n{app_url}/admin/pixel?domain={domain}",
                'macros'      => [
                    'emoji'        => 'Source emoji (🤖 AI / 🔍 search)',
                    'source'       => 'Engine key (e.g. google, chatgpt)',
                    'event'        => 'Pixel event name (e.g. pageview)',
                    'page_url'     => 'Page URL (truncated)',
                    'route'        => 'COUNTRY · device (or "unknown")',
                    'visitor_uuid' => 'Visitor UUID (first 8 chars)',
                    'fp_js'        => 'FingerprintJS id (first 10 chars)',
                    'domain'       => 'Page host (url-encoded, for the admin link)',
                    'country'      => 'ISO-2 country code',
                    'device'       => 'Device type',
                    'app_url'      => 'Admin base URL',
                ],
            ],
            self::CONV_CLICK => [
                'label'       => 'Conversion — postback attributed to a click',
                'has_sources' => false,
                'default'     => "💵 <b>{offer_name}</b> · {status} · \${payout}\n🌍 {route}\n{source}\n👤 player {player_id} · {age} после клика\n{app_url}/admin/clicks?click_id={click_id}",
                'macros'      => [
                    'offer_name' => 'Offer name',
                    'status'     => 'Conversion status',
                    'payout'     => 'Payout amount',
                    'route'      => 'lander → button · COUNTRY · device',
                    'source'     => 'Entry source (engine key or host or "direct")',
                    'player_id'  => 'External/player id (may be empty)',
                    'age'        => 'Time since click (e.g. 5m, 2h)',
                    'country'    => 'ISO-2 country code',
                    'device'     => 'Device type',
                    'click_id'   => 'Click UUID',
                    'app_url'    => 'Admin base URL',
                ],
            ],
            self::CONV_PING => [
                'label'       => 'Campaign ping — campaign-token postback without a click id',
                'has_sources' => false,
                'default'     => "📡 <b>{campaign}</b> ({slug}) · ping · {status}\n👤 player {player_id} · ip {ip}\n⚠ нет click_id — клик не привязан",
                'macros'      => [
                    'campaign'  => 'Campaign name',
                    'slug'      => 'Campaign slug',
                    'status'    => 'Conversion status',
                    'player_id' => 'External/player id (may be empty)',
                    'ip'        => 'Source IP',
                    'payout'    => 'Payout amount',
                    'app_url'   => 'Admin base URL',
                ],
            ],
        ];
    }

    public function defaultTemplate(string $key): string
    {
        return $this->definitions()[$key]['default'] ?? '';
    }

    /**
     * Render a notification. Empty/whitespace override → the registry default.
     * Every value is HTML-escaped; the template itself is left as-is.
     *
     * @param array<string, string|int|float|null> $values
     */
    public function render(string $key, string $template, array $values): string
    {
        $tpl = trim($template) !== '' ? $template : $this->defaultTemplate($key);
        $map = [];
        foreach ($values as $name => $v) {
            $map['{' . $name . '}'] = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return strtr($tpl, $map);
    }
}
