<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ConversionRepository;
use App\Shared\Time\DateRange;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ConversionController
{
    /** The window the repository falls back to when no period is picked. */
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly ConversionRepository $repo,
        private readonly CampaignRepository $campaigns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        // Day range from the filter bar. Unset = the repository's rolling
        // 30-day window; the 'all' preset needs the real first day.
        $range = DateRange::fromQuery($params, self::DEFAULT_DAYS, fn (): ?string => $this->repo->earliestDay());

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'status'      => $params['status']      ?? null,
            'since'       => $params['since']       ?? null,
            'from'        => $range->from,
            'to'          => $range->to,
            'range'       => $range->preset,
        ];

        $items = $this->repo->page($page, $perPage, $filters);
        $total = $this->repo->count($filters);
        $pages = max(1, (int)ceil($total / $perPage));
        $breakdown = $this->repo->statusBreakdown($filters);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => 'Conversions',
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'filters' => $range->forView($filters),
                'range_from' => $range->from ?? DateRange::daysAgo(self::DEFAULT_DAYS),
                'range_to' => $range->to ?? DateRange::today(),
                'breakdown' => $breakdown,
                'campaigns' => $this->campaigns->page(1, 100),
            ],
        );
        return $view->respond($response, 'admin/conversions/index', $data);
    }
}
