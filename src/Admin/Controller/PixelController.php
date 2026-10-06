<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Pixel\PixelColumnPreferences;
use App\Admin\Repository\CampaignRepository;
use App\Pixel\PixelEventRepository;
use App\Shared\Time\DateRange;
use App\Shared\Time\TimelineWindow;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PixelController
{
    /** Days in the default list window — mirrors PixelEventRepository's `interval '7 days'`. */
    private const DEFAULT_DAYS = 7;

    public function __construct(
        private readonly PixelEventRepository $repo,
        private readonly CampaignRepository $campaigns,
        private readonly PixelColumnPreferences $columns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $params  = $request->getQueryParams();

        // Sort: ?sort=<col>&dir=asc|desc — clicking a column header toggles dir
        if (isset($params['sort']) && is_string($params['sort'])) {
            $dir = (isset($params['dir']) && $params['dir'] === 'asc') ? 'asc' : 'desc';
            $this->columns->setSort($params['sort'], $dir);
            $cleanQuery = $params;
            unset($cleanQuery['sort'], $cleanQuery['dir']);
            $location = '/admin/pixel' . ($cleanQuery !== [] ? '?' . http_build_query($cleanQuery) : '');
            return $response->withHeader('Location', $location)->withStatus(302);
        }

        $page    = max(1, (int)($params['page'] ?? '1'));
        $perPage = 50;

        // Day range from the filter bar. Unset = the repository's rolling
        // 7-day default; the inputs are prefilled with that same window.
        $range = DateRange::fromQuery($params, self::DEFAULT_DAYS, fn (): ?string => $this->repo->earliestDay());

        // Legacy 'since' (old deep links): accept ISO format; ignored if invalid
        $sinceRaw = $params['since'] ?? null;
        if ($sinceRaw !== null && $sinceRaw !== '' && strtotime($sinceRaw) === false) {
            $sinceRaw = null;
        }

        // Bot view: hide bots by default (same semantics as /admin/clicks).
        $botView = $params['bot_view'] ?? 'hide';
        if (!in_array($botView, ['hide', 'all', 'only'], true)) $botView = 'hide';

        $filters = [
            'campaign_id' => $params['campaign_id'] ?? null,
            'event_name'  => $params['event_name']  ?? null,
            'domain'      => $params['domain']       ?? null,
            'country'     => is_string($params['country'] ?? null) ? trim($params['country']) : null,
            'ip'          => is_string($params['ip'] ?? null) && trim($params['ip']) !== '' ? trim($params['ip']) : null,
            'since'       => $sinceRaw !== '' ? $sinceRaw : null,
            'from'        => $range->from,
            'to'          => $range->to,
            'range'       => $range->preset,
            'search'      => is_string($params['search'] ?? null) && $params['search'] !== '' ? $params['search'] : null,
            'bot_view'    => $botView,
            'fp_js'       => is_string($params['fp_js'] ?? null) && $params['fp_js'] !== '' ? $params['fp_js'] : null,
        ];
        // Normalise empty strings to null
        foreach ($filters as $k => $v) {
            if ($v === '') {
                $filters[$k] = null;
            }
        }

        $sort = $this->columns->sort();
        $orderColumn = $this->columns->sortColumn() ?? 'pe.created_at';
        $orderBy = $orderColumn . ' ' . ($sort['dir'] === 'asc' ? 'ASC' : 'DESC');

        $items   = $this->repo->page($page, $perPage, $filters, $orderBy);
        $total   = $this->repo->count($filters);
        $pages   = max(1, (int)ceil($total / $perPage));
        $summary = $this->repo->summary($filters);
        $topDomains    = $this->repo->topDomains($filters, 10);
        $topEventNames = $this->repo->topEventNames($filters, 10);
        $window        = TimelineWindow::fromFilters($filters, self::DEFAULT_DAYS);
        $timeline      = $this->repo->timeline($filters, $window);

        // What the date inputs show: the picked range, else the window the list
        // is actually using (a legacy 'since' link, or the 7-day default).
        $sinceTs = is_string($sinceRaw) && $sinceRaw !== '' ? strtotime($sinceRaw) : false;
        $rangeFrom = $range->from
            ?? ($sinceTs !== false ? date('Y-m-d', $sinceTs) : DateRange::daysAgo(self::DEFAULT_DAYS));

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'           => 'Pixel',
                '__layout__'      => 'layouts/admin',
                'items'           => $items,
                'total'           => $total,
                'pages'           => $pages,
                'page'            => $page,
                'filters'         => $range->forView($filters),
                'summary'         => $summary,
                'topDomains'      => $topDomains,
                'topEventNames'   => $topEventNames,
                'timeline'        => $timeline,
                'timeline_step'   => $window->step,
                'range_from'      => $rangeFrom,
                'range_to'        => $range->to ?? DateRange::today(),
                'campaigns'       => $this->campaigns->page(1, 100),
                'columns_meta'    => PixelColumnPreferences::COLUMNS,
                'visible_columns' => $this->columns->visible(),
                'sort'            => $sort,
            ],
        );
        return $view->respond($response, 'admin/pixel/index', $data);
    }

    public function saveColumns(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['columns'] ?? '') : '';
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $this->columns->setVisible(array_values(array_filter($decoded, 'is_string')));
        }
        return $response->withHeader('Location', '/admin/pixel')->withStatus(302);
    }

    public function resetColumns(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->columns->reset();
        return $response->withHeader('Location', '/admin/pixel')->withStatus(302);
    }
}
