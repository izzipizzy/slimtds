<?php

declare(strict_types=1);

use App\Admin\Controller\SessionController;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $_SESSION = [];
    $this->pdo = pdo();

    // Truncate existing rrweb_chunks partitions
    foreach ($this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $this->pdo->exec("TRUNCATE {$r}");
    }
    $this->pdo->exec('TRUNCATE stats.rrweb_sessions');

    $this->db = new Connection($this->pdo);
    (new Partitions($this->pdo))->ensureDailyAhead(1);

    $this->pdo->exec('DELETE FROM core.campaigns');
    $camp = (new CampaignRepository($this->db, new CampaignIdGenerator()))
        ->create(['name' => 'View Test', 'slug' => 'vt0001', 'is_active' => '1']);

    $this->sid = '88888888-8888-8888-8888-888888888888';
    $gz = base64_encode(gzencode(json_encode([['e' => 1], ['e' => 2]])));

    // Insert a session row
    $this->db->execute(
        "INSERT INTO stats.rrweb_sessions (session_id, campaign_id, started_at, last_at, chunk_count, event_count, bytes)
         VALUES (:s, :c, now(), now(), 1, 2, 100)",
        ['s' => $this->sid, 'c' => $camp->id],
    );

    // Insert a chunk (needed for events endpoint; daily partitions required)
    $this->db->execute(
        "INSERT INTO stats.rrweb_chunks (session_id, campaign_id, seq, payload, created_at)
         VALUES (:s, :c, 0, decode(:b,'base64'), now())",
        ['s' => $this->sid, 'c' => $camp->id, 'b' => $gz],
    );

    $this->ctrl = new SessionController(
        new RrwebSessionRepository($this->db),
        new CampaignRepository($this->db, new CampaignIdGenerator()),
    );

    $root = dirname(__DIR__, 3);
    $assets = new Manifest($root . '/public/assets/manifest.json');
    $i18n   = new I18n((new TranslatorFactory($root . '/resources/translations'))->create());
    $this->view = new View($root . '/resources/views', $assets, $i18n);
});

test('sessions index renders without fatal', function (): void {
    $req  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/sessions');
    $resp = $this->ctrl->index($req, new Response(), $this->view);
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getBody())->not->toBeEmpty();
});

test('sessions show renders without fatal', function (): void {
    $req  = (new ServerRequestFactory())->createServerRequest('GET', "/admin/sessions/{$this->sid}");
    $resp = $this->ctrl->show($req, new Response(), $this->view, $this->sid);
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getBody())->not->toBeEmpty();
});


test('activity badge and filter render on list and replay with sorting preserved', function (): void {
    $this->db->execute('UPDATE stats.rrweb_sessions SET has_interaction = false, first_event_ms = 0, last_event_ms = 10000 WHERE session_id = :s', ['s' => $this->sid]);
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/sessions')->withQueryParams(['activity' => 'inactive', 'min_dur' => '0']);
    $body = (string)$this->ctrl->index($req, new Response(), $this->view)->getBody();
    expect($body)->toContain('data-session-activity="inactive"', 'name="activity"', 'value="inactive" selected', 'activity=inactive');
    $body = (string)$this->ctrl->show($req, new Response(), $this->view, $this->sid)->getBody();
    expect($body)->toContain('data-session-activity="inactive"');
});


test('sessions default hides suspected bots and includes short active and unknown recordings', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/sessions');
    $this->db->execute('UPDATE stats.rrweb_sessions SET has_interaction = false, first_event_ms = 0, last_event_ms = 1000 WHERE session_id = :s', ['s' => $this->sid]);
    $body = (string)$this->ctrl->index($req, new Response(), $this->view)->getBody();
    expect($body)->not->toContain('data-session-activity="inactive"');
    expect($body)->toContain('value="not_bot" selected', 'value="0" selected');
    $this->db->execute('UPDATE stats.rrweb_sessions SET has_interaction = true WHERE session_id = :s', ['s' => $this->sid]);
    expect((string)$this->ctrl->index($req, new Response(), $this->view)->getBody())->toContain('data-session-activity="active"');
    $this->db->execute('UPDATE stats.rrweb_sessions SET has_interaction = NULL WHERE session_id = :s', ['s' => $this->sid]);
    expect((string)$this->ctrl->index($req, new Response(), $this->view)->getBody())->toContain('data-session-activity="unknown"');
    $req = $req->withQueryParams(['activity' => 'all']);
    $this->db->execute('UPDATE stats.rrweb_sessions SET has_interaction = false WHERE session_id = :s', ['s' => $this->sid]);
    expect((string)$this->ctrl->index($req, new Response(), $this->view)->getBody())->toContain('data-session-activity="inactive"', 'value="all" selected');
});
