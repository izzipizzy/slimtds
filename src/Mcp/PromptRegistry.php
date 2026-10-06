<?php

declare(strict_types=1);

namespace App\Mcp;

final class PromptRegistry
{
    private const PROMPTS = [
        'traffic_review' => [
            'description' => 'Review one campaign: volume, quality, revenue, and what to change.',
            'arguments'   => [
                ['name' => 'campaign', 'description' => 'Campaign slug or id', 'required' => true],
                ['name' => 'period', 'description' => 'today, yesterday, 7d, 30d or 90d', 'required' => false],
            ],
            'text' => 'Review the slimTDS campaign "{campaign}" over {period}. Call get_campaign to see its flows, then traffic_summary, traffic_timeline, and traffic_breakdown by country, device, referer_domain, lander and offer. Report: what the traffic is, where it converts and where it does not, how much is bots or trash, and three concrete changes to flows or offers, each backed by a number.',
        ],
        'bot_audit' => [
            'description' => 'Find bot and junk traffic and where it comes from.',
            'arguments'   => [
                ['name' => 'period', 'description' => 'today, yesterday, 7d, 30d or 90d', 'required' => false],
            ],
            'text' => 'Audit bot and junk traffic in slimTDS over {period}. Compare traffic_summary with bots=only and bots=exclude, then traffic_breakdown with bots=only by bot_name, asn, country and referer_domain. Then look for unflagged junk among bots=exclude: ASNs or referers with many clicks, near-zero uniques ratio and no conversions. List what to block and why.',
        ],
        'funnel_leaks' => [
            'description' => 'Find where visitors drop between lander, click and conversion.',
            'arguments'   => [
                ['name' => 'campaign', 'description' => 'Campaign slug or id', 'required' => true],
                ['name' => 'period', 'description' => 'today, yesterday, 7d, 30d or 90d', 'required' => false],
            ],
            'text' => 'Find the funnel leaks of the slimTDS campaign "{campaign}" over {period}. Use pixel_summary for lander visitors and the share that clicked, traffic_breakdown by lander and by offer for click-to-conversion, and conversions_summary for statuses. Name the weakest step, the landers and offers responsible, and check two or three visitor_journey samples before concluding.',
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function describe(): array
    {
        $out = [];
        foreach (self::PROMPTS as $name => $p) {
            $out[] = ['name' => $name, 'description' => $p['description'], 'arguments' => $p['arguments']];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function get(string $name, array $args): ?array
    {
        $p = self::PROMPTS[$name] ?? null;
        if ($p === null) {
            return null;
        }
        $text = strtr($p['text'], [
            '{campaign}' => is_string($args['campaign'] ?? null) ? $args['campaign'] : '(ask the user which campaign)',
            '{period}'   => is_string($args['period'] ?? null) ? $args['period'] : '7d',
        ]);
        return [
            'description' => $p['description'],
            'messages'    => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }
}
