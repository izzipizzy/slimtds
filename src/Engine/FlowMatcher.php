<?php

declare(strict_types=1);

namespace App\Engine;

use App\Admin\Repository\Flow;
use App\Admin\Repository\FlowRepository;
use App\Shared\TestLink;

final class FlowMatcher
{
    /** @var array<string, array{ts:int, flows: list<Flow>}> */
    private array $cache = [];
    private const CACHE_TTL = 60; // seconds — cheap invalidation

    public function __construct(
        private readonly FlowRepository $repo,
        private readonly FilterCompiler $compiler,
    ) {}

    public function match(string $campaignId, Context $ctx): ?Flow
    {
        $flows = $this->cachedFlows($campaignId);
        foreach ($flows as $flow) {
            if (!$flow->isActive) continue;
            $predicate = $this->compiler->compile($flow->filters);
            if ($predicate($ctx)) {
                $ctx->matchedFlowId = $flow->id;
                return $flow;
            }
        }
        return null;
    }


    /**
     * The flow a test link points at, active or not, filters ignored. A
     * campaign holds a handful of flows, so recomputing each key is cheaper
     * than any index — and it only runs when a request carries `_t` at all.
     */
    public function forTestLink(string $campaignId, TestLink $link): ?Flow
    {
        foreach ($this->cachedFlows($campaignId) as $flow) {
            if ($link->isFlow($flow->id)) {
                return $flow;
            }
        }
        return null;
    }

    /** @return list<Flow> */
    private function cachedFlows(string $campaignId): array
    {
        $now = time();
        $entry = $this->cache[$campaignId] ?? null;
        if ($entry !== null && $now - $entry['ts'] < self::CACHE_TTL) {
            return $entry['flows'];
        }
        $flows = $this->repo->forCampaign($campaignId);
        $this->cache[$campaignId] = ['ts' => $now, 'flows' => $flows];
        return $flows;
    }

    public function invalidate(string $campaignId): void
    {
        unset($this->cache[$campaignId]);
    }
}
