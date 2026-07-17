<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\FlowForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FlowController
{
    public function __construct(
        private readonly FlowRepository $repo,
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
        private readonly FlowForm $form,
    ) {}

    public function all(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();
        $q = isset($params['q']) && is_string($params['q']) ? $params['q'] : null;
        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 25;

        $items = $this->repo->pageAll($page, $perPage, $q);
        $total = $this->repo->countAll($q);
        $pages = max(1, (int)ceil($total / $perPage));
        $campaignList = $this->campaigns->page(1, 200, null);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('flows.title'),
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'q' => $q ?? '',
                'campaigns' => $campaignList,
            ],
        );
        return $view->respond($response, 'admin/flows/all', $data);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view, string $cid): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);

        $items = $this->repo->forCampaign($cid);
        $offers = $this->offers->forCampaign($cid);
        $offerMap = [];
        foreach ($offers as $o) { $offerMap[$o->id] = $o; }

        $data = array_merge($view->withRequestContext($request), [
            'title' => $view->i18n->t('flows.title'),
            '__layout__' => 'layouts/admin',
            'campaign' => $campaign,
            'items' => $items,
            'offer_map' => $offerMap,
        ]);
        return $view->respond($response, 'admin/flows/index', $data);
    }

    public function new_(ServerRequestInterface $request, ResponseInterface $response, View $view, string $cid): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);

        return $this->renderForm($request, $response, $view, $campaign, null);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $cid): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows/new")->withStatus(302);
        }

        $flow = $this->repo->create($cid, $data);
        flash_push('success', "Flow {$flow->name} created");
        return $response->withHeader('Location', "/admin/campaigns/{$cid}/edit#flows")->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, View $view, string $cid, string $id): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);

        $flow = $this->repo->findById($id);
        if ($flow === null || $flow->campaignId !== $cid) {
            return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows")->withStatus(302);
        }
        return $this->renderForm($request, $response, $view, $campaign, $flow);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $cid, string $id): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);

        $flow = $this->repo->findById($id);
        if ($flow === null || $flow->campaignId !== $cid) {
            return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows")->withStatus(302);
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows/{$id}/edit")->withStatus(302);
        }

        $this->repo->update($id, $data);
        flash_push('success', 'Flow updated');
        return $response->withHeader('Location', "/admin/campaigns/{$cid}/edit#flows")->withStatus(302);
    }

    public function deleteConfirm(ServerRequestInterface $request, ResponseInterface $response, View $view, string $cid, string $id): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        $flow = $this->repo->findById($id);
        if ($campaign === null || $flow === null || $flow->campaignId !== $cid) {
            return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows")->withStatus(302);
        }
        $data = array_merge($view->withRequestContext($request), [
            'title' => $view->i18n->t('flows.delete'),
            '__layout__' => 'layouts/admin',
            'campaign' => $campaign,
            'flow' => $flow,
        ]);
        return $view->respond($response, 'admin/flows/delete', $data);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $cid, string $id): ResponseInterface
    {
        $this->repo->delete($id);
        flash_push('success', 'Flow deleted');
        return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows")->withStatus(302);
    }

    /**
     * Swap a flow's position with its neighbour. ?dir=up moves it before the
     * previous active sibling; ?dir=down moves it after the next sibling.
     * Matters because FlowMatcher returns the first matching flow — earlier
     * positions take priority (e.g. bot trap should be above country filters).
     */
    public function move(ServerRequestInterface $request, ResponseInterface $response, string $cid, string $id): ResponseInterface
    {
        $params = $request->getQueryParams() + (is_array($request->getParsedBody()) ? $request->getParsedBody() : []);
        $dir = ($params['dir'] ?? 'down') === 'up' ? 'up' : 'down';

        $flows = $this->repo->forCampaign($cid);
        $idx = -1;
        foreach ($flows as $i => $f) {
            if ($f->id === $id) { $idx = $i; break; }
        }
        if ($idx >= 0) {
            $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
            if ($swap >= 0 && $swap < count($flows)) {
                $reordered = $flows;
                [$reordered[$idx], $reordered[$swap]] = [$reordered[$swap], $reordered[$idx]];
                $this->repo->reorder($cid, array_map(static fn ($f) => $f->id, $reordered));
            }
        }
        return $response->withHeader('Location', "/admin/campaigns/{$cid}/flows")->withStatus(302);
    }

    private function renderForm(ServerRequestInterface $request, ResponseInterface $response, View $view, \App\Admin\Repository\Campaign $campaign, ?\App\Admin\Repository\Flow $flow): ResponseInterface
    {
        $offers = $this->offers->all();
        $data = array_merge($view->withRequestContext($request), [
            'title' => $view->i18n->t($flow === null ? 'flows.create' : 'flows.edit'),
            '__layout__' => 'layouts/admin',
            'campaign' => $campaign,
            'flow' => $flow,
            'offers' => $offers,
            'errors' => $_SESSION['_errors'] ?? [],
        ]);
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/flows/form', $data);
    }
}
