<?php

declare(strict_types=1);

use App\Admin\Clicks\ColumnPreferences;
use App\Admin\Controller\ClickController;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\ViewPreferenceRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

const CTRL_CAMPAIGN = '00000000-0000-7000-8000-0000000000f1';
const CTRL_V_SEARCH = '00000000-0000-7000-8000-0000000000f2';
const CTRL_V_PLAIN  = '00000000-0000-7000-8000-0000000000f3';

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM stats.pixel_events');
    $this->db = new Connection($pdo);

    $root = dirname(__DIR__, 3);
    $this->view = new View(
        $root . '/resources/views',
        new Manifest($root . '/public/assets/manifest.json'),
        new I18n((new TranslatorFactory($root . '/resources/translations'))->create()),
    );
    $this->ctrl = new ClickController(
        new ClickRepository($this->db),
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new ColumnPreferences(),
        new ViewPreferenceRepository($this->db),
    );

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:id, 'ctrlref', 'controller entry ref', true) ON CONFLICT (id) DO NOTHING",
        ['id' => CTRL_CAMPAIGN],
    );
    // One visitor arrived from Google, one has no recorded source at all.
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, entry_referer, created_at)
         VALUES (:c, :v, 'https://lander.test/', 'https://www.google.com/search?q=z', now() - interval '2 minutes')",
        ['c' => CTRL_CAMPAIGN, 'v' => CTRL_V_SEARCH],
    );
    $this->db->execute(
        // fp_js is set on purpose: the default clicks view is gated on having a
        // fingerprint, so a click without one is invisible before any filter
        // of ours gets a say.
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, fp_js, created_at)
         VALUES
            (:c, :vs, '2.2.2.1', 'https://lander.test/x', false, 'fp-search', now()),
            (:c, :vp, '2.2.2.2', 'https://lander.test/x', false, 'fp-plain',  now())",
        ['c' => CTRL_CAMPAIGN, 'vs' => CTRL_V_SEARCH, 'vp' => CTRL_V_PLAIN],
    );
});

function clicksHtml(array $query): string
{
    $req = (new ServerRequestFactory())
        ->createServerRequest('GET', '/admin/clicks')
        ->withQueryParams($query + ['campaign_id' => CTRL_CAMPAIGN, 'is_trash' => 'all']);
    $resp = (test()->ctrl)->index($req, new Response(), test()->view);
    return (string)$resp->getBody();
}

test('without the filter both clicks are listed', function (): void {
    $html = clicksHtml([]);
    expect($html)->toContain('2.2.2.1');
    expect($html)->toContain('2.2.2.2');
});

test('entry_ref reaches the repository and narrows the list', function (): void {
    $html = clicksHtml(['entry_ref' => 'google']);
    expect($html)->toContain('2.2.2.1');
    expect($html)->not->toContain('2.2.2.2');
});

test('an empty entry_ref is normalised away rather than filtering everything out', function (): void {
    $html = clicksHtml(['entry_ref' => '']);
    expect($html)->toContain('2.2.2.1');
    expect($html)->toContain('2.2.2.2');
});

test('an exact visitor lookup ignores the entry-source filter', function (): void {
    // A postback audit must find its row whatever the list happens to be
    // filtered by — otherwise the filter silently hides the thing being looked
    // up, and the operator concludes the click does not exist.
    $html = clicksHtml(['entry_ref' => 'google', 'visitor' => CTRL_V_PLAIN]);
    expect($html)->toContain('2.2.2.2');
});
