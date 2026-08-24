<?php

declare(strict_types=1);

use App\Shared\Version\SemVer;

// ── describe() suffix stripping ────────────────────────────────────────────

test('strips a git-describe suffix down to the base tag', function (): void {
    expect(SemVer::baseTag('v0.4.1-85-gb9d3407'))->toBe('v0.4.1');
});

test('strips a describe suffix that also carries -dirty', function (): void {
    expect(SemVer::baseTag('v0.4.1-85-gb9d3407-dirty'))->toBe('v0.4.1');
});

test('strips -dirty from an exact tag', function (): void {
    expect(SemVer::baseTag('v0.6.0-dirty'))->toBe('v0.6.0');
});

test('leaves a plain tag untouched', function (): void {
    expect(SemVer::baseTag('v0.6.0'))->toBe('v0.6.0');
});

// A real pre-release must survive: -m3 is part of the version, not describe noise.
test('keeps a genuine pre-release identifier', function (): void {
    expect(SemVer::baseTag('v0.3.0-m3'))->toBe('v0.3.0-m3');
});

test('keeps a pre-release while stripping the describe suffix around it', function (): void {
    expect(SemVer::baseTag('v0.3.0-m3-12-gdeadbee'))->toBe('v0.3.0-m3');
});

// ── exactness ──────────────────────────────────────────────────────────────

test('recognises an exact release tag', function (): void {
    expect(SemVer::isExactTag('v0.6.0'))->toBeTrue();
    expect(SemVer::isExactTag('v0.3.0-m3'))->toBeTrue();
});

test('a described or dirty build is not an exact tag', function (): void {
    expect(SemVer::isExactTag('v0.4.1-85-gb9d3407'))->toBeFalse();
    expect(SemVer::isExactTag('v0.6.0-dirty'))->toBeFalse();
    expect(SemVer::isExactTag('b9d3407'))->toBeFalse();
});

// ── parsing ────────────────────────────────────────────────────────────────

test('parses with and without the v prefix, in either case', function (): void {
    expect(SemVer::parse('v1.2.3'))->not->toBeNull();
    expect(SemVer::parse('1.2.3'))->not->toBeNull();
    expect(SemVer::parse('V1.2.3'))->not->toBeNull();
});

test('ignores build metadata', function (): void {
    expect(SemVer::compare('v1.2.3+build.5', 'v1.2.3'))->toBe(0);
});

test('rejects unparseable input rather than guessing', function (): void {
    expect(SemVer::parse('b9d3407'))->toBeNull();
    expect(SemVer::parse(''))->toBeNull();
    expect(SemVer::parse('not-a-version'))->toBeNull();
    expect(SemVer::parse('v1.2'))->toBeNull();
});

test('rejects leading zeroes, which are invalid semver', function (): void {
    expect(SemVer::parse('v01.2.3'))->toBeNull();
    expect(SemVer::parse('v1.02.3'))->toBeNull();
});

// ── comparison ─────────────────────────────────────────────────────────────

test('orders by major, minor, then patch', function (): void {
    expect(SemVer::compare('v0.6.0', 'v0.6.1'))->toBeLessThan(0);
    expect(SemVer::compare('v0.7.0', 'v0.6.9'))->toBeGreaterThan(0);
    expect(SemVer::compare('v1.0.0', 'v0.99.99'))->toBeGreaterThan(0);
    expect(SemVer::compare('v0.6.1', 'v0.6.1'))->toBe(0);
});

// The repo really carries v0.3.0-m3; it must sort below the final release.
test('a pre-release sorts below its own release', function (): void {
    expect(SemVer::compare('v0.3.0-m3', 'v0.3.0'))->toBeLessThan(0);
    expect(SemVer::compare('v0.3.0', 'v0.3.0-m3'))->toBeGreaterThan(0);
});

test('compares pre-release identifiers against each other', function (): void {
    expect(SemVer::compare('v0.3.0-m2', 'v0.3.0-m3'))->toBeLessThan(0);
    expect(SemVer::compare('v0.3.0-alpha', 'v0.3.0-beta'))->toBeLessThan(0);
});

test('numeric pre-release identifiers compare numerically, not as strings', function (): void {
    expect(SemVer::compare('v1.0.0-2', 'v1.0.0-10'))->toBeLessThan(0);
});

test('compare returns null when either side is unparseable', function (): void {
    expect(SemVer::compare('b9d3407', 'v0.6.1'))->toBeNull();
    expect(SemVer::compare('v0.6.1', 'garbage'))->toBeNull();
});

// ── the case that broke revision 1 of the design ───────────────────────────

test('a describe string compares by its base tag only', function (): void {
    // This checkout describes as v0.4.1-85-gb9d3407 while upstream is v0.6.1.
    expect(SemVer::compare(SemVer::baseTag('v0.4.1-85-gb9d3407'), 'v0.6.1'))->toBeLessThan(0);
});
