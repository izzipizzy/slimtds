<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ConversionRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ConversionController
{
    public function __construct(
        private readonly ConversionRepository $repo,
        private readonly CampaignRepository $campaigns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'status'      => $params['status']      ?? null,
            'since'       => $params['since']       ?? null,
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
                'filters' => $filters,
                'breakdown' => $breakdown,
                'campaigns' => $this->campaigns->page(1, 100),
            ],
        );
        return $view->respond($response, 'admin/conversions/index', $data);
    }
}
