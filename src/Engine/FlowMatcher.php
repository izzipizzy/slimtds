<?php

declare(strict_types=1);

namespace App\Engine;

use App\Admin\Repository\Flow;
use App\Admin\Repository\FlowRepository;

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
