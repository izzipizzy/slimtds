<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM stats.rrweb_sessions');
    foreach ($this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $this->pdo->exec("TRUNCATE {$r}");
    }
    $this->db = new Connection($this->pdo);
    (new Partitions($this->pdo))->ensureDailyAhead(1);
    $this->pdo->exec('DELETE FROM core.campaigns');
    $this->camp = (new CampaignRepository($this->db, new CampaignIdGenerator()))
        ->create(['name' => 'Repo Test', 'slug' => 'rpo01', 'is_active' => '1']);
    $this->repo = new RrwebSessionRepository($this->db);

    $sid = '66666666-6666-7666-8666-666666666666';
    foreach ([[0, [['e' => 1], ['e' => 2]]], [1, [['e' => 3]]]] as [$seq, $events]) {
        $gz = base64_encode(gzencode(json_encode($events)));
        $this->db->execute(
            "INSERT INTO stats.rrweb_chunks (session_id, campaign_id, seq, payload, created_at)
             VALUES (:s, :c, :seq, decode(:b,'base64'), now())",
            ['s' => $sid, 'c' => $this->camp->id, 'seq' => $seq, 'b' => $gz],
        );
    }
    $this->db->execute(
        "INSERT INTO stats.rrweb_sessions (session_id, campaign_id, started_at, last_at, chunk_count, event_count, bytes)
         VALUES (:s, :c, now(), now(), 2, 3, 100)",
        ['s' => $sid, 'c' => $this->camp->id],
    );
    $this->sid = $sid;
});

test('page + count return the session', function (): void {
    expect($this->repo->count([]))->toBe(1);
    $rows = $this->repo->page([], 1, 50);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['session_id'])->toBe($this->sid);
});

test('page + count filter by fingerprint', function (): void {
    $this->db->execute("UPDATE stats.rrweb_sessions SET fp_js = 'fp_x' WHERE session_id = :s", ['s' => $this->sid]);
    expect($this->repo->count(['fp' => 'fp_x']))->toBe(1);
    expect($this->repo->count(['fp' => 'nope']))->toBe(0);
    expect($this->repo->page(['fp' => 'fp_x'], 1, 50))->toHaveCount(1);
});

test('filter + distinct by lander domain', function (): void {
    $this->db->execute(
        "UPDATE stats.rrweb_sessions SET page_url = 'https://lander.test/promo' WHERE session_id = :s",
        ['s' => $this->sid],
    );
    expect($this->repo->distinct('domain'))->toBe(['lander.test']);
    expect($this->repo->count(['domain' => 'lander.test']))->toBe(1);
    expect($this->repo->count(['domain' => 'other.test']))->toBe(0);
    expect($this->repo->page(['domain' => 'lander.test'], 1, 50))->toHaveCount(1);
});

test('filter + distinct by client fields', function (): void {
    $this->db->execute(
        "UPDATE stats.rrweb_sessions SET country='us', browser='Chrome 174', os='iOS', device='mobile' WHERE session_id = :s",
        ['s' => $this->sid],
    );
    expect($this->repo->distinct('country'))->toBe(['us']);
    expect($this->repo->distinct('browser'))->toBe(['Chrome 174']);
    expect($this->repo->distinct('device'))->toBe(['mobile']);
    expect($this->repo->count(['country' => 'us', 'device' => 'mobile']))->toBe(1);
    expect($this->repo->count(['os' => 'Android']))->toBe(0);
    expect($this->repo->page(['browser' => 'Chrome 174'], 1, 50))->toHaveCount(1);
});

test('duration_ms + traffic-source filter', function (): void {
    $this->db->execute(
        "UPDATE stats.rrweb_sessions
         SET referer='https://www.google.com/search?q=x', first_event_ms=1000, last_event_ms=46000
         WHERE session_id = :s",
        ['s' => $this->sid],
    );
    $rows = $this->repo->page([], 1, 50);
    expect((int)$rows[0]['duration_ms'])->toBe(45000);
    expect($this->repo->count(['min_dur' => '3']))->toBe(1);   // 45s >= 3s
    expect($this->repo->count(['min_dur' => '60']))->toBe(0);  // 45s < 60s
    expect($this->repo->count(['source' => 'google']))->toBe(1);
    expect($this->repo->count(['source' => 'any']))->toBe(1);
    expect($this->repo->count(['source' => 'chatgpt']))->toBe(0);
});

test('traffic source resolved via linked pixel events by fp', function (): void {
    $this->db->execute("UPDATE stats.rrweb_sessions SET fp_js='fp_src', referer=NULL WHERE session_id = :s", ['s' => $this->sid]);
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, fp_js, referer)
         VALUES (:c, '00000000-0000-7000-8000-000000000001', 'fp_src', 'https://lander.test/?utm_source=chatgpt.com')",
        ['c' => $this->camp->id],
    );
    expect($this->repo->pixelSourceReferers(['fp_src']))->toBe(['fp_src' => 'https://lander.test/?utm_source=chatgpt.com']);
    expect($this->repo->count(['source' => 'chatgpt']))->toBe(1); // via pixel EXISTS, session.referer is null
    expect($this->repo->count(['source' => 'google']))->toBe(0);
});

test('sort by duration desc orders longest first', function (): void {
    $this->db->execute("UPDATE stats.rrweb_sessions SET first_event_ms=0, last_event_ms=5000 WHERE session_id = :s", ['s' => $this->sid]);
    $sid2 = '77777777-7777-7777-8777-777777777777';
    $this->db->execute(
        "INSERT INTO stats.rrweb_sessions (session_id, campaign_id, started_at, last_at, chunk_count, event_count, bytes, first_event_ms, last_event_ms)
         VALUES (:s, :c, now(), now(), 1, 1, 10, 0, 60000)",
        ['s' => $sid2, 'c' => $this->camp->id],
    );
    $rows = $this->repo->page([], 1, 50, 'duration', 'desc');
    expect($rows[0]['session_id'])->toBe($sid2);
});

test('events concatenates chunks in seq order', function (): void {
    $events = $this->repo->events($this->sid);
    expect($events)->toHaveCount(3);
    expect($events[0]['e'])->toBe(1);
    expect($events[2]['e'])->toBe(3);
});

test('get returns the session row or null', function (): void {
    expect($this->repo->get($this->sid))->not->toBeNull();
    expect($this->repo->get('00000000-0000-7000-8000-000000000000'))->toBeNull();
});
