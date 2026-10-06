<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Mcp\ReportFilters;

final class GetCampaignTool implements ToolInterface
{
    public function __construct(
        private readonly ReportFilters $filters,
        private readonly CampaignRepository $campaigns,
        private readonly FlowRepository $flows,
        private readonly OfferRepository $offers,
    ) {}

    public function name(): string
    {
        return 'get_campaign';
    }

    public function description(): string
    {
        return 'One campaign with its flows in matching order: each flow\'s filters (AND inside a group, OR across '
            . 'groups), target offers with weights, and what happens to clicks no flow matches (trash mode).';
    }

    public function inputSchema(): array
    {
        return Schema::object(['campaign' => ['type' => 'string', 'description' => 'Slug, alias or UUID.']], ['campaign']);
    }

    public function call(array $args): array
    {
        if (!is_string($args['campaign'] ?? null) || $args['campaign'] === '') {
            throw new ToolError('"campaign" is required.');
        }
        $c = $this->campaigns->findById($this->filters->campaignId($args['campaign']));
        if ($c === null) {
            throw new ToolError('The campaign disappeared. Call list_campaigns again.');
        }
        $names = [];
        $flows = [];
        foreach ($this->flows->forCampaign($c->id) as $fl) {
            $targets = [];
            foreach ($fl->targetOffers as $t) {
                $oid = (string)$t['offer_id'];
                if (!isset($names[$oid])) {
                    $offer = $this->offers->findById($oid);
                    $names[$oid] = $offer === null ? '(deleted offer)' : $offer->name;
                }
                $targets[] = ['offer' => $names[$oid], 'weight' => (int)$t['weight']];
            }
            $flows[] = [
                'name'      => $fl->name,
                'position'  => $fl->position,
                'is_active' => $fl->isActive,
                'weight'    => $fl->weight,
                'filters'   => $fl->filters,
                'offers'    => $targets,
            ];
        }
        return [
            'id'              => $c->id,
            'slug'            => $c->slug,
            'name'            => $c->name,
            'notes'           => $c->notes,
            'is_active'       => $c->isActive,
            'trash_mode'      => [
                0 => 'blank page (200)',
                1 => '302 redirect to trash url',
                2 => '403',
                3 => '404',
                4 => '301 redirect to trash url',
                5 => '307 redirect to trash url',
                6 => 'meta refresh to trash url',
                7 => 'js redirect to trash url',
            ][$c->trashMode] ?? (string)$c->trashMode,
            'sticky_offer'    => $c->stickyOffer,
            'flows'           => $flows,
        ];
    }
}
