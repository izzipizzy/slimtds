# Version ticker + update check — Design Spec

**Date:** 2026-08-24
**Status:** Draft, revision 2 — reworked after adversarial review. NOT approved, NOT implemented.
**Branch:** `updater`

> Revision 2 replaces revision 1 after a two-agent review found a false factual premise and a
> mechanism that would have made the feature lie. The **Review findings** section at the end
> records what changed and why, including the criticisms that were rejected.

## Problem

The admin footer prints a version, and it lies:

```php
<?= e(getenv('APP_VERSION') ?: 'v0.5.5') ?>   // resources/views/layouts/admin.php:148
```

`APP_VERSION` is set nowhere in the repository, so the fallback always wins and every
deployment claims `v0.5.5`. The **same fabricated fallback also sits in
`resources/views/layouts/public.php:91`**, so the login page lies too.

Goal: tell the operator what build is actually running, and raise a persistent, non-modal
signal when a newer tagged release exists upstream.

## Decisions

| # | Decision | Choice |
|---|----------|--------|
| D1 | Upstream source | GitHub API on the mirror: `GET /repos/izzipizzy/slimtds/releases/latest`. Public, verified reachable, returns `v0.6.1`. |
| D2 | Comparison | semver of **release tags** only. Revised: a build that is not itself a release tag is labelled a source build and never claims to be current. See D2a. |
| D2a | Build identity | Two distinct kinds. **Release build** — identity injected by CI from the tag being built; authoritative. **Source build** — identity derived from `git describe` at build time and displayed as a source build. They are never mixed behind one variable. |
| D3 | Cadence | `17 4,16 * * *` via supercronic — twice daily. |
| D4 | Where shown | Footer of **both** layouts, permanently. Persistent chip when behind. One-shot toast after login. |
| D5 | Version plumbing | Baked `ARG`→`ENV` only. **No empty runtime overrides.** Dev override lives in `docker-compose.override.yml` with explicit values. |
| D6 | Storage | Typed single-row table `core.update_status` + migration. Not KV. |
| D7 | Failure posture | The command returns FAILURE on a failed attempt; noise is controlled by a staleness threshold, not by misreporting. |

### Why D2a exists

Revision 1 asserted that this branch describes as `v0.6.0`. It does not:

```
$ git describe --tags --always --dirty
v0.4.1-85-gb9d3407
$ git merge-base --is-ancestor v0.6.0 HEAD; echo $?
1
```

`v0.6.0` points at `b39d51e` on `feat/filters-pixel-country` and is **not an ancestor of
HEAD**. `git describe` returns the nearest *reachable* tag, which is not the newest tag in
the repository. Under revision 1 this checkout would have displayed `v0.4.1`, compared it
against upstream `v0.6.1`, and announced "two minors behind" for a build that has actually
diverged from the tag rather than fallen behind it.

The fix is not to be cleverer about parsing. It is to stop pretending a source checkout has
a release identity.

## Architecture

### 1. Build identity

**Release builds.** Both release workflows pass the tag and revision as build args:

```
--build-arg APP_VERSION=${TAG} --build-arg APP_COMMIT=${SHA} --build-arg APP_BUILD_DATE=${NOW}
--build-arg APP_BUILD_KIND=release
```

The build **fails** if the tag or revision cannot be resolved. A release image with no
identity is a defect, not a thing to paper over at runtime.

**Source builds.** `make build` / `make up` pass the same args with
`APP_BUILD_KIND=source` and `APP_VERSION=$(git describe --tags --always --dirty)`. Make
computes them on the host, where git exists, using target-specific exports.

`docker/Dockerfile`, runtime stage:

```dockerfile
ARG APP_VERSION=""
ARG APP_COMMIT=""
ARG APP_BUILD_DATE=""
ARG APP_BUILD_KIND=""
ENV APP_VERSION=$APP_VERSION APP_COMMIT=$APP_COMMIT \
    APP_BUILD_DATE=$APP_BUILD_DATE APP_BUILD_KIND=$APP_BUILD_KIND
```

One `ARG` per instruction — unlike `ENV`, `ARG` declares a single build argument, and the
multi-name form fails the build.

**No `environment:` entry for these keys in `docker-compose.yml` or the prod overlays.** A
Compose `environment` mapping overrides the image `ENV` *even when interpolation yields an
empty string*, so `APP_VERSION: ${APP_VERSION:-}` would erase a correctly stamped release
image whenever the stack is started through the documented path — and a stale `.env` would
make an image claim a version it does not contain. Development-only overrides go in
`docker-compose.override.yml`, which prod never loads.

**`cron` must carry the same identity as `app`.** Today `cron` declares only
`env_file: .env` and no `environment` block, so it would never have seen the override at
all — and it is the container that runs the check. Both services take identity from the
same image (`image: slimtds:local`); the migration adds an assertion that the resolved
Compose config gives both services the same values.

### 2. `src/Shared/Version/`

- **`BuildInfo`** — `fromEnv()`. Exposes `raw()`, `tag()`, `commit()`, `builtAt()`,
  `kind()` (`release` | `source` | `unknown`). Holds the raw string unchanged; it does no
  semver normalization at all — that belongs to one owner, `SemVer`.
- **`SemVer`** — the single normalization owner. Two separate, anchored grammars: a
  git-describe grammar (`<tag>-<N>-g<sha>[-dirty]`) and strict semver. Defined behaviour for
  `-dirty` on an exact tag, real pre-releases (`v0.3.0-m3`), build metadata, uppercase `V`,
  leading zeroes, and bare hashes. Unparseable input yields `null`, never a verdict.
- **`ReleaseFetcher`** (interface) + **`GithubReleaseFetcher`** — hardened, see §3.
- **`UpdateStatusRepository`** — typed access to `core.update_status` via
  `Shared\Db\Connection`.

### 3. Fetching, hardened

Revision 1 said "like `BotsUpdateCommand`". That is not an adequate model: it fetches a
plaintext blocklist and only distinguishes failure from a body. This endpoint returns JSON
that drives a link rendered in the admin UI, and may carry a token.

Required:

- HTTP 200 only; explicit handling of 401, 403, 404, 429 and 5xx.
- **Redirects disabled** (`follow_location => 0`). PHP streams follow redirects by default
  and would forward an `Authorization` header to the redirect target.
- Bounded response size; 8s timeout.
- `json_decode(..., flags: JSON_THROW_ON_ERROR)`, then validation of every field's presence
  and type.
- `tag_name` must parse through `SemVer` or the response is rejected.
- The release URL is **constructed locally** from the validated tag, not taken from
  `html_url`. Nothing remote reaches an `href`.
- `ETag` / `If-None-Match` to stay inside the 60 req/h unauthenticated budget.
- Errors are stored sanitized and length-bounded; query strings and headers are stripped so
  a token can never land in the database or the cron log.

### 4. Storage

New migration, single-row table `core.update_status`:

`channel` (PK, always `github`, `CHECK` constrained — this is what enforces the single row),
`repo`, `latest_version`, `latest_url`, `published_at`, `last_attempt_at`, `last_success_at`,
`last_error`, `etag`, `updated_at`.

`repo` stores the slug the persisted data was fetched from. Without it a fresh cron process
cannot tell whether the stored validator and release data belong to the repository currently
configured; on a config change it would send another repo's `If-None-Match`, take a 304, and
keep serving the old repo's version forever.

`etag` exists because §3 requires conditional requests and every cron run is a fresh PHP
process — an in-memory validator cannot survive between checks. Rules: store the response
`ETag` only as part of the same atomic write that stores validated 200 data, so a validator
is never separated from the representation that produced it; send it as `If-None-Match` on
the next attempt **only when the stored `repo` equals the configured slug**; on 304 keep all
release fields untouched and advance `last_attempt_at` and `last_success_at`. When the stored
`repo` differs from the configured one, one transaction runs **before** the request and
writes the new slug into `repo` while clearing `etag`, every release field, `last_error`,
**and `last_success_at`**.

Clearing `last_success_at` is the part that matters. Leaving it behind means a repository
change followed by a failed request keeps a recent success timestamp that belongs to the
*previous* repository — the freshness check passes, precedence falls through to
`release_current`, and the footer asserts a verdict for data it no longer has. That is
exactly the false-current failure this feature exists to eliminate. As a second line of
defence, state resolution treats "no `last_success_at`" and "release fields empty" as
`unknown` regardless of what else is set.

Reasons for a table over five `core.settings` keys: this is observed operational state, not
operator configuration; five independent upserts are five transactions, so a reader can see
a new tag beside an old URL; and `last_attempt_at` / `last_success_at` must be distinct —
revision 1 had a single `checked_at` that was asked to mean both. The whole row is written
in one UPSERT, and the check holds a Postgres advisory lock so two runners cannot interleave.

This also removes revision 1's awkward `UpdateStateStore`, which existed only to reach
`core.settings` from `Shared` without depending on `Admin`.

### 5. Read path and rendering

`View::render()` merges only the `$data` it is given; there is no layout-global mechanism,
so "the footer calls `UpdateStatus::current()`" was not implementable as written — a static
call cannot obtain a `Connection`, and editing every controller is not acceptable.

`View` gains an injected `UpdateStatus` service and merges a small set of stable layout
globals during render. Registered explicitly in `config/di.php`, per the project convention.

**`UpdateStatus` never throws at the `View` boundary.** The footer is decorative; a database
hiccup must not turn it into a 500 on every admin page. Every repository and APCu-free read
path is wrapped, and any failure resolves to `unknown`. This is fail-closed: an error can
only ever *remove* a claim, never manufacture one.

**The toast is owned by `LoginController`, not by the layout.** Layout globals alone would
re-render the toast on every page. After successful authentication, before the redirect,
`LoginController` resolves the state and calls `flash_push('info', …)` with an i18n string
only when the state is `behind`; `_partials/toast-host.php` already converts info flashes to
toasts. When the login redirects to the forced password change, the flash is still queued and
surfaces on the page that follows. A failure while resolving the state queues nothing — a
login must never fail because an update check could not be read.

**State precedence**, applied top to bottom, first match wins — so overlapping conditions
cannot yield contradictory verdicts:

1. no build identity (`APP_VERSION` empty) → `unknown`
2. checking disabled → `unknown`
3. state read failed → `unknown`
4. never checked successfully → `unknown`
5. build kind is not `release`, or the version is not an exact release tag → `source_build`
6. `last_success_at` older than 36h → `stale`
7. upstream tag greater than local tag → `behind`
8. otherwise → `release_current`

Note that `source_build` outranks `stale` and `behind`: a source checkout has no release
identity to compare, so it must not be told it is behind.

**APCu is dropped.** It is process-local: FrankenPHP worker processes and the cron process
each hold their own copy, so a completed check cannot invalidate the web workers and two
requests could disagree for up to 300s. For one indexed single-row read on a page that
already queries the database, the cache buys nothing and costs correctness.

### 6. States

| State | Meaning | UI |
|---|---|---|
| `release_current` | release build, no newer tag found | version + commit, no chip |
| `behind` | release build, upstream tag is greater | version + commit + terracotta chip `↑ v0.7.0`, linked |
| `source_build` | not a release tag (`git describe` distance, or dirty) | version + commit + muted grey chip `src`, not linked |
| `stale` | last success older than 3 missed cycles (36h) | version + commit + muted grey chip `stale?`, not linked |
| `unknown` | no identity, disabled, or never checked | `unknown`, no chip |

`source_build` and `stale` carry their own explicit markers rather than relying on the
absence of the terracotta chip. Absence already means "no newer tagged release was
observed", and one signal cannot carry three meanings.

### Where the chip links

The `behind` chip opens GitHub so the operator can read **what actually changed**:

```
https://github.com/<repo>/compare/<local-tag>...<latest-tag>
```

The compare view is the honest answer to "what changed" — it spans every release in between,
not just the newest one. It is the **only** linked form: per the precedence list, `behind`
is reachable only from a release build with an exact local tag, so a "no local tag" fallback
would be dead code. `source_build` and `stale` chips are not links at all.

Both path segments come from tags already validated by `SemVer`, and `<repo>` from the
validated slug below; nothing from the API response ever reaches an `href`.

One accepted consequence, inherited from Risk 1: if the local release tag was never pushed
to the mirror, the compare URL 404s. Detecting that would cost an extra API call to answer a
question the operator can see immediately, and the real fix is pushing the tag.

The wording matters: absence of a chip means *no newer tagged release was observed*, not
"you are running the canonical artifact". A GitHub Release object is not proof that a
deployable image was published — that gap is documented, not hidden.

### 7. Cron

`version:check`, scheduled `17 4,16 * * *`.

Returns **FAILURE** on a failed attempt so `CronJournal` records a non-zero `exit_code` in
`core.cron_runs` — the mechanism the repo already has for exactly this. Revision 1's
always-SUCCESS posture would have made a checker that had been broken for months look
healthy while the footer kept asserting a verdict. Noise is controlled instead by the
`stale` state and by alerting only after consecutive failures. The last successful result is
always preserved; only the verdict's freshness claim expires.

## Testing

Unit: `SemVer` grammar table (describe suffix, `-dirty` on an exact tag, `v0.3.0-m3`,
build metadata, uppercase `V`, leading zeroes, bare hash, garbage); `BuildInfo` on
empty/partial env and each `kind`; state resolution for all five states with a stubbed
**`UpdateStatusRepository`** (revision 1 wrongly stubbed `ReleaseFetcher` here — `UpdateStatus`
never touches the fetcher).

Fetcher: stubbed transport for 200/401/403/404/429/5xx, redirect attempts, oversized bodies,
invalid JSON, missing and wrongly-typed fields, hostile `html_url`, ETag 304.

Integration (`db-test`): the command writes the row atomically; a failed attempt preserves
prior data, sets `last_error`, updates `last_attempt_at` but not `last_success_at`, and exits
non-zero; a recovered check clears `last_error`.

Precedence: one case per row of the precedence list **plus** the overlaps that motivated it —
disabled *and* stale, source build *and* upstream ahead, never-checked *and* no identity —
asserting the single winning state, not merely that each state is reachable.

ETag: a 200 stores the validator alongside `repo`; a second command instance in a fresh
process sends it and handles 304 by preserving release data while advancing both timestamps;
a changed `UPDATE_CHECK_REPO` clears the validator and release fields *before* the request
rather than revalidating against the new repository. **The case that must not regress:**
the repo changes and the very next request fails — assert `unknown`, no surviving
`last_success_at`, no surviving release data, and the new slug persisted.

Configuration: valid and invalid `UPDATE_CHECK_REPO` values — `./repo`, `../repo`,
`owner/.`, `owner/..`, a slug with an extra path segment, a full URL, a bare host, empty,
over-long. An invalid value performs no request and yields `unknown`; accepted slugs are
asserted against the exact final request path; the API host is unaffected by any configured
value.

Rendering: both layouts in every state; a repository that throws still renders the page and
degrades to `unknown`; the compare URL is built from validated tags and the validated slug and
is correctly escaped; the `behind` chip links, the `src` and `stale?` chips do not.

Login: the toast is queued once on successful authentication in the `behind` state, does not
reappear on the next page load, reappears on a second login, is not queued in any other
state, and a throwing status read still lets the login complete.

Deployment: `docker compose config` matrix (unset / `.env` / Make-supplied / prebuilt release
image) asserting a release image's identity is never erased and that `app` and `cron` resolve
identically; Dockerfile ARG/ENV assertions; release-workflow build-arg assertions; exact
crontab schedule.

## Risks and accepted trade-offs

1. **GitHub is the mirror, Gitea is primary (D1, operator's choice).** A release tagged in
   Gitea but not mirrored leaves installations reporting no update, indefinitely. Tagging the
   mirror is therefore a release requirement. Accepted knowingly.
2. **A release object is not a deployable image.** The check proves a tag exists, not that
   the image was pushed. The UI wording reflects this rather than overstating it.
3. **New outbound dependency** on `api.github.com`, twice daily. `UPDATE_CHECK_ENABLED=0`
   disables it for air-gapped installs; disabled renders as `unknown`, not as current.
4. **Shallow clones** without tags produce a bare hash → `source_build`, never a false verdict.

## Configuration

`UPDATE_CHECK_ENABLED` (default on, strict boolean parsing), `UPDATE_CHECK_REPO` (default
`izzipizzy/slimtds`), `UPDATE_CHECK_TOKEN` (optional, never logged or persisted). All three
added to `.env.example` with defaults and documented in `docs/DEPLOYMENT.md`.

**`UPDATE_CHECK_REPO` is a slug, not a URL.** It is split on the single `/` and **each
segment is validated separately** against `^[A-Za-z0-9._-]{1,100}$`, with `.` and `..`
rejected outright. A character-class regex alone is not enough: it happily accepts
`owner/..`, and percent-encoding does not neutralise dot segments — a dot stays a dot in a
normal path, so `..` could still normalise the request away from
`/repos/<owner>/<repo>/releases/latest`. Validation happens before any request is made, and
an invalid value disables checking and resolves to `unknown` rather than falling back to the
default, so a typo cannot silently redirect the check somewhere else.

The origins are **constants in code**, never configuration: `https://api.github.com` for the
request and `https://github.com` for the link. This is the credential boundary — the operator
chooses which repository is queried, never which host receives `UPDATE_CHECK_TOKEN`. The
validated slug is percent-encoded per path segment, and the same value feeds both the API
endpoint and the compare URL, so the link can never point at a different repository than the
one that was checked.

## Review findings — what changed and what was rejected

Reviewed by two independent Codex agents (implementation reviewer + architecture critic),
read-only, against this repository. Every finding below was re-verified against the code
before being accepted.

**Accepted and fixed:** false `v0.6.0` premise (D2a); Compose empty-string override erasing
baked identity (§1); `cron` never receiving the identity (§1); missing `public.php:91`
fallback (Problem); no layout-global mechanism in `View` (§5); always-SUCCESS hiding failures
from `core.cron_runs` (§7); no staleness expiry (§6); unvalidated remote JSON, redirect token
leak, trusted `html_url` (§3); torn writes across five KV keys and the overloaded `checked_at`
(§4); contradictory normalization ownership between `BuildInfo` and `SemVer` (§2); the
`UpdateStatus`/`ReleaseFetcher` stub contradiction and thin coverage (Testing); undocumented
env vars (Configuration); process-local APCu (§5).

**Rejected — login toast.** The critic called it complexity without benefit. It is an
explicit product requirement from the operator, not a defect.

**Rejected in part — the seven-state model.** Collapsing every condition into three states
was genuinely wrong, and five states fix that. Modelling "diverged", "ahead", "rebuilt tag"
and "digest mismatch" separately is beyond what a footer chip can express and would require
release-manifest infrastructure this project does not have.

**Deferred — publishing a release manifest from CI** so the check compares against an
artifact that provably exists. This is the critic's strongest remaining point. It is a change
to the release pipeline, not to this feature, and belongs in its own spec.

## Release

This feature ships as **`v0.7.0`** — a minor bump: new operator-visible capability, a new
table, new configuration, no breaking change to existing behaviour.

Tagging it is also the feature's own first real test. `v0.7.0` must be pushed to **both**
remotes — `origin` (Gitea, primary) and `github` (the mirror the checker reads, per D1) —
because an unmirrored tag is exactly the silent-false-current failure recorded in Risk 1. The
release workflow then stamps the image with `APP_BUILD_KIND=release`, and a deployment of
that image should report `release_current` against upstream `v0.7.0`. Anything else means the
identity plumbing in §1 is wrong.

Sequence: implement → tests green → tag `v0.7.0` on both remotes → deploy → confirm the
footer shows `v0.7.0` with no chip. Only then is the feature verified end to end.

## Open questions

- Is a manual "check now" control wanted, given there is no settings block in this design?
