<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Clicks\ColumnPreferences;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ClickController
{
    public function __construct(
        private readonly ClickRepository $repo,
        private readonly CampaignRepository $campaigns,
        private readonly ColumnPreferences $columns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();

        // Sort: ?sort=<col>&dir=asc|desc — clicking a column header toggles dir
        if (isset($params['sort']) && is_string($params['sort'])) {
            $dir = (isset($params['dir']) && $params['dir'] === 'asc') ? 'asc' : 'desc';
            $this->columns->setSort($params['sort'], $dir);
            $cleanQuery = $params;
            unset($cleanQuery['sort'], $cleanQuery['dir']);
            $location = '/admin/clicks' . ($cleanQuery !== [] ? '?' . http_build_query($cleanQuery) : '');
            return $response->withHeader('Location', $location)->withStatus(302);
        }

        $page = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        // Routing filter: default 'all'. Accepted: 'hide', 'all' (default), 'only'.
        $routing = $params['is_trash'] ?? 'all';
        if (!in_array($routing, ['hide', 'all', 'only'], true)) $routing = 'all';

        $hasExactLookup = !empty($params['click_id']) || !empty($params['visitor']) || !empty($params['fp_js']);

        // Default operator view: search/AI referrers only, known bots hidden.
        // Exact click/visitor links bypass the defaults so postback audits work.
        $botView = $params['bot_view'] ?? ($hasExactLookup ? 'all' : 'hide');
        if (!in_array($botView, ['hide', 'all', 'only'], true)) $botView = 'all';

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'country'     => $params['country'] ?? null,
            'device'      => $params['device'] ?? null,
            'bot_view'    => $botView,
            'is_uniq'     => isset($params['is_uniq']) && $params['is_uniq'] !== '' ? ($params['is_uniq'] === '1') : null,
            'is_trash'    => $routing,
            'since'       => $params['since'] ?? null,
            'search'      => array_key_exists('search', $params)
                ? (is_string($params['search']) && $params['search'] !== '' ? $params['search'] : null)
                : ($hasExactLookup ? null : 'any'),
            'ip'          => is_string($params['ip'] ?? null) && trim($params['ip']) !== '' ? trim($params['ip']) : null,
            'click_id'    => is_string($params['click_id'] ?? null) && $params['click_id'] !== '' ? $params['click_id'] : null,
            'visitor'     => is_string($params['visitor'] ?? null) && $params['visitor'] !== '' ? $params['visitor'] : null,
            'fp_js'       => is_string($params['fp_js'] ?? null) && $params['fp_js'] !== '' ? $params['fp_js'] : null,
            // Server-side go.php traffic normally has no browser fingerprint.
            'fp_js_has'   => isset($params['fp_js_has'])
                ? (in_array($params['fp_js_has'], ['0', '1'], true) ? $params['fp_js_has'] : null)
                : null,
        ];

        $sort = $this->columns->sort();
        $orderColumn = $this->columns->sortColumn() ?? 'c.created_at';
        $orderBy = $orderColumn . ' ' . ($sort['dir'] === 'asc' ? 'ASC' : 'DESC');

        $items = $this->repo->page($page, $perPage, $filters, $orderBy);
        $total = $this->repo->count($filters);
        $pages = max(1, (int)ceil($total / $perPage));
        $timeline = $this->repo->hourlyTimeline($filters);

        // When the user is drilling into a single click via ?click_id=, also
        // load the visitor card + journey so they can see the full pre/post
        // history without a separate page navigation. Same treatment for
        // ?fp_js= — except the journey unions across every visitor_uuid that
        // ever carried this fingerprint (cookie clears, multi-domain visits).
        $visitor = null;
        if (!empty($filters['click_id']) && $items !== [] && !empty($items[0]['visitor_uuid'])) {
            $vu = (string)$items[0]['visitor_uuid'];
            $visitor = [
                'kind'    => 'visitor',
                'uuid'    => $vu,
                'totals'  => $this->repo->visitorTotals($vu),
                'entry'   => $this->repo->entrySource($vu),
                'journey' => $this->repo->visitorJourney($vu),
            ];
        } elseif (!empty($filters['fp_js'])) {
            $fp = (string)$filters['fp_js'];
            $visitor = [
                'kind'    => 'fp_js',
                'uuid'    => $fp,
                'totals'  => $this->repo->visitorTotalsByFp($fp),
                'entry'   => $this->repo->entrySourceByFp($fp),
                'journey' => $this->repo->visitorJourneyByFp($fp),
            ];
        } elseif (!empty($filters['visitor'])) {
            // Deep-link from /admin/pixel — show the visitor card + journey
            // (pageviews + clicks + conversions) keyed straight off visitor_uuid.
            $vu = (string)$filters['visitor'];
            $visitor = [
                'kind'    => 'visitor',
                'uuid'    => $vu,
                'totals'  => $this->repo->visitorTotals($vu),
                'entry'   => $this->repo->entrySource($vu),
                'journey' => $this->repo->visitorJourney($vu),
            ];
        }

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => 'Clicks',
                '__layout__' => 'layouts/admin',
                'items' => $items,
                'total' => $total,
                'pages' => $pages,
                'page' => $page,
                'filters' => $filters,
                'timeline' => $timeline,
                'campaigns' => $this->campaigns->page(1, 100),
                'columns_meta' => ColumnPreferences::COLUMNS,
                'visible_columns' => $this->columns->visible(),
                'sort' => $sort,
                'visitor' => $visitor,
            ],
        );
        return $view->respond($response, 'admin/clicks/index', $data);
    }

    public function saveColumns(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['columns'] ?? '') : '';
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $this->columns->setVisible(array_values(array_filter($decoded, 'is_string')));
        }
        return $response->withHeader('Location', '/admin/clicks')->withStatus(302);
    }

    public function resetColumns(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->columns->reset();
        return $response->withHeader('Location', '/admin/clicks')->withStatus(302);
    }
}
