<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Cron\Command\RrwebFlushCommand;
use App\Engine\DeviceDetector;
use App\Engine\GeoLookup;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM stats.rrweb_inbox');
    $this->pdo->exec('DELETE FROM stats.rrweb_sessions');
    foreach ($this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN) as $r) {
        $this->pdo->exec("TRUNCATE {$r}");
    }
    $this->db   = new Connection($this->pdo);
    (new Partitions($this->pdo))->ensureDailyAhead(1);
    $repo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->pdo->exec('DELETE FROM core.campaigns');
    $this->camp = $repo->create(['name' => 'Flush Test', 'slug' => 'flx01', 'is_active' => '1']);

    $this->cmd = new RrwebFlushCommand(
        $this->db,
        $repo,
        new GeoLookup(),
        new DeviceDetector(),
        new Partitions($this->pdo),
    );
});

test('drainOnce promotes inbox chunk to chunks + session', function (): void {
    $sid = '33333333-3333-7333-8333-333333333333';
    $payload = json_encode(['c' => 'flx01', 'sid' => $sid, 'seq' => 0, 'events' => [['type' => 4, 'data' => []], ['type' => 3, 'data' => []]]]);
    $this->db->execute(
        "INSERT INTO stats.rrweb_inbox (payload, ip, user_agent, visitor_uuid) VALUES (:p::jsonb, NULL, 'UA', :vu)",
        ['p' => $payload, 'vu' => '44444444-4444-7444-8444-444444444444'],
    );

    $n = $this->cmd->drainOnce();
    expect($n)->toBe(1);
    expect($this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_inbox'))->toBe(0);

    $chunk = $this->db->fetchOne(
        "SELECT campaign_id, seq, encode(payload,'base64') AS b64 FROM stats.rrweb_chunks WHERE session_id = :s",
        ['s' => $sid],
    );
    expect($chunk['campaign_id'])->toBe($this->camp->id);
    $events = json_decode(gzdecode(base64_decode((string)$chunk['b64'])), true);
    expect($events)->toHaveCount(2);

    $sess = $this->db->fetchOne('SELECT chunk_count, event_count FROM stats.rrweb_sessions WHERE session_id = :s', ['s' => $sid]);
    expect((int)$sess['chunk_count'])->toBe(1);
    expect((int)$sess['event_count'])->toBe(2);
});

test('drainOnce accumulates a second chunk onto the same session', function (): void {
    $sid = '55555555-5555-7555-8555-555555555555';
    foreach ([0, 1] as $seq) {
        $this->db->execute(
            "INSERT INTO stats.rrweb_inbox (payload, ip, user_agent, visitor_uuid) VALUES (:p::jsonb, NULL, 'UA', NULL)",
            ['p' => json_encode(['c' => 'flx01', 'sid' => $sid, 'seq' => $seq, 'events' => [['type' => $seq]]])],
        );
    }
    $this->cmd->drainOnce();
    $sess = $this->db->fetchOne('SELECT chunk_count, event_count FROM stats.rrweb_sessions WHERE session_id = :s', ['s' => $sid]);
    expect((int)$sess['chunk_count'])->toBe(2);
    expect((int)$sess['event_count'])->toBe(2);
});

test('drainOnce skips malformed payload without throwing', function (): void {
    $this->db->execute("INSERT INTO stats.rrweb_inbox (payload) VALUES ('{\"junk\":1}'::jsonb)");
    expect($this->cmd->drainOnce())->toBe(1); // counted as processed (deleted)
    expect($this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_inbox'))->toBe(0);
});

test('drainOnce isolates a bad row: non-uuid sid is skipped, valid row still flushes', function (): void {
    // A non-uuid sid can't go into the uuid session_id column. The bad row must
    // be skipped (and deleted) instead of aborting the batch and stranding the
    // valid sessions behind it.
    $bad  = json_encode(['c' => 'flx01', 'sid' => '1782222278112-23e9873795b1f8', 'seq' => 0, 'events' => [['type' => 4]]]);
    $good = '66666666-6666-7666-8666-666666666666';
    $ok   = json_encode(['c' => 'flx01', 'sid' => $good, 'seq' => 0, 'events' => [['type' => 4], ['type' => 3]]]);
    $this->db->execute("INSERT INTO stats.rrweb_inbox (payload) VALUES (:p::jsonb)", ['p' => $bad]);
    $this->db->execute("INSERT INTO stats.rrweb_inbox (payload) VALUES (:p::jsonb)", ['p' => $ok]);

    expect($this->cmd->drainOnce())->toBe(2);                                   // both rows consumed
    expect($this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_inbox'))->toBe(0); // inbox fully drained
    // valid session promoted; bad one absent
    expect($this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_sessions'))->toBe(1);
    $sess = $this->db->fetchOne('SELECT event_count FROM stats.rrweb_sessions WHERE session_id = :s', ['s' => $good]);
    expect((int)$sess['event_count'])->toBe(2);
});
