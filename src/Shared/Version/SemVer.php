<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * The single owner of version-string normalization and comparison.
 *
 * Two deliberately separate grammars:
 *
 *  1. git-describe — `<tag>-<N>-g<sha>[-dirty]`, plus a bare `<tag>-dirty`.
 *     Stripped by baseTag() so a checkout N commits past a tag compares as
 *     that tag.
 *  2. strict semver — `[vV]MAJOR.MINOR.PATCH[-prerelease][+build]`.
 *
 * Keeping them apart matters: `v0.3.0-m3` is a real pre-release that must
 * survive, while `v0.4.1-85-gb9d3407` is describe noise that must not.
 * Anything unparseable yields null — never a guess, never a verdict.
 */
final class SemVer
{
    /** `-<count>-g<sha>` with an optional trailing `-dirty`, anchored to the end. */
    private const DESCRIBE_SUFFIX = '/-\d+-g[0-9a-f]{4,40}(-dirty)?$/';

    /** A bare `-dirty` with no describe distance. */
    private const DIRTY_SUFFIX = '/-dirty$/';

    private const SEMVER = '/^[vV]?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';

    /**
     * Strip git-describe decoration, leaving the tag the build is based on.
     * A genuine pre-release identifier is preserved.
     */
    public static function baseTag(string $raw): string
    {
        $s = trim($raw);
        $s = (string)preg_replace(self::DESCRIBE_SUFFIX, '', $s);
        return (string)preg_replace(self::DIRTY_SUFFIX, '', $s);
    }

    /**
     * True when the string is itself a release tag — not a commit past one,
     * not a dirty tree. This is what separates a release build from a source
     * build; a source build never claims to be current or behind.
     */
    public static function isExactTag(string $raw): bool
    {
        $s = trim($raw);
        if ($s === '' || preg_match(self::DESCRIBE_SUFFIX, $s) === 1 || preg_match(self::DIRTY_SUFFIX, $s) === 1) {
            return false;
        }
        return self::parse($s) !== null;
    }

    /**
     * @return array{major:int,minor:int,patch:int,pre:string}|null
     */
    public static function parse(string $version): ?array
    {
        if (preg_match(self::SEMVER, trim($version), $m) !== 1) {
            return null;
        }
        return [
            'major' => (int)$m[1],
            'minor' => (int)$m[2],
            'patch' => (int)$m[3],
            'pre'   => $m[4] ?? '',
        ];
    }

    /**
     * -1 / 0 / 1, or null when either side cannot be parsed.
     */
    public static function compare(string $a, string $b): ?int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return null;
        }

        foreach (['major', 'minor', 'patch'] as $part) {
            if ($pa[$part] !== $pb[$part]) {
                return $pa[$part] <=> $pb[$part];
            }
        }

        return self::comparePre($pa['pre'], $pb['pre']);
    }

    /** Semver §11: a pre-release version sorts below its own release. */
    private static function comparePre(string $a, string $b): int
    {
        if ($a === $b)  return 0;
        if ($a === '')  return 1;
        if ($b === '')  return -1;

        $ida = explode('.', $a);
        $idb = explode('.', $b);

        foreach ($ida as $i => $x) {
            if (!isset($idb[$i])) {
                return 1; // a has more identifiers; the larger set wins
            }
            $y = $idb[$i];
            if ($x === $y) {
                continue;
            }

            $xNum = ctype_digit($x);
            $yNum = ctype_digit($y);
            if ($xNum && $yNum) {
                return (int)$x <=> (int)$y;   // numerically, so 2 < 10
            }
            if ($xNum !== $yNum) {
                return $xNum ? -1 : 1;        // numeric sorts below alphanumeric
            }
            return strcmp($x, $y) <=> 0;
        }

        return count($ida) <=> count($idb);
    }
}
