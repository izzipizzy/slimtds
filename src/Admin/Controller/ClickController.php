<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Clicks\ColumnPreferences;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\ViewPreferenceRepository;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ClickController
{
    /** Filter keys an operator may pin as their personal default for this list. */
    private const SAVEABLE = ['is_trash', 'bot_view', 'search', 'entry_ref', 'fp_js_has'];

    private const VIEW_KEY = 'clicks';

    public function __construct(
        private readonly ClickRepository $repo,
        private readonly CampaignRepository $campaigns,
        private readonly ColumnPreferences $columns,
        private readonly ViewPreferenceRepository $viewPrefs,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params = $request->getQueryParams();

        // Saved default view — applied ONLY when the operator arrives with no
        // filter of their own in the URL. Any explicit query wins outright, so a
        // shared link always shows the same rows to whoever opens it.
        $saved = [];
        if (!$this->hasOwnFilters($params)) {
            $saved = $this->viewPrefs->get($this->adminId(), self::VIEW_KEY, self::SAVEABLE);
            $params += $saved;
        }

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

        // Routing filter: default 'all' — the fingerprint-gated default view (see
        // fp_js_has below) is about every visitor that left a fingerprint,
        // trash and routed alike. Accepted: 'hide', 'all' (default), 'only'.
        $routing = $params['is_trash'] ?? 'all';
        if (!in_array($routing, ['hide', 'all', 'only'], true)) $routing = 'all';

        // Bot filter: default 'all' too — bots that executed the pixel and got a
        // fingerprint are part of the same fingerprinted-traffic view.
        $botView = $params['bot_view'] ?? 'all';
        if (!in_array($botView, ['hide', 'all', 'only'], true)) $botView = 'all';

        // Entry-source filter — OPT-IN only, so the default view is unchanged.
        // Exact lookups bypass it: a postback audit must find its click_id row
        // no matter how the list happens to be filtered.
        $hasExactLookup = ($params['click_id'] ?? '') !== ''
            || ($params['visitor'] ?? '') !== ''
            || ($params['fp_js'] ?? '') !== '';
        $entryRef = is_string($params['entry_ref'] ?? null) && $params['entry_ref'] !== ''
            ? $params['entry_ref']
            : null;
        if ($hasExactLookup) $entryRef = null;

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'entry_ref'   => $entryRef,
            'country'     => $params['country'] ?? null,
            'device'      => $params['device'] ?? null,
            'bot_view'    => $botView,
            'is_uniq'     => isset($params['is_uniq']) && $params['is_uniq'] !== '' ? ($params['is_uniq'] === '1') : null,
            'is_trash'    => $routing,
            'since'       => $params['since'] ?? null,
            'search'      => is_string($params['search'] ?? null) && $params['search'] !== '' ? $params['search'] : null,
            'ip'          => is_string($params['ip'] ?? null) && trim($params['ip']) !== '' ? trim($params['ip']) : null,
            'click_id'    => is_string($params['click_id'] ?? null) && $params['click_id'] !== '' ? $params['click_id'] : null,
            'visitor'     => is_string($params['visitor'] ?? null) && $params['visitor'] !== '' ? $params['visitor'] : null,
            'fp_js'       => is_string($params['fp_js'] ?? null) && $params['fp_js'] !== '' ? $params['fp_js'] : null,
            // Default '1': show only clicks that carry a fingerprint (the ones a
            // replay session can be linked to). Pass fp_js_has= (empty) for all.
            'fp_js_has'   => isset($params['fp_js_has'])
                ? (in_array($params['fp_js_has'], ['0', '1'], true) ? $params['fp_js_has'] : null)
                : '1',
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
                'saved_view' => $saved,
            ],
        );
        return $view->respond($response, 'admin/clicks/index', $data);
    }

    /**
     * Pin the filters currently in the URL as this operator's default view.
     * Saving with an empty query clears the preference, which is what the
     * "reset" affordance sends.
     */
    public function saveView(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $prefs = [];
        if (is_array($body)) {
            foreach (self::SAVEABLE as $key) {
                $val = $body[$key] ?? null;
                if (is_string($val) && $val !== '') {
                    $prefs[$key] = $val;
                }
            }
        }

        if ($prefs === []) {
            $this->viewPrefs->clear($this->adminId(), self::VIEW_KEY);
        } else {
            $this->viewPrefs->save($this->adminId(), self::VIEW_KEY, $prefs, self::SAVEABLE);
        }

        return $response->withHeader('Location', '/admin/clicks')->withStatus(302);
    }

    /**
     * True when the operator brought any filter of their own in the query
     * string. Pagination and sorting are navigation, not filtering, so they do
     * not count — page 2 of a saved view must stay inside that view.
     * @param array<string,mixed> $params
     */
    private function hasOwnFilters(array $params): bool
    {
        foreach ($params as $key => $value) {
            if (in_array($key, ['page', 'sort', 'dir'], true)) {
                continue;
            }
            if ($value !== '' && $value !== null) {
                return true;
            }
        }
        return false;
    }

    private function adminId(): int
    {
        $id = $_SESSION['admin_id'] ?? null;
        return is_int($id) ? $id : 0;
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
