<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Identity of the running build, injected at image build time.
 *
 * Two kinds, deliberately kept apart (design D2a):
 *
 *  - **release** — stamped by CI from the tag it built. Authoritative, and the
 *    only kind allowed to be compared against upstream.
 *  - **source**  — derived from `git describe` in a local build. Displayed as
 *    a source build; it never claims to be current or behind, because the
 *    nearest *reachable* tag is not the newest tag in the repository, and
 *    comparing the two produces confident nonsense.
 *
 * Nothing is invented: an unstamped image reports `unknown`, never a version.
 */
final class BuildInfo
{
    private readonly string $raw;
    private readonly string $commit;
    private readonly string $kind;

    public function __construct(
        string $raw,
        string $commit = '',
        private readonly ?string $builtAt = null,
        string $kind = '',
    ) {
        $this->raw    = trim($raw);
        $this->commit = trim($commit);
        // Anything that is not literally "release" is treated as a source
        // build. Failing towards the weaker claim keeps a typo or a future
        // value from promoting a checkout into a release identity.
        $this->kind = match (trim($kind)) {
            'release' => 'release',
            default   => $this->raw === '' ? 'unknown' : 'source',
        };
    }

    /** @param array<string,mixed> $env */
    public static function fromEnv(array $env): self
    {
        // Values reaching here come from $_ENV/$_SERVER, so they are not
        // guaranteed to be strings — anything else is treated as absent.
        $get = static function (string $k) use ($env): string {
            $v = $env[$k] ?? '';
            return is_string($v) ? $v : '';
        };

        $date = $get('APP_BUILD_DATE');

        return new self(
            $get('APP_VERSION'),
            $get('APP_COMMIT'),
            $date === '' ? null : $date,
            $get('APP_BUILD_KIND'),
        );
    }

    public static function fromSuperglobals(): self
    {
        /** @var array<string,mixed> $env */
        $env = $_ENV + $_SERVER;
        foreach (['APP_VERSION', 'APP_COMMIT', 'APP_BUILD_DATE', 'APP_BUILD_KIND'] as $k) {
            if (!isset($env[$k]) || $env[$k] === '') {
                $fromGetenv = getenv($k);
                if (is_string($fromGetenv) && $fromGetenv !== '') {
                    $env[$k] = $fromGetenv;
                }
            }
        }
        return self::fromEnv($env);
    }

    /** The identity exactly as stamped, e.g. `v0.4.1-85-gb9d3407`. */
    public function raw(): string
    {
        return $this->raw;
    }

    /** The base tag for comparison — describe decoration removed. */
    public function tag(): string
    {
        return $this->raw === '' ? '' : SemVer::baseTag($this->raw);
    }

    public function commit(): string
    {
        return $this->commit;
    }

    public function builtAt(): ?string
    {
        return $this->builtAt;
    }

    /** `release` | `source` | `unknown` */
    public function kind(): string
    {
        return $this->kind;
    }

    public function isKnown(): bool
    {
        return $this->raw !== '';
    }

    /**
     * True only for a build that is itself a release tag. A `release` kind
     * whose version carries describe distance or `-dirty` is not one: CI can
     * mislabel, the version string cannot.
     */
    public function isRelease(): bool
    {
        return $this->kind === 'release' && SemVer::isExactTag($this->raw);
    }

    /** Whether the tag can take part in a semver comparison at all. */
    public function hasComparableTag(): bool
    {
        return $this->raw !== '' && SemVer::parse($this->tag()) !== null;
    }
}
