<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\ClickRepository;

final class VisitorJourneyTool implements ToolInterface
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly ClickRepository $clicks) {}

    public function name(): string
    {
        return 'visitor_journey';
    }

    public function description(): string
    {
        return 'Everything one visitor did, newest first: lander pageviews, clicks and conversions. Identify the visitor '
            . 'by visitor_uuid or by fp_js (the browser fingerprint, which survives cleared cookies); both come from list_clicks.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'visitor_uuid' => ['type' => 'string'],
            'fp_js'        => ['type' => 'string'],
            'days'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'How far back, default 30.'],
            'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 80.'],
        ]);
    }

    public function call(array $args): array
    {
        $days = max(1, min(90, (int)($args['days'] ?? 30)));
        $limit = max(1, min(200, (int)($args['limit'] ?? 80)));
        if (is_string($args['visitor_uuid'] ?? null) && $args['visitor_uuid'] !== '') {
            if (preg_match(self::UUID, $args['visitor_uuid']) !== 1) {
                throw new ToolError('"visitor_uuid" must be a UUID.');
            }
            $rows = $this->clicks->visitorJourney($args['visitor_uuid'], $limit, $days);
        } elseif (is_string($args['fp_js'] ?? null) && $args['fp_js'] !== '') {
            $rows = $this->clicks->visitorJourneyByFp($args['fp_js'], $limit, $days);
        } else {
            throw new ToolError('Give either "visitor_uuid" or "fp_js".');
        }
        return ['days' => $days, 'events' => array_map(static fn (array $r): array => [
            'kind'     => (string)$r['kind'],
            'at'       => (string)$r['at'],
            'detail'   => $r['detail'],
            'referer'  => $r['ref'],
            'click_id' => $r['click_id'],
            'campaign' => $r['slug'],
            'lander'   => $r['lander_host'],
            'offer'    => $r['offer'],
            'status'   => $r['status'],
            'payout'   => $r['payout'] === null ? null : (string)$r['payout'],
        ], $rows)];
    }
}
