<?php

declare(strict_types=1);

use App\Shared\Version\BuildInfo;
use App\Shared\Version\UpdateState;
use App\Shared\Version\UpdateStateReader;
use App\Shared\Version\UpdateStatus;

const NOW  = 1_800_000_000;
const HOUR = 3600;

function reader(?UpdateState $state, bool $throws = false): UpdateStateReader
{
    return new class ($state, $throws) implements UpdateStateReader {
        public function __construct(private ?UpdateState $s, private bool $throws) {}

        public function read(): ?UpdateState
        {
            if ($this->throws) {
                throw new RuntimeException('database is down');
            }
            return $this->s;
        }
    };
}

/** @param array<string,mixed> $over */
function state(array $over = []): UpdateState
{
    return new UpdateState(
        repo:          $over['repo'] ?? 'izzipizzy/slimtds',
        // array_key_exists, not ??: an explicit null is the case under test.
        latestVersion: array_key_exists('latestVersion', $over) ? $over['latestVersion'] : 'v0.8.0',
        latestUrl:     $over['latestUrl']     ?? null,
        publishedAt:   $over['publishedAt']   ?? '2026-09-02T10:00:00Z',
        lastAttemptAt: $over['lastAttemptAt'] ?? NOW - HOUR,
        lastSuccessAt: array_key_exists('lastSuccessAt', $over) ? $over['lastSuccessAt'] : NOW - HOUR,
        lastError:     $over['lastError']     ?? null,
    );
}

function status(BuildInfo $b, ?UpdateState $s, bool $enabled = true, bool $throws = false): UpdateStatus
{
    return new UpdateStatus($b, reader($s, $throws), $enabled, 'izzipizzy/slimtds');
}

$release = fn (string $v = 'v0.7.0') => new BuildInfo($v, 'b9d3407', null, 'release');
$source  = fn () => new BuildInfo('v0.4.1-85-gb9d3407', 'b9d3407', null, 'source');

// ── the five states ────────────────────────────────────────────────────────

test('release build with nothing newer is current', function () use ($release): void {
    $v = status($release(), state(['latestVersion' => 'v0.7.0']))->resolve(NOW);
    expect($v->state)->toBe('release_current');
    expect($v->chipUrl)->toBeNull();
});

test('release build with a greater upstream tag is behind', function () use ($release): void {
    $v = status($release(), state(['latestVersion' => 'v0.8.0']))->resolve(NOW);
    expect($v->state)->toBe('behind');
    expect($v->latestVersion)->toBe('v0.8.0');
});

test('a build ahead of upstream is current, not behind', function () use ($release): void {
    $v = status($release('v0.9.0'), state(['latestVersion' => 'v0.8.0']))->resolve(NOW);
    expect($v->state)->toBe('release_current');
});

test('a source build is a source build', function () use ($source): void {
    expect(status($source(), state())->resolve(NOW)->state)->toBe('source_build');
});

test('a stale check no longer asserts a verdict', function () use ($release): void {
    $v = status($release(), state(['lastSuccessAt' => NOW - 37 * HOUR]))->resolve(NOW);
    expect($v->state)->toBe('stale');
});

test('a check just inside the window still counts as fresh', function () use ($release): void {
    $v = status($release(), state(['lastSuccessAt' => NOW - 35 * HOUR]))->resolve(NOW);
    expect($v->state)->toBe('behind');
});

// ── everything that must resolve to unknown ────────────────────────────────

test('no build identity is unknown', function (): void {
    $v = status(new BuildInfo(''), state())->resolve(NOW);
    expect($v->state)->toBe('unknown');
    expect($v->versionLabel)->toBe('unknown');
});

test('checking disabled is unknown, never current', function () use ($release): void {
    expect(status($release(), state(), enabled: false)->resolve(NOW)->state)->toBe('unknown');
});

test('a failed state read degrades to unknown instead of throwing', function () use ($release): void {
    expect(status($release(), null, throws: true)->resolve(NOW)->state)->toBe('unknown');
});

test('never checked is unknown', function () use ($release): void {
    expect(status($release(), null)->resolve(NOW)->state)->toBe('unknown');
    expect(status($release(), state(['lastSuccessAt' => null]))->resolve(NOW)->state)->toBe('unknown');
});

test('a success timestamp without release data is still unknown', function () use ($release): void {
    $v = status($release(), state(['latestVersion' => null]))->resolve(NOW);
    expect($v->state)->toBe('unknown');
});

// This is the false-current failure the design exists to prevent: a repo
// change followed by a failed request must not leave the old verdict standing.
test('data belonging to a different repository is never used', function () use ($release): void {
    $v = status($release(), state(['repo' => 'someone/else']))->resolve(NOW);
    expect($v->state)->toBe('unknown');
});

test('an unparseable upstream tag yields unknown, not a verdict', function () use ($release): void {
    expect(status($release(), state(['latestVersion' => 'garbage']))->resolve(NOW)->state)->toBe('unknown');
});

// ── overlaps: exactly one state must win ───────────────────────────────────

test('disabled outranks stale', function () use ($release): void {
    $v = status($release(), state(['lastSuccessAt' => NOW - 99 * HOUR]), enabled: false)->resolve(NOW);
    expect($v->state)->toBe('unknown');
});

test('a source build outranks an upstream that is ahead', function () use ($source): void {
    $v = status($source(), state(['latestVersion' => 'v9.9.9']))->resolve(NOW);
    expect($v->state)->toBe('source_build');
});

test('a source build outranks staleness', function () use ($source): void {
    $v = status($source(), state(['lastSuccessAt' => NOW - 99 * HOUR]))->resolve(NOW);
    expect($v->state)->toBe('source_build');
});

test('no identity outranks everything, including a source kind', function (): void {
    $v = status(new BuildInfo('', '', null, 'source'), state())->resolve(NOW);
    expect($v->state)->toBe('unknown');
});

// ── what the footer renders ────────────────────────────────────────────────

test('the version is shown whenever the build is known, whatever the state', function () use ($source): void {
    $v = status($source(), null)->resolve(NOW);   // never checked → unknown state
    expect($v->state)->toBe('source_build');
    expect($v->versionLabel)->toBe('v0.4.1-85-gb9d3407');
    expect($v->commit)->toBe('b9d3407');
});

test('behind links to the release page and to the update guide', function () use ($release): void {
    $v = status($release('v0.7.0'), state(['latestVersion' => 'v0.8.0']))->resolve(NOW);

    // The chip opens the release notes for that version…
    expect($v->chipUrl)->toBe('https://github.com/izzipizzy/slimtds/releases/tag/v0.8.0');
    // …and the guide answers "how do I apply it", pinned to that same release
    // so the steps are the ones that shipped with it.
    expect($v->guideUrl)->toBe('https://github.com/izzipizzy/slimtds/blob/v0.8.0/README.md#updating-an-existing-install');
});

test('only behind carries links', function () use ($release, $source): void {
    foreach ([
        status($source(), state())->resolve(NOW),
        status($release(), state(['lastSuccessAt' => NOW - 99 * HOUR]))->resolve(NOW),
        status($release(), state(['latestVersion' => 'v0.7.0']))->resolve(NOW),
    ] as $v) {
        expect($v->chipUrl)->toBeNull();
        expect($v->guideUrl)->toBeNull();
    }
});

test('an invalid configured slug disables checking rather than falling back', function () use ($release): void {
    $s = new UpdateStatus($release(), reader(state()), true, 'not-a-slug');
    expect($s->resolve(NOW)->state)->toBe('unknown');
});
