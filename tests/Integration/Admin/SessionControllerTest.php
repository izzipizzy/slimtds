<?php

declare(strict_types=1);

use App\Admin\Controller\SessionController;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $this->pdo = pdo();
    foreach ($this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $this->pdo->exec("TRUNCATE {$r}");
    }
    $this->db = new Connection($this->pdo);
    (new Partitions($this->pdo))->ensureDailyAhead(1);
    $this->pdo->exec('DELETE FROM core.campaigns');
    $camp = (new CampaignRepository($this->db, new CampaignIdGenerator()))
        ->create(['name' => 'Sess Test', 'slug' => 'ses01', 'is_active' => '1']);
    $this->sid = '77777777-7777-7777-8777-777777777777';
    $gz = base64_encode(gzencode(json_encode([['e' => 1], ['e' => 2]])));
    $this->db->execute(
        "INSERT INTO stats.rrweb_chunks (session_id, campaign_id, seq, payload, created_at)
         VALUES (:s, :c, 0, decode(:b,'base64'), now())",
        ['s' => $this->sid, 'c' => $camp->id, 'b' => $gz],
    );
    $this->ctrl = new SessionController(
        new RrwebSessionRepository($this->db),
        new CampaignRepository($this->db, new CampaignIdGenerator()),
    );
});

test('events endpoint returns concatenated events as JSON', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', "/admin/sessions/{$this->sid}/events");
    $resp = $this->ctrl->events($req, new Response(), $this->sid);
    expect($resp->getStatusCode())->toBe(200);
    $data = json_decode((string)$resp->getBody(), true);
    expect($data['events'])->toHaveCount(2);
});
