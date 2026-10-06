<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Shared\Time\DateRange;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SessionController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly RrwebSessionRepository $sessions,
        private readonly CampaignRepository $campaigns,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $q = $request->getQueryParams();
        $str = static fn (string $k): ?string => isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '' ? $q[$k] : null;

        // Sessions have no default window (retention bounds the table), so a
        // lone `to` needs no invented `from`.
        $range = DateRange::fromQuery($q, null);

        $filters = [
            'campaign_id' => $str('campaign'),
            'fp'          => $str('fp'),
            'domain'      => $str('domain'),
            'country'     => $str('country'),
            'browser'     => $str('browser'),
            'os'          => $str('os'),
            'device'      => $str('device'),
            'source'      => $str('source'),
            'activity'    => in_array($str('activity'), ['not_bot', 'all', 'active', 'inactive', 'unknown'], true) ? $str('activity') : 'not_bot',
            'from'        => $range->from,
            'to'          => $range->to,
            'range'       => $range->preset,
            // Default: all durations; the activity filter hides suspected bots.
            'min_dur'     => isset($q['min_dur']) && is_numeric($q['min_dur']) ? (string)max(0, (int)$q['min_dur']) : '0',
        ];
        $page = isset($q['page']) && is_numeric($q['page']) ? max(1, (int)$q['page']) : 1;
        $sort = (($q['sort'] ?? '') === 'duration') ? 'duration' : 'started';
        $dir  = (($q['dir'] ?? '') === 'asc') ? 'asc' : 'desc';

        $rows = $this->sessions->page($filters, $page, self::PER_PAGE, $sort, $dir);
        $total = $this->sessions->count($filters);

        // Traffic source via the fp link to pixel events (fallback when the
        // session's own referer is empty).
        $fps = array_values(array_filter(array_map(
            static fn (array $r): string => (string)($r['fp_js'] ?? ''),
            $rows,
        ), static fn (string $f): bool => $f !== ''));
        $pixelSrc = $this->sessions->pixelSourceReferers($fps);

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'       => 'Sessions',
                '__layout__'  => 'layouts/admin',
                'sessions'    => $rows,
                'total'       => $total,
                'page'        => $page,
                'pages'       => max(1, (int)ceil($total / self::PER_PAGE)),
                'per_page'    => self::PER_PAGE,
                'sort'        => $sort,
                'dir'         => $dir,
                'filters'     => $range->forView($filters),
                // Unset = everything on record, so the inputs show exactly that.
                'range_from'  => $range->from ?? $this->sessions->earliestDay() ?? DateRange::today(),
                'range_to'    => $range->to ?? DateRange::today(),
                'campaigns'   => $this->campaigns->page(1, 1000),
                'opt_domains' => $this->sessions->distinct('domain'),
                'opt_country' => $this->sessions->distinct('country'),
                'opt_browser' => $this->sessions->distinct('browser'),
                'opt_os'      => $this->sessions->distinct('os'),
                'opt_device'  => $this->sessions->distinct('device'),
                'opt_sources' => \App\Shared\Referer\SearchEngine::keys(),
                'pixel_src'   => $pixelSrc,
            ],
        );
        return $view->respond($response, 'admin/sessions/index', $data);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, View $view, string $sid): ResponseInterface
    {
        $session = $this->sessions->get($sid);
        if ($session === null) {
            return $response->withStatus(404);
        }
        $fp = (string)($session['fp_js'] ?? '');
        $pixelRef = $fp !== '' ? ($this->sessions->pixelSourceReferers([$fp])[$fp] ?? null) : null;
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'        => 'Session replay',
                '__layout__'   => 'layouts/admin',
                'session'      => $session,
                'pixel_referer' => $pixelRef,
                'events_url'   => "/admin/sessions/{$sid}/events",
            ],
        );
        return $view->respond($response, 'admin/sessions/show', $data);
    }

    public function events(ServerRequestInterface $request, ResponseInterface $response, string $sid): ResponseInterface
    {
        $events = $this->sessions->events($sid);
        $response->getBody()->write(json_encode(['events' => $events], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
