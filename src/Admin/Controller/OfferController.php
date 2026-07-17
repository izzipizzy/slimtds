<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Form\OfferForm;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OfferController
{
    public function __construct(
        private readonly OfferRepository $repo,
        private readonly CampaignRepository $campaigns,
        private readonly OfferForm $form,
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

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('offers.title'),
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'q' => $q ?? '',
            ],
        );
        return $view->respond($response, 'admin/offers/all', $data);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view, string $cid): ResponseInterface
    {
        $campaign = $this->campaigns->findById($cid);
        if ($campaign === null) {
            return $response->withHeader('Location', '/admin/campaigns')->withStatus(302);
        }
        $items = $this->repo->forCampaign($cid);
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('offers.title'),
                '__layout__' => 'layouts/admin',
                'campaign' => $campaign,
                'items' => $items,
            ],
        );
        return $view->respond($response, 'admin/offers/index', $data);
    }

    public function new_(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('offers.create'),
                '__layout__' => 'layouts/admin',
                'offer' => null,
                'errors' => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/offers/form', $data);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', '/admin/offers/new')->withStatus(302);
        }

        $data['postback_urls'] = $this->form->extractPostbackUrls($data);
        $offer = $this->repo->create($data);
        flash_push('success', "Offer {$offer->name} created");
        return $response->withHeader('Location', "/admin/offers/{$offer->id}/edit")->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $offer = $this->repo->findById($id);
        if ($offer === null) {
            return $response->withHeader('Location', '/admin/offers')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('offers.edit'),
                '__layout__' => 'layouts/admin',
                'offer' => $offer,
                'flow_refs' => $this->repo->flowReferenceCount($id),
                'errors' => $_SESSION['_errors'] ?? [],
            ],
        );
        unset($_SESSION['_errors']);
        return $view->respond($response, 'admin/offers/form', $data);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $offer = $this->repo->findById($id);
        if ($offer === null) {
            return $response->withHeader('Location', '/admin/offers')->withStatus(302);
        }

        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $errors = $this->form->validate($data);
        if (!empty($errors)) {
            $_SESSION['_old'] = $data;
            $_SESSION['_errors'] = $errors;
            return $response->withHeader('Location', "/admin/offers/{$id}/edit")->withStatus(302);
        }

        $data['postback_urls'] = $this->form->extractPostbackUrls($data);
        $this->repo->update($id, $data);
        flash_push('success', 'Offer updated');
        return $response->withHeader('Location', "/admin/offers/{$id}/edit")->withStatus(302);
    }

    public function rotateToken(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $newTok = $this->repo->rotateToken($id);
        if ($newTok !== null) {
            flash_push('success', "Postback token rotated: {$newTok}");
        }
        return $response->withHeader('Location', "/admin/offers/{$id}/edit")->withStatus(302);
    }

    public function deleteConfirm(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $offer = $this->repo->findById($id);
        if ($offer === null) {
            return $response->withHeader('Location', '/admin/offers')->withStatus(302);
        }
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('offers.delete'),
                '__layout__' => 'layouts/admin',
                'offer' => $offer,
                'flow_refs' => $this->repo->flowReferenceCount($id),
            ],
        );
        return $view->respond($response, 'admin/offers/delete', $data);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->repo->delete($id);
        flash_push('success', 'Offer deleted');
        return $response->withHeader('Location', '/admin/offers')->withStatus(302);
    }
}
