# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

slimTDS — Slim 4 + FrankenPHP (worker mode) + PostgreSQL 18 traffic distribution system. Self-hosted, single-tenant, internal tool. Modern rewrite of zTDS v0.8.4. Stack: PHP 8.4, Pest 4, Phinx, PHP-DI, Tailwind 4 + Alpine.js + ECharts, FingerprintJS CE pixel.


## Everything goes through Docker

Runtime, tests, migrations, console — all expect to run inside the `app` container. Never invoke `composer`, `pest`, `phinx`, or `bin/console` from the host. The `Makefile` is the source of truth — most targets are thin wrappers around `docker compose exec app …`.

```bash
make env              # copy .env.example, generate APP_SECRET + ADMIN_PASSWORD
make up               # bring up db + app + cron
make migrate          # phinx against dev DB
make seed             # idempotent dev fixtures (1 admin + 3 campaigns + 5 offers + 10 flows)
make seed-fresh       # wipe campaigns/offers/flows first
make restart          # recreate app container to pick up code changes (no volume mount in some modes)
make logs / make shell / make psql
```

## Tests

Tests run against an isolated `db-test` Postgres container (tmpfs storage, separate compose profile) — the dev DB is never touched.

```bash
make test                 # unit + integration + arch (~10s)
make test-unit | test-integration | test-arch
make test-browser         # opt-in, needs Playwright Chromium + running dev stack + pixel-test stack
make stan                 # PHPStan level 6 over src + tests
```

To run a single file/filter — `make test-up` first, then explicitly point `DB_DSN` at `db-test`:

```bash
docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
  app ./vendor/bin/pest tests/Integration/Admin/OfferRepositoryTest.php
docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
  app ./vendor/bin/pest --filter='cross-campaign'
```

`make test-up` brings up `db-test`, runs Phinx (`-e test`), and runs `partitions:rotate` so partitioned tables exist before integration tests.

Browser tests are gated by `BROWSER_TESTS=1`. `tests/Browser/PixelCrossDomain.test.php` requires `make pixel-test-up` (4 lander domains × 3 pages each via OrbStack auto-HTTPS).

## Frontend assets

Assets are built by Bun (`scripts/build.ts`) into `public/assets/` with content-hashed filenames + `manifest.json` (read by `App\Shared\Asset\Manifest`). The pixel `public/p.js` is also a Bun build artifact. Both are gitignored.

```bash
make build-assets         # one-shot build via oven/bun:1-alpine
# inside container: bun run watch (script: scripts/build.ts)
```

After editing CSS/JS templates, rebuild assets — admin views resolve URLs through the manifest, stale builds 404.

## Architecture map

### Engine hot-path (`/<slug>` for organic traffic)

`src/Engine/ClickHandler.php` orchestrates the full pipeline; understanding the order is essential before touching any of the components:

1. `VisitorResolver` — cookie ID → server fingerprint (24h, hash of IP+UA+lang+salt) → FingerprintJS CE (30d) → new UUIDv7 (D7).
2. `GeoLookup` — MaxMind GeoLite2 City/Country/ASN, silently no-ops if `.mmdb` files are missing in `geoip-data/`.
3. `BotDetector` — IP list + ASN table + UA signatures; refreshed by the `bots:update` cron.
4. `DeviceDetector` — wraps `mobiledetectlib`.
5. `FlowMatcher` + `FilterCompiler` — flow filters are JSONB AND-groups within OR (D5), compiled to PHP closures and matched against the visitor `Context`.
6. `OfferPicker` — weighted random over the matched flow's `target_offers`, active-only.
7. `MacroExpander` — substitutes `{click_id}`, `{country}`, `{city}`, `{device_type}`, `{payout}`, `{rand:1-100}`, etc. in offer URLs and postback templates.
8. `Schema/*` — 15 response strategies (HTTP 301–308, Meta Refresh, Double Meta, iFrame, HTML page, Text, JSON, Curl proxy, JS redirect, No Action, HTTP code, Formula). New schemas register via `SchemaRegistry`.
9. Click is logged async to `stats.clicks` (RANGE-partitioned monthly, BRIN index on `created_at` per D3).

### Other request entry points

- `/p.js` (`Pixel\ScriptController`) + `/p/event` (`Pixel\EventController`, has OPTIONS preflight + permissive CORS so any external lander can fire). Events land in `stats.pixel_events` (also partitioned).
- `/postback` (GET+POST, `Postback\PostbackController`) — receives affiliate callbacks; UPSERTs `core.conversions` (idempotent on `subid+status`). Supports per-offer tokens *and* a campaign-level catch-all token (incl. anonymous pings without subid).
- Admin lives under `/admin` with middleware stack (`Session → Locale → Csrf → RateLimit → Auth → PasswordChangeRequired`). All routes in `config/routes.php` — that file is the canonical inventory.

### Outgoing postbacks

Decoupled outbox: `core.postback_deliveries` rows are written when conversions arrive. `Postback\OutgoingDeliveryWorker` (cron `postback:deliver`, every 2 min) flushes with exponential backoff retry. Don't fire HTTP from the request thread.

### Cron commands

All under `src/Cron/Command/`, registered as Symfony Console commands invoked via `bin/console <name>`. Schedule lives in `docker/supercronic/crontab` (read by the `cron` container). Notable: `partitions:rotate` (creates next-month partitions, drops past-retention; **must** run on `db-test` before integration tests too), `stats:refresh` (refreshes `clicks_hourly` matview every 5 min).

### Auth, session, rate limit

- Sessions persisted in Postgres via `Shared\Session\PgSessionHandler` (no filesystem, no Redis).
- `RateLimiter` is fixed-window per minute keyed independently on IP, login, and cookie (`RATE_LIMIT_IP`/`RATE_LIMIT_LOGIN`/`RATE_LIMIT_COOKIE`).
- `must_change_password` is enforced by `PasswordChangeRequiredMiddleware` — bootstrap admin password from `.env` only seeds once via `admin:init`.
- Auth events (login, logout, password change, lockout) go through `Shared\Auth\AuthEventLogger` for the audit log.

### DI + container

`config/di.php` is autowire-heavy but every controller/repo/service is explicitly listed. When adding a new service, append it there — autowire is on but explicit registration is the convention. The PDO factory sets `SET TIME ZONE` to `APP_TZ` (default `Europe/Moscow`) per session so `timestamptz` reads come back already localised; storage stays UTC.

## Database conventions

- Three schemas: `core` (auth, campaigns, offers, flows, conversions, settings, postback outbox), `stats` (clicks, pixel_events, visitors_fingerprints — all RANGE-partitioned monthly + BRIN), and the default `public` (Phinx migrations table).
- Migrations are Phinx PHP files in `migrations/` (one per logical change, datestamp-prefixed). Always add a migration; do not hand-edit schema in psql.
- Campaign slugs are Bitcoin-Base58 6-char (`Shared` generator excludes `0/O/I/l`) or a custom alias `^[a-zA-Z0-9]{3,16}$` (D13).
- Offers are **global** since M4 — `core.offers.campaign_id` was dropped. Campaign↔offer relationship is derived from `flows.target_offers` JSONB. Don't reintroduce the coupling.
- Filters are JSONB `{ groups: [ { conditions: [...] } ] }` — AND inside a group, OR across groups (D5). `FilterCompiler` is the only thing that should interpret them.

## Three deployment modes

Compose layering, picked at runtime via `DEPLOY_MODE` in `.env` and `docker/entrypoint.sh` selecting the right `Caddyfile.{dev,cf,direct}`:

| Mode | Compose | TLS | Real-IP |
|---|---|---|---|
| `dev` | `docker compose up` (override auto-merged) | OrbStack auto-HTTPS via `dev.orbstack.domains=slimtds.local` label | local |
| `cf_flex` / `cf_full` | `docker-compose.prod.cf.yml` | Cloudflare | `CF-Connecting-IP` header (`TRUSTED_PROXIES`) |
| `direct` | `docker-compose.prod.direct.yml` | Caddy auto-TLS / Let's Encrypt | trusted proxies only |

`make prod-up-cf` / `make prod-up-direct` / `make prod-down` enforce `.env` consistency before running. See `docs/DEPLOYMENT.md`.

## Conventions

- `declare(strict_types=1);` everywhere — enforced by an arch test.
- No `dd`/`var_dump` in `src/` — enforced by an arch test.
- Layer boundaries (`Admin` ↔ `Engine` ↔ `Pixel` ↔ `Postback` ↔ `Stats` ↔ `Shared`) — enforced by `tests/Arch`. If you need cross-layer access, route it through `Shared`.
- Admin templates are PHP partials under `resources/views/admin/` with `_partials/` for shared chrome (chip-mark, wordmark, tables, forms). UI conventions are in `.impeccable.md` — warm-stone palette, terracotta accent, `tabular-nums`, dense tables, no glassmorphism.
- i18n via `symfony/translation` — strings in `resources/translations/messages.{ru,en}.yaml`, RU has proper plural rules. Don't hard-code visible strings.
- Dark mode opt-in via `data-theme="dark"` on `<html>`, applied pre-paint to avoid flash.

## CI

GitHub Actions runs lint + PHPStan + the full Pest suite on every push (`.github/workflows/ci.yml`).
