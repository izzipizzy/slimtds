# Testing

slimTDS tests run against an isolated `slimtds_test` database (separate Postgres container on tmpfs, under the `test` compose profile). The dev DB is never touched by the suite.

## Suites

| Suite | Count | What it covers | DB? |
|---|---:|---|---|
| Unit | 195 | Pure functions: FilterCompiler, OfferPicker, MacroExpander, CampaignIdGenerator, etc. | no |
| Integration | 422 | DB-backed: repositories, controllers (PSR-7), engine pipeline, postback, pixel events, rate-limiter, sessions | yes (db-test) |
| Arch | 9 | pest-plugin-arch invariants: layer boundaries, naming, strict_types, no `dd`/`var_dump` | no |
| Browser | 12 (opt-in) | Pest 5 + Playwright Chromium against the running dev stack | no (talks to live stack) |

## Quick start

```bash
make test               # unit + integration + arch — ~10 seconds
make test-unit          # unit only
make test-integration   # integration only
make test-arch          # arch only
```

The `make test` target brings up `db-test` (idempotent), runs migrations and `partitions:rotate` against it, then runs the suites. `db-test` uses `tmpfs` storage so data is reset on container restart.

## Run a specific file or filter

```bash
make test-up   # ensure db-test is up + migrated
docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
  app ./vendor/bin/pest tests/Integration/Admin/OfferRepositoryTest.php
docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
  app ./vendor/bin/pest --filter='cross-campaign'
```

## Browser tests

The browser suite is gated by `BROWSER_TESTS=1` and needs Playwright Chromium. Set `BROWSER_BASE_URL` and `TEST_PG_DSN` explicitly. The target app must use that same disposable database: browser fixtures delete campaigns/offers/clicks and reset administrator passwords. Never point these tests at the development database restored from production. Optional `BROWSER_LANDER_URL_PATTERN` selects the four landers (e.g. `http://lander-%s`). Browser runs without explicit targets fail before fixture cleanup.

The 2026-10-04 validation used a Debian PHP 8.5 test container with Node 24, Playwright 1.63 Chromium and its system dependencies, an isolated app attached to `db-test`, and four nginx landers. Alpine runtime images do not supply Playwright's supported glibc browser dependencies. There are two ways to run browser tooling:

### Option A — supported Docker browser tooling

```bash
docker build -f docker/Dockerfile.browser-tests -t slimtds:browser-tests .
# The target app must be attached to db-test and the landers must use its pixel URL.
docker run --rm --init --network YOUR_TEST_NETWORK \
  -e BROWSER_TESTS=1 -e BROWSER_BASE_URL=http://app-test \
  -e TEST_PG_DSN='pgsql:host=db-test;port=5432;dbname=slimtds_test' \
  -e BROWSER_LANDER_URL_PATTERN='http://lander-%s' \
  -v "$PWD:/app" -w /app slimtds:browser-tests \
  php vendor/bin/pest --testsuite=Browser
```

This image includes Chromium, Node, PHP extensions and the supported browser system libraries. The checkout must already have Composer dev dependencies and Bun dependencies installed.

### Option B — Playwright on the host (recommended for development)

```bash
cd /tmp
npm install playwright
npx playwright install chromium
node /tmp/pixel-test.mjs   # see below
```

Use this when you want fast iteration on browser-driven scripts without rebuilding the container.

## Pixel cross-domain test

`tests/Browser/PixelCrossDomain.test.php` exercises the full cross-domain pixel pipeline:

1. Visit `https://lander-{a,b,c,d}.local/` (4 distinct OrbStack-hosted domains)
2. Page-load auto-fires `pageview` via `<script async src="https://slimtds.local/p.js?c=demo01">`
3. Click internal nav → next page; `view_about` / `view_pricing` events fire on subsequent pages
4. Walk back home, click button to fire a custom event (`purchase`/`signup`/etc.)
5. Inspect `stats.pixel_events` to assert: campaign attribution, referrer chain (`https://lander-x.local/about` → `/pricing`), FingerprintJS visitor stability across origins

To run:

```bash
make pixel-test-up    # start lander-{a,b,c,d}.local services (OrbStack auto-HTTPS)
make test-browser
make pixel-test-down  # tear them down when done
```

For ad-hoc verification without Pest:

```bash
node /tmp/pixel-test.mjs
```

The script disables `navigator.sendBeacon` (forces fetch fallback so postData is JSON-readable by Playwright), walks every lander × every page, captures every POST to `/p/event`, and prints status + payload + referrer per event.

## Why a separate DB?

Pre-M3, the suite ran against the dev DB and cleaned `core.admins`/`core.campaigns`/`stats.clicks` in `beforeEach`, wiping operator state. M3 routes tests to `db-test` (tmpfs storage, gone on container restart) so dev data is safe.

If a test ever needs to inspect dev data, point `DB_DSN` explicitly:

```bash
docker compose exec -e 'DB_DSN=pgsql:host=db;port=5432;dbname=slimtds' \
  app ./vendor/bin/pest tests/Integration/...
```

But this is rarely the right thing — prefer fixtures inside the test that reproduce the dev state.

## CI

GitHub Actions runs `unit + integration + arch` on every push. Browser tests are not run in CI (no Chromium in the runner image). The pipeline is `.github/workflows/ci.yml`.
