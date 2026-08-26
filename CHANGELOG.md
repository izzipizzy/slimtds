# Changelog

Русская версия — [CHANGELOG.ru.md](CHANGELOG.ru.md).

This file is the machine-readable source for `scripts/publish.sh`: the section
of the version being released becomes the GitHub release body, and the release
section immediately below it names the predecessor whose tag the new commit
must be built on. Only versions that were actually published carry a
`## [x.y.z]` heading — everything that predates the public repository is listed
at the bottom under a plain heading the tooling skips.

## [0.7.2] — 2026-08-26

Two lines of development that had drifted apart are one line again.

The public releases were cut from a branch that development had since moved
away from, so this repository was missing work that had been shipping here for
weeks, and carrying work that had never shipped. Reconciling them is most of
this release. Version 0.7.1 existed only internally and was never published;
its changes are included below.

### Translations

The Russian and English catalogues are whole again — the pass that translated
the admin chrome, the filters, the empty states and the campaign workspace had
never reached the development line. Around three hundred lines per language.

### It no longer reports your visitors to someone else

`resources/views/layouts/public.php` carried a hard-coded beacon pointing at
one specific installation. That layout renders the login page, so any
deployment built from this source — including one you built yourself — sent
its visitors there. The tag is now driven by `PUBLIC_PIXEL_SRC` and emits
nothing when unset.

### Images stop carrying secrets

`.dockerignore` now keeps `.env` and a host `vendor/` out of the build context.
Baking the former put `APP_SECRET`, the database password and the MaxMind and
Telegram tokens into image layers, readable by anyone who could pull the image;
the latter shipped pest, phpstan and faker into production.

### Publishing

`scripts/publish.sh <x.y.z>` replaces the manual release ritual. It projects
the tree of the published commit minus `.publicignore`, commits it as a child
of the public tip, pushes branch and tag atomically, and cuts the release from
this file. `make release-publish VERSION=x.y.z [DRY=1]` is the wrapper;
`make test-publish` runs the pipeline's own suite.

That ritual is also why an internal design document shipped with 0.7.0. It is
excluded now and this release removes it; the 0.7.0 tag still carries it.

### Build identity

The four `APP_BUILD_*` values reach the image as build args, and the Makefile
is what exports them — but a production update runs `docker compose` directly,
on purpose, so nothing exported them there and the image baked empty strings.
The documented procedure now does, and `git describe` is restricted to release
tags: a `rollback/...` tag on a checkout was otherwise free to become the
reported version.

### Licensing

`LICENSE` and the licence declarations now say AGPL-3.0-or-later in the
development line too, matching what this repository has carried since its first
release.

### Also

A demo deploy mode with its own Caddyfile and a four-hourly reset, `db:seed-stats`,
and `cf_full` refusing to start rather than serving a plaintext origin while
claiming an encrypted one.

## [0.7.0] — 2026-08-24

The admin footer used to print `getenv('APP_VERSION') ?: 'v0.5.5'`. `APP_VERSION` was set nowhere in the repository, so the fallback always won and every deployment claimed a version that existed only in a template. This release makes the footer tell the truth.

### Build identity

Baked at image build time, in two distinct kinds:

- **release** — stamped by CI from the tag it built. The release workflow now fails the build if the tag or revision cannot be resolved.
- **source** — from `git describe` in a local build, shown as a source build with an `src` marker.

They are never mixed behind one variable. The nearest *reachable* tag is not the newest tag in a repository, so a source checkout comparing itself to upstream can confidently report "behind" for a build that has actually diverged. A source build therefore never claims to be current or behind.

### Update check

`version:check` runs twice daily and reads GitHub's `releases/latest` into a typed single-row `core.update_status`.

- Redirects disabled — PHP streams follow them by default and would forward the `Authorization` header to the target host.
- Every response field validated; `tag_name` must parse as semver; `html_url` is ignored. All URLs are built locally from a validated `owner/repo` slug against constant hosts, so the operator picks the repository but never the host that receives the token.
- `last_attempt_at` and `last_success_at` are separate, because one timestamp cannot mean both "when did we last try" and "how old is this answer".
- A failed attempt keeps the last good answer but exits non-zero, so `core.cron_runs` records it. Freshness ages out into a `stale` state rather than letting an old verdict stand forever.

### Footer

Five states with a strict precedence order. Only `behind` carries links — to the release page, plus a "how to update" link pinned to that release so the instructions match the version being offered. Status resolution never throws: the footer is decorative and must not be able to 500 an admin page.

### Upgrading

See [Updating an existing install](https://github.com/izzipizzy/slimtds/blob/v0.7.0/README.md#updating-an-existing-install). This release adds one migration (`core.update_status`) and three optional settings: `UPDATE_CHECK_ENABLED`, `UPDATE_CHECK_REPO`, `UPDATE_CHECK_TOKEN`. Checking is on by default and needs no token.

## [0.6.1] — 2026-08-23

Three bug fixes. No new features, no schema or migration changes.

### Production deploy modes could not start

`Caddyfile.cf` and `Caddyfile.direct` both used a one-line block:

```caddyfile
handle @static { file_server }
```

The Caddyfile grammar wants the first token of a block on the line after `{`, so `frankenphp adapt` rejected both. `docker/entrypoint.sh` hands the file to the parser unmodified, which means `cf_flex`, `cf_full` and `direct` exited on a config parse error — since the very first release. Only `dev` was unaffected, because `Caddyfile.dev` already used the multi-line form, which is why `make up` never showed it.

Both files now also carry a test that hands them to the real parser rather than asserting on their text.

### Switched-off offers still received traffic

`ClickHandler` picked from `flow.target_offers` and resolved the winner with `findById()`; neither looked at `is_active`. Turning an offer off in the admin UI did not stop it receiving visitors, and a malformed `offer_id` in the JSONB reached the `uuid` column and aborted the request with `22P02` rather than being skipped.

The pick now runs against the full candidate list and only the winner is verified, so sticky assignments survive an offer being switched off — narrowing the list first would have moved roughly half the visitors the change never touched. A flow that matched but has nothing routable falls through to the campaign trash mode, so the operator's configured fallback fires. `{offer:X}` references in `trash_url` resolve to active offers only.

### CI was red on every branch

`CurlProxySchemaTest` fetched a host that only resolves on the dev stack, and guarded the failure path with a bare `markTestSkipped()` — not a Pest global, so it raised `Call to undefined function` instead of skipping. The assertion is now deterministic on any runner.

Thanks to @klimenkoalex, who reported and reproduced all three.

## [0.6.0] — 2026-08-05

First tagged release of the public repository.

### Features

**Pixel: pageview-only mode (#2)** — landers that only need entry-source and pageview tracking can now skip rrweb session recording with `?rec=0` (or `data-rec="0"`) on the existing tag:

```html
<script src="https://tds.example/p.js?c=CAMPAIGN&rec=0"></script>
```

Fingerprint and pageview still fire. This is a mode of the one pixel, not a second script — `public/p.js` stays the only build artifact, and no Caddy or CORS change is involved.

**Clicks: entry-source attribution (#3)** — behind a server-side `go.php` redirect a click's own `Referer` is the lander itself, which makes genuine search traffic indistinguishable from direct. The pixel now records the visit's external entry referer on every pageview, and the clicks list can show and filter on it:

- a new **Entry source** column sits next to the raw `Referer` and never replaces it;
- the filter is opt-in — leaving it unset adds no predicate, so existing default views and query plans are unchanged;
- attribution takes the *earliest* external pixel event for the visitor, not the most recent one, which is typically an in-lander navigation that would mask the real source;
- exact `click_id` / `visitor` / `fp_js` lookups bypass the filter entirely, so postback audits always find their row.

**Clicks: per-operator saved default view (#3)** — pin the filters you actually work with as your own default, stored per admin in `core.user_view_prefs`. An explicit query string always wins, so a shared link shows the same rows to whoever opens it; `page`/`sort`/`dir` do not count as filters, so pagination stays inside the saved view.

### Fixes

**Flow filters: country accepts a list (#4)** — the country value input hardcoded `maxlength="2"` for a single ISO code, so choosing the *one of* / *not one of* operator silently truncated typing at the second character and the operator appeared broken. The cap is now conditional on the operator, with the expected format hinted in the placeholder.

### Migrations

Two, both additive and safe to apply online:

- `20260805000001_pixel_events_entry_referer` — adds `stats.pixel_events.entry_referer`
- `20260805000002_user_view_prefs` — creates `core.user_view_prefs`

Entry-source attribution is forward-looking: it reads the column the pixel populates, so clicks recorded before this release have no entry source.

### Verification

Full Pest suite (332 passed, 23 195 assertions) and PHPStan level 6 (no errors) against PostgreSQL 18 on an isolated test database.

## Before the public repository

These versions were tagged privately and never published to GitHub. They are
recorded here for continuity only, and deliberately carry no `## [x.y.z]`
heading: `scripts/publish.sh` reads those headings as the chain of public
release tags, and naming a tag that does not exist on GitHub would send it
looking for a parent commit that was never pushed.

- **v0.4.1** — 2026-05-30 — converted-row highlight in `/admin/clicks` and `/admin/pixel`.
- **v0.4.0** — 2026-04-25 — M4: offers became global (`core.offers.campaign_id` dropped, the campaign↔offer relationship derived from `flows.target_offers`), admin UI redesign, cross-domain pixel test harness.
- **v0.3.0-m3** — 2026-04-25 — M3: pixel, postbacks and the outgoing delivery outbox.
- **v0.2.0-m2** — 2026-04-25 — M2: admin panel, campaigns, offers, flows, filter compiler.
- **v0.1.0-m1** — 2026-04-25 — M1: engine hot path, response schemas, partitioned click stats.
