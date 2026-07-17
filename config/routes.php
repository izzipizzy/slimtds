<?php

declare(strict_types=1);

use App\Admin\Middleware\AuthMiddleware;
use App\Admin\Middleware\CsrfMiddleware;
use App\Admin\Middleware\LocaleMiddleware;
use App\Admin\Middleware\RateLimitMiddleware;
use App\Admin\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // Health endpoint — no DB, no session, pure sanity check
    $app->get('/__health', function (Request $request, Response $response): Response {
        $response->getBody()->write(json_encode(['ok' => true, 'ts' => gmdate('c')], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Корень — пока редирект в админку
    $app->get('/', function (Request $request, Response $response): Response {
        return $response->withHeader('Location', '/admin')->withStatus(302);
    });

    // Пиксель скрипт (M2)
    $app->get('/p.js', \App\Pixel\ScriptController::class);

    // Пиксель events (M2)
    $app->post('/p/event', \App\Pixel\EventController::class);
    $app->options('/p/event', [\App\Pixel\EventController::class, 'preflight']);

    // rrweb session chunks
    $app->post('/p/rec', \App\Pixel\RecordController::class);
    $app->options('/p/rec', [\App\Pixel\RecordController::class, 'preflight']);

    // Постбэк (M2)
    $app->get('/postback',  \App\Postback\PostbackController::class);
    $app->post('/postback', \App\Postback\PostbackController::class);

    // Admin group with middleware stack
    $app->group('/admin', function (RouteCollectorProxy $g): void {
        // Login — gets rate-limit in addition
        $g->get('/login', function (Request $request, Response $response, \App\Shared\View\View $view): Response {
            $data = array_merge(
                $view->withRequestContext($request),
                ['title' => 'Login', '__layout__' => 'layouts/public'],
            );
            return $view->respond($response, 'admin/login', $data);
        });

        // Language switch — sets cookie, redirects to Referer (outside auth gate)
        $g->get('/lang/{lang:ru|en}', function (Request $request, Response $response, string $lang): Response {
            $response = $response
                ->withHeader('Set-Cookie', "ui_lang={$lang}; Path=/; Max-Age=31536000; HttpOnly; Secure; SameSite=Lax")
                ->withHeader('Location', $request->getHeaderLine('Referer') ?: '/admin/login')
                ->withStatus(302);
            return $response;
        });

        // Login POST — real handler (Task 17)
        $g->post('/login', [\App\Admin\Controller\LoginController::class, 'postLogin'])
            ->add(RateLimitMiddleware::class);

        // Logout (Task 17)
        $g->get('/logout', [\App\Admin\Controller\LoginController::class, 'getLogout']);

        // Change password (protected)
        $g->get('/password', [\App\Admin\Controller\PasswordController::class, 'get']);
        $g->post('/password', [\App\Admin\Controller\PasswordController::class, 'post']);

        // Campaigns
        $g->get('/campaigns',                 [\App\Admin\Controller\CampaignController::class, 'index']);
        $g->get('/campaigns/new',             [\App\Admin\Controller\CampaignController::class, 'new_']);
        $g->post('/campaigns',                [\App\Admin\Controller\CampaignController::class, 'create']);
        $g->get('/campaigns/{id}/edit',       [\App\Admin\Controller\CampaignController::class, 'edit']);
        $g->post('/campaigns/{id}',           [\App\Admin\Controller\CampaignController::class, 'update']);
        $g->get('/campaigns/{id}/delete',     [\App\Admin\Controller\CampaignController::class, 'deleteConfirm']);
        $g->post('/campaigns/{id}/delete',    [\App\Admin\Controller\CampaignController::class, 'delete']);

        // Per-campaign pixel page
        $g->get('/campaigns/{cid}/pixel', [\App\Admin\Controller\CampaignController::class, 'pixel']);

        // Per-campaign stats page
        $g->get('/campaigns/{cid}/stats', [\App\Admin\Controller\CampaignController::class, 'stats']);

        // Offers — global, no campaign coupling
        $g->get('/offers',                       [\App\Admin\Controller\OfferController::class, 'all']);
        $g->get('/offers/new',                   [\App\Admin\Controller\OfferController::class, 'new_']);
        $g->post('/offers',                      [\App\Admin\Controller\OfferController::class, 'create']);
        $g->get('/offers/{id}/edit',             [\App\Admin\Controller\OfferController::class, 'edit']);
        $g->post('/offers/{id}',                 [\App\Admin\Controller\OfferController::class, 'update']);
        $g->post('/offers/{id}/rotate-token',    [\App\Admin\Controller\OfferController::class, 'rotateToken']);
        $g->get('/offers/{id}/delete',           [\App\Admin\Controller\OfferController::class, 'deleteConfirm']);
        $g->post('/offers/{id}/delete',          [\App\Admin\Controller\OfferController::class, 'delete']);

        // Per-campaign offers view (read-only listing of offers used by this campaign's flows)
        $g->get('/campaigns/{cid}/offers',       [\App\Admin\Controller\OfferController::class, 'index']);

        // Flows — top-level cross-campaign list
        $g->get('/flows', [\App\Admin\Controller\FlowController::class, 'all']);

        // Clicks log viewer
        $g->get('/clicks',                  [\App\Admin\Controller\ClickController::class, 'index']);
        $g->post('/clicks/columns',         [\App\Admin\Controller\ClickController::class, 'saveColumns']);
        $g->post('/clicks/columns/reset',   [\App\Admin\Controller\ClickController::class, 'resetColumns']);

        // Conversions log viewer
        $g->get('/conversions', [\App\Admin\Controller\ConversionController::class, 'index']);

        // Pixel events cross-campaign feed
        $g->get('/pixel',                  [\App\Admin\Controller\PixelController::class, 'index']);
        $g->post('/pixel/columns',         [\App\Admin\Controller\PixelController::class, 'saveColumns']);
        $g->post('/pixel/columns/reset',   [\App\Admin\Controller\PixelController::class, 'resetColumns']);

        // Settings
        $g->get('/settings',  [\App\Admin\Controller\SettingsController::class, 'index']);
        $g->post('/settings', [\App\Admin\Controller\SettingsController::class, 'index']);

        // Backups — list/run/download/delete pg_dump archives. The list lives
        // inside the /admin/settings tabbed page; the mutations are sub-paths.
        $g->post('/settings/backups',                          [\App\Admin\Controller\BackupController::class, 'create']);
        $g->get('/settings/backups/{name}/download',           [\App\Admin\Controller\BackupController::class, 'download']);
        $g->post('/settings/backups/{name}/delete',            [\App\Admin\Controller\BackupController::class, 'delete']);
        $g->post('/settings/notifications/test', [\App\Admin\Controller\SettingsController::class, 'testTelegram']);

        // Statistics dashboard
        $g->get('/statistics', [\App\Admin\Controller\StatsController::class, 'index']);

        // Flows (nested under campaign)
        $g->get('/campaigns/{cid}/flows',                    [\App\Admin\Controller\FlowController::class, 'index']);
        $g->get('/campaigns/{cid}/flows/new',                [\App\Admin\Controller\FlowController::class, 'new_']);
        $g->post('/campaigns/{cid}/flows',                   [\App\Admin\Controller\FlowController::class, 'create']);
        $g->get('/campaigns/{cid}/flows/{id}/edit',          [\App\Admin\Controller\FlowController::class, 'edit']);
        $g->post('/campaigns/{cid}/flows/{id}',              [\App\Admin\Controller\FlowController::class, 'update']);
        $g->get('/campaigns/{cid}/flows/{id}/delete',        [\App\Admin\Controller\FlowController::class, 'deleteConfirm']);
        $g->post('/campaigns/{cid}/flows/{id}/delete',       [\App\Admin\Controller\FlowController::class, 'delete']);
        $g->post('/campaigns/{cid}/flows/{id}/move',         [\App\Admin\Controller\FlowController::class, 'move']);

        // rrweb session replay
        $g->get('/sessions',               [\App\Admin\Controller\SessionController::class, 'index']);
        $g->get('/sessions/{sid}',         [\App\Admin\Controller\SessionController::class, 'show']);
        $g->get('/sessions/{sid}/events',  [\App\Admin\Controller\SessionController::class, 'events']);

        // Dashboard (protected)
        $g->get('[/{path:.*}]', function (
            Request $request,
            Response $response,
            \App\Shared\View\View $view,
            \App\Admin\Repository\CampaignRepository $campaigns,
            \App\Admin\Repository\OfferRepository $offers,
            \App\Admin\Repository\FlowRepository $flows,
            \App\Shared\Auth\AuthEventLogger $audit,
        ): Response {
            $campaignsCount = $campaigns->count();
            $offersCount = (int)$campaigns->count() === 0
                ? 0
                : array_sum($offers->countsByCampaign(array_map(fn ($c) => $c->id, $campaigns->page(1, 1000))));
            $flowsCount = (int)$campaigns->count() === 0
                ? 0
                : array_sum($flows->countsByCampaign(array_map(fn ($c) => $c->id, $campaigns->page(1, 1000))));
            $data = array_merge(
                $view->withRequestContext($request),
                [
                    'title' => 'Dashboard',
                    '__layout__' => 'layouts/admin',
                    'campaigns_count' => $campaignsCount,
                    'offers_count' => $offersCount,
                    'flows_count' => $flowsCount,
                    'recent_events' => $audit->recent(10),
                ],
            );
            return $view->respond($response, 'admin/dashboard', $data);
        });
    })
        ->add(\App\Admin\Middleware\PasswordChangeRequiredMiddleware::class)
        ->add(AuthMiddleware::class)
        ->add(CsrfMiddleware::class)
        ->add(LocaleMiddleware::class)
        ->add(SessionMiddleware::class);

    // Движок редиректа (slug) — last so static /admin, /postback, /p.js, /__health win
    $app->get('/{slug:[A-Za-z0-9]{3,16}}', [\App\Engine\ClickHandler::class, 'handle']);
};
