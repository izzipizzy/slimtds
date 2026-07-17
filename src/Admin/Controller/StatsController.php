<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CampaignRepository;
use App\Shared\View\View;
use App\Stats\StatsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StatsController
{
    public function __construct(
        private readonly StatsRepository $stats,
        private readonly CampaignRepository $campaigns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $campaignId = !empty($params['campaign_id']) && is_string($params['campaign_id']) ? $params['campaign_id'] : null;
        $window = (string)($params['window'] ?? '7d');
        $since = match ($window) {
            '24h'  => date('c', time() - 86400),
            '30d'  => date('c', time() - 30 * 86400),
            default => date('c', time() - 7 * 86400),
        };

        $timeline = $this->stats->clicksTimeline($campaignId, $since);
        $summary  = $this->stats->summary($campaignId, $since);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'       => 'Statistics',
                '__layout__'  => 'layouts/admin',
                'timeline'    => $timeline,
                'summary'     => $summary,
                'window'      => $window,
                'campaign_id' => $campaignId,
                'campaigns'   => $this->campaigns->page(1, 100),
            ],
        );
        return $view->respond($response, 'admin/statistics/index', $data);
    }
}
