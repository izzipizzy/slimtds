<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\CampaignForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CampaignController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly CampaignRepository $repo,
        private readonly CampaignForm $form,
        private readonly OfferRepository $offers,
        private readonly FlowRepository $flows,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $q = $this->query($request, 'q');
        $page = max(1, (int)($this->query($request, 'page') ?? '1'));

        $items = $this->repo->page($page, self::PER_PAGE, $q);
        $total = $this->repo->count($q);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));

        $ids = array_map(fn ($c) => $c->id, $items);
        $offerCounts = $this->offers->countsByCampaign($ids);
        $flowCounts = $this->flows->countsByCampaign($ids);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('campaigns.title'),
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'q' => $q ?? '',
                'offer_counts' => $offerCounts,
                'flow_counts' => $flowCounts,
                'app_url' => rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/'),
            ],
        );
        return $view->respond($response, 'admin/campaigns/index', $data);
    }

    public function new_(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'      => $view->i18n->t('campaigns.create'),
                '__layout__' => 'layouts/admin',
                'campaign'   => null,
                'offers'     => [],
                'flows'      => [],
                'app_url'    => rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/'),
                'errors'     => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/campaigns/form', $data);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $errors = $this->form->validate($data, null);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', '/admin/campaigns/new')->withStatus(302);
        }

        $c = $this->repo->create($data);
        flash_push('success', "Campaign {$c->slug} created");
        return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $c = $this->repo->findById($id);
        if ($c === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }
        $offers = $this->offers->forCampaign($id);
        $flows  = $this->flows->forCampaign($id);
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'      => $view->i18n->t('campaigns.edit'),
                '__layout__' => 'layouts/admin',
                'campaign'   => $c,
                'offers'     => $offers,
                'flows'      => $flows,
                'app_url'    => $appUrl,
                'errors'     => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/campaigns/form', $data);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $c = $this->repo->findById($id);
        if ($c === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $errors = $this->form->validate($data, $id);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', "/admin/campaigns/{$id}/edit")->withStatus(302);
        }

        $this->repo->update($id, $data);
        flash_push('success', "Campaign updated");
        return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
    }

    public function deleteConfirm(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $c = $this->repo->findById($id);
        if ($c === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('campaigns.delete'),
                '__layout__' => 'layouts/admin',
                'campaign' => $c,
            ],
        );
        return $view->respond($response, 'admin/campaigns/delete', $data);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->repo->delete($id);
        flash_push('success', "Campaign deleted");
        return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
    }

    public function stats(
        ServerRequestInterface $request,
        ResponseInterface $response,
        View $view,
        \App\Stats\StatsRepository $stats,
        string $cid,
    ): ResponseInterface {
        $campaign = $this->repo->findById($cid);
        if ($campaign === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }
        $window = (string)($request->getQueryParams()['window'] ?? '7d');
        $since = match ($window) {
            '24h'  => date('c', time() - 86400),
            '30d'  => date('c', time() - 30 * 86400),
            default => date('c', time() - 7 * 86400),
        };
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'      => 'Stats · ' . $campaign->name,
                '__layout__' => 'layouts/admin',
                'campaign'   => $campaign,
                'timeline'   => $stats->searchClicksTimeline($cid, $since),
                'summary'    => $stats->searchSummary($cid, $since),
                'window'     => $window,
            ],
        );
        return $view->respond($response, 'admin/campaigns/stats', $data);
    }

    public function pixel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        View $view,
        \App\Pixel\PixelEventRepository $events,
        string $cid,
    ): ResponseInterface {
        $campaign = $this->repo->findById($cid);
        if ($campaign === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'      => 'Pixel · ' . $campaign->name,
                '__layout__' => 'layouts/admin',
                'campaign'   => $campaign,
                'app_url'    => $appUrl,
                'recent'     => $events->recentForCampaign($cid, 50),
                'counts'     => $events->countsForCampaign($cid),
            ],
        );
        return $view->respond($response, 'admin/campaigns/pixel', $data);
    }

    private function query(ServerRequestInterface $request, string $key): ?string
    {
        $params = $request->getQueryParams();
        return isset($params[$key]) && is_string($params[$key]) ? $params[$key] : null;
    }
}
