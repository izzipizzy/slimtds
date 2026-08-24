<?php

declare(strict_types=1);

use App\Shared\Version\BuildInfo;

test('a CI-stamped release build reports itself as a release', function (): void {
    $b = new BuildInfo('v0.7.0', 'b9d3407', '2026-08-24T10:00:00Z', 'release');

    expect($b->kind())->toBe('release');
    expect($b->isRelease())->toBeTrue();
    expect($b->tag())->toBe('v0.7.0');
    expect($b->commit())->toBe('b9d3407');
    expect($b->isKnown())->toBeTrue();
});

test('a local checkout build reports itself as a source build', function (): void {
    $b = new BuildInfo('v0.4.1-85-gb9d3407', 'b9d3407', null, 'source');

    expect($b->kind())->toBe('source');
    expect($b->isRelease())->toBeFalse();
    expect($b->raw())->toBe('v0.4.1-85-gb9d3407');
    expect($b->tag())->toBe('v0.4.1');           // describe noise stripped for comparison
});

// The whole point of D2a: a build kind of "release" is not enough on its own.
// If the version is not an exact tag, it is not a release, whatever CI claimed.
test('a release kind with a non-exact version is downgraded to source', function (): void {
    $b = new BuildInfo('v0.7.0-3-gabc1234', 'abc1234', null, 'release');
    expect($b->isRelease())->toBeFalse();
});

test('a dirty tree is never a release build', function (): void {
    $b = new BuildInfo('v0.7.0-dirty', 'b9d3407', null, 'release');
    expect($b->isRelease())->toBeFalse();
    expect($b->tag())->toBe('v0.7.0');
});

test('an empty version is unknown, not a guess', function (): void {
    $b = new BuildInfo('', '', null, '');

    expect($b->isKnown())->toBeFalse();
    expect($b->kind())->toBe('unknown');
    expect($b->isRelease())->toBeFalse();
    expect($b->tag())->toBe('');
});

test('a bare commit hash is a known build but has no comparable tag', function (): void {
    $b = new BuildInfo('b9d3407', 'b9d3407', null, 'source');

    expect($b->isKnown())->toBeTrue();
    expect($b->isRelease())->toBeFalse();
    expect($b->hasComparableTag())->toBeFalse();
});

test('an unrecognised build kind falls back to source, never to release', function (): void {
    $b = new BuildInfo('v0.7.0', 'b9d3407', null, 'nonsense');
    expect($b->kind())->toBe('source');
    expect($b->isRelease())->toBeFalse();
});

test('whitespace around injected values is tolerated', function (): void {
    $b = new BuildInfo("  v0.7.0\n", " b9d3407 ", null, ' release ');
    expect($b->tag())->toBe('v0.7.0');
    expect($b->commit())->toBe('b9d3407');
    expect($b->isRelease())->toBeTrue();
});

test('fromEnv reads the four build variables', function (): void {
    $b = BuildInfo::fromEnv([
        'APP_VERSION'    => 'v0.7.0',
        'APP_COMMIT'     => 'b9d3407',
        'APP_BUILD_DATE' => '2026-08-24T10:00:00Z',
        'APP_BUILD_KIND' => 'release',
    ]);

    expect($b->tag())->toBe('v0.7.0');
    expect($b->isRelease())->toBeTrue();
    expect($b->builtAt())->toBe('2026-08-24T10:00:00Z');
});

test('fromEnv on an empty environment yields an unknown build', function (): void {
    expect(BuildInfo::fromEnv([])->isKnown())->toBeFalse();
});

test('fromEnv tolerates a partially stamped environment', function (): void {
    $b = BuildInfo::fromEnv(['APP_VERSION' => 'v0.7.0']);

    expect($b->isKnown())->toBeTrue();
    expect($b->commit())->toBe('');
    expect($b->builtAt())->toBeNull();
    expect($b->kind())->toBe('source');   // no kind stamped → never a release
});
