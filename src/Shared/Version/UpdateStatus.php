<?php

declare(strict_types=1);

namespace App\Shared\Version;

/**
 * Resolves the running build plus the stored check result into exactly one
 * state. Never throws: the footer is decorative, and a database hiccup must
 * not turn it into a 500 on every admin page. Every failure resolves to
 * `unknown`, which can only ever remove a claim, never manufacture one.
 */
final class UpdateStatus
{
    /** Three missed 12-hour cycles. */
    public const STALE_AFTER_SECONDS = 36 * 3600;

    private readonly ?RepoSlug $slug;

    public function __construct(
        private readonly BuildInfo $build,
        private readonly UpdateStateReader $reader,
        private readonly bool $enabled,
        string $repoSlug,
    ) {
        // An invalid slug disables checking rather than falling back to a
        // default, so a typo cannot silently point the check somewhere else.
        $this->slug = RepoSlug::tryParse($repoSlug);
    }

    /**
     * Precedence, first match wins. Order is the contract: overlapping
     * conditions must never produce two different answers.
     */
    public function resolve(?int $now = null): UpdateVerdict
    {
        $now   = $now ?? time();
        $label = $this->build->isKnown() ? $this->build->raw() : 'unknown';
        $mk    = fn (string $state, ...$rest): UpdateVerdict
            => new UpdateVerdict($state, $label, $this->build->commit(), ...$rest);

        // 1. Nothing was stamped into the image — we do not know what is running.
        if (!$this->build->isKnown()) {
            return $mk(UpdateVerdict::UNKNOWN);
        }

        // 2. Operator turned checking off, or the configured slug is invalid.
        if (!$this->enabled || $this->slug === null) {
            return $mk(UpdateVerdict::UNKNOWN);
        }

        // 3. Not a release build. This outranks every check-state condition:
        //    a source checkout can never produce a verdict, so classifying it
        //    by whether a check has run would say nothing useful. It also
        //    cannot lie — source_build claims neither current nor behind.
        if (!$this->build->isRelease() || !$this->build->hasComparableTag()) {
            return $mk(UpdateVerdict::SOURCE);
        }

        try {
            $state = $this->reader->read();
        } catch (\Throwable) {
            return $mk(UpdateVerdict::UNKNOWN);   // 4. read failed
        }

        // 5. Never checked, no usable data, or data belonging to a different
        //    repository — all of which mean we have nothing to assert.
        if ($state === null
            || $state->lastSuccessAt === null
            || !$state->hasRelease()
            || $state->repo !== (string)$this->slug
        ) {
            return $mk(UpdateVerdict::UNKNOWN);
        }

        $latest = (string)$state->latestVersion;
        $cmp    = SemVer::compare($this->build->tag(), $latest);
        if ($cmp === null) {
            return $mk(UpdateVerdict::UNKNOWN);
        }

        // 6. The answer is too old to stand behind.
        if (($now - $state->lastSuccessAt) > self::STALE_AFTER_SECONDS) {
            return $mk(UpdateVerdict::STALE, $latest, null, null, $state->lastSuccessAt, $state->publishedAt);
        }

        // 7. Something newer exists — the only linked state. The chip opens the
        //    release page (human-readable notes for that version); the guide
        //    link answers the next question, "how do I actually apply it".
        if ($cmp < 0) {
            return $mk(
                UpdateVerdict::BEHIND,
                $latest,
                $this->slug->releaseTagUrl($latest),
                $this->slug->updateGuideUrl($latest),
                $state->lastSuccessAt,
                $state->publishedAt,
            );
        }

        // 8. Checked recently, nothing newer seen. Note this means "no newer
        //    tagged release was observed", not "this is the canonical build".
        return $mk(UpdateVerdict::CURRENT, $latest, null, null, $state->lastSuccessAt, $state->publishedAt);
    }
}
