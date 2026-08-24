<?php

declare(strict_types=1);

use App\Shared\Version\RepoSlug;

test('accepts a plain owner/repo slug', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s)->not->toBeNull();
    expect($s->owner())->toBe('izzipizzy');
    expect($s->repo())->toBe('slimtds');
    expect((string)$s)->toBe('izzipizzy/slimtds');
});

test('accepts dots, dashes and underscores', function (): void {
    expect(RepoSlug::tryParse('some-org/my_repo.js'))->not->toBeNull();
});

test('trims surrounding whitespace', function (): void {
    expect((string)RepoSlug::tryParse('  izzipizzy/slimtds  '))->toBe('izzipizzy/slimtds');
});

// ── rejection: this is the credential boundary ─────────────────────────────

test('rejects dot segments that could normalise the path away', function (): void {
    expect(RepoSlug::tryParse('./repo'))->toBeNull();
    expect(RepoSlug::tryParse('../repo'))->toBeNull();
    expect(RepoSlug::tryParse('owner/.'))->toBeNull();
    expect(RepoSlug::tryParse('owner/..'))->toBeNull();
});

test('rejects anything that is not exactly two segments', function (): void {
    expect(RepoSlug::tryParse('owner'))->toBeNull();
    expect(RepoSlug::tryParse('owner/repo/extra'))->toBeNull();
    expect(RepoSlug::tryParse('owner/'))->toBeNull();
    expect(RepoSlug::tryParse('/repo'))->toBeNull();
});

test('rejects a URL or a bare host, which is how a token would leak', function (): void {
    expect(RepoSlug::tryParse('https://evil.example/owner/repo'))->toBeNull();
    expect(RepoSlug::tryParse('evil.example'))->toBeNull();
    expect(RepoSlug::tryParse('//evil.example/repo'))->toBeNull();
    expect(RepoSlug::tryParse('owner/repo@evil.example'))->toBeNull();
});

test('rejects empty and over-long values', function (): void {
    expect(RepoSlug::tryParse(''))->toBeNull();
    expect(RepoSlug::tryParse('   '))->toBeNull();
    expect(RepoSlug::tryParse(str_repeat('a', 101) . '/repo'))->toBeNull();
    expect(RepoSlug::tryParse('owner/' . str_repeat('b', 101)))->toBeNull();
});

test('rejects characters that have meaning inside a URL', function (): void {
    foreach (['owner/re?po', 'owner/re#po', 'owner/re po', "owner/re\npo", 'ow:ner/repo'] as $bad) {
        expect(RepoSlug::tryParse($bad))->toBeNull();
    }
});

// ── URL construction ───────────────────────────────────────────────────────

test('builds the API url on the fixed api host', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s->apiLatestReleaseUrl())
        ->toBe('https://api.github.com/repos/izzipizzy/slimtds/releases/latest');
});

test('builds the compare url on the fixed web host', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s->compareUrl('v0.6.1', 'v0.7.0'))
        ->toBe('https://github.com/izzipizzy/slimtds/compare/v0.6.1...v0.7.0');
});

test('builds the update-guide url pinned to the release', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s->updateGuideUrl('v0.7.0'))
        ->toBe('https://github.com/izzipizzy/slimtds/blob/v0.7.0/README.md#updating-an-existing-install');
    expect($s->updateGuideUrl('not a tag'))->toBeNull();
});

test('builds the release page url', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s->releaseTagUrl('v0.7.0'))
        ->toBe('https://github.com/izzipizzy/slimtds/releases/tag/v0.7.0');
});

// The host is a constant, never configuration — the operator chooses which
// repository is queried, never which host receives the token.
test('no configured value can move the request off api.github.com', function (): void {
    foreach (['izzipizzy/slimtds', 'a/b', 'some-org/my_repo.js'] as $slug) {
        expect(RepoSlug::tryParse($slug)->apiLatestReleaseUrl())
            ->toStartWith('https://api.github.com/repos/');
    }
});

test('a tag that is not valid semver never reaches a url', function (): void {
    $s = RepoSlug::tryParse('izzipizzy/slimtds');
    expect($s->compareUrl('../../evil', 'v0.7.0'))->toBeNull();
    expect($s->releaseTagUrl('not a tag'))->toBeNull();
});
