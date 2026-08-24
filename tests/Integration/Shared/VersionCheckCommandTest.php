<?php

declare(strict_types=1);

use App\Cron\Command\VersionCheckCommand;
use App\Shared\Db\Connection;
use App\Shared\Version\ReleaseFetchException;
use App\Shared\Version\ReleaseFetcher;
use App\Shared\Version\ReleaseResult;
use App\Shared\Version\RepoSlug;
use App\Shared\Version\UpdateStatusRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const SLUG = 'izzipizzy/slimtds';

/** A fetcher that returns whatever the test tells it to, and records the etag it was sent. */
function fakeFetcher(ReleaseResult|ReleaseFetchException $outcome, ?object $spy = null): ReleaseFetcher
{
    return new class ($outcome, $spy) implements ReleaseFetcher {
        public function __construct(private ReleaseResult|ReleaseFetchException $o, private ?object $spy) {}

        public function fetchLatest(RepoSlug $repo, ?string $etag = null): ReleaseResult
        {
            if ($this->spy !== null) {
                $this->spy->sentEtag = $etag;
                $this->spy->calls++;
            }
            if ($this->o instanceof ReleaseFetchException) {
                throw $this->o;
            }
            return $this->o;
        }
    };
}

function runCheck(
    UpdateStatusRepository $repo,
    ReleaseResult|ReleaseFetchException $outcome,
    ?object $spy = null,
    string $slug = SLUG,
    bool $enabled = true,
): array {
    $cmd = new VersionCheckCommand($repo, fakeFetcher($outcome, $spy), $enabled, $slug);
    $out = new BufferedOutput();
    $code = $cmd->run(new ArrayInput([]), $out);
    return [$code, $out->fetch()];
}

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.update_status');
    $this->db   = new Connection($pdo);
    $this->repo = new UpdateStatusRepository($this->db);
});

test('a successful check stores the release and both timestamps', function (): void {
    [$code] = runCheck($this->repo, ReleaseResult::release('v0.8.0', '2026-09-02T10:00:00Z', 'W/"abc"'));

    expect($code)->toBe(Command::SUCCESS);

    $s = $this->repo->read();
    expect($s->latestVersion)->toBe('v0.8.0');
    expect($s->repo)->toBe(SLUG);
    expect($s->lastSuccessAt)->toBeInt();
    expect($s->lastAttemptAt)->toBeInt();
    expect($s->lastError)->toBeNull();
    expect($s->etag)->toBe('W/"abc"');
    // The URL is built locally from the validated tag, never taken from the API.
    expect($s->latestUrl)->toBe('https://github.com/izzipizzy/slimtds/releases/tag/v0.8.0');
});

test('a second run updates the single row rather than duplicating it', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, null));
    runCheck($this->repo, ReleaseResult::release('v0.9.0', null, null));

    $n = (int)$this->db->fetchScalar('SELECT count(*) FROM core.update_status');
    expect($n)->toBe(1);
    expect($this->repo->read()->latestVersion)->toBe('v0.9.0');
});

test('a failed attempt preserves the last good answer but exits non-zero', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, null));
    $before = $this->repo->read()->lastSuccessAt;

    [$code, $text] = runCheck($this->repo, new ReleaseFetchException('rate-limited'));

    expect($code)->toBe(Command::FAILURE);          // core.cron_runs must see this
    expect($text)->toContain('rate-limited');

    $s = $this->repo->read();
    expect($s->latestVersion)->toBe('v0.8.0');      // last good answer kept
    expect($s->lastSuccessAt)->toBe($before);       // but its freshness does not advance
    expect($s->lastError)->toBe('rate-limited');
});

test('a recovered check clears the previous error', function (): void {
    runCheck($this->repo, new ReleaseFetchException('rate-limited'));
    expect($this->repo->read()->lastError)->toBe('rate-limited');

    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, null));
    expect($this->repo->read()->lastError)->toBeNull();
});

test('the stored etag is sent on the next attempt', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, 'W/"abc"'));

    $spy = new class { public ?string $sentEtag = null; public int $calls = 0; };
    runCheck($this->repo, ReleaseResult::notModified(), $spy);

    expect($spy->sentEtag)->toBe('W/"abc"');
});

test('a 304 keeps the release data and advances both timestamps', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', '2026-09-02T10:00:00Z', 'W/"abc"'));
    $this->db->execute("UPDATE core.update_status SET last_success_at = now() - interval '10 hours'");
    $stale = $this->repo->read()->lastSuccessAt;

    [$code] = runCheck($this->repo, ReleaseResult::notModified());

    expect($code)->toBe(Command::SUCCESS);
    $s = $this->repo->read();
    expect($s->latestVersion)->toBe('v0.8.0');
    expect($s->etag)->toBe('W/"abc"');
    expect($s->lastSuccessAt)->toBeGreaterThan($stale);
});

// The false-current failure this design exists to prevent.
test('changing the repository then failing leaves nothing to assert', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, 'W/"abc"'));

    [$code] = runCheck($this->repo, new ReleaseFetchException('offline'), slug: 'someone/else');

    expect($code)->toBe(Command::FAILURE);
    $s = $this->repo->read();
    expect($s->repo)->toBe('someone/else');    // new identity persisted
    expect($s->latestVersion)->toBeNull();     // old repo's data gone
    expect($s->etag)->toBeNull();
    expect($s->lastSuccessAt)->toBeNull();     // and no borrowed freshness
});

test('a changed repository never revalidates with the old etag', function (): void {
    runCheck($this->repo, ReleaseResult::release('v0.8.0', null, 'W/"abc"'));

    $spy = new class { public ?string $sentEtag = null; public int $calls = 0; };
    runCheck($this->repo, ReleaseResult::release('v1.0.0', null, null), $spy, slug: 'someone/else');

    expect($spy->sentEtag)->toBeNull();
});

test('an invalid slug fails loudly and performs no request', function (): void {
    $spy = new class { public ?string $sentEtag = null; public int $calls = 0; };
    [$code, $text] = runCheck($this->repo, ReleaseResult::release('v1.0.0', null, null), $spy, slug: '../evil');

    expect($code)->toBe(Command::FAILURE);
    expect($text)->toContain('not a valid owner/repo slug');
    expect($spy->calls)->toBe(0);
});

test('a disabled check performs no request and does not fail the job', function (): void {
    $spy = new class { public ?string $sentEtag = null; public int $calls = 0; };
    [$code] = runCheck($this->repo, ReleaseResult::release('v1.0.0', null, null), $spy, enabled: false);

    expect($code)->toBe(Command::SUCCESS);
    expect($spy->calls)->toBe(0);
});
