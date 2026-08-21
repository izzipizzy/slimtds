# slimTDS

Slim-based Traffic Distribution System — a modern rewrite of
[zTDS v0.8.4](https://github.com/anonymous/ztds) on **PHP 8.4** +
**FrankenPHP** + **PostgreSQL 18** + **Bun/Tailwind 4/Alpine.js** + **Pest 4**.

**Documentation:** [slimtds.com/docs](https://slimtds.com/docs) — sources in
[slimtds-docs](https://github.com/izzipizzy/slimtds-docs). The landing page lives in
[slimtds-site](https://github.com/izzipizzy/slimtds-site). Both build and deploy on their
own; this repository holds only the application.

## Status

**M1** (scaffolding) — done.
**M2** (traffic engine) — done.
**M3** (admin polish + stats + Telegram + outgoing postbacks + backups + retention) — done (`v0.3.0-m3`).
**M4** (global offers + brand identity + dark mode + responsive shell) — **done** (`v0.4.0`).

### What changed in M4

- **Offers became global** — dropped `core.offers.campaign_id`. An offer is no longer "owned" by a campaign; relationship to campaigns is derived from `flows.target_offers` JSONB. One offer can be reused across many campaigns. New top-level CRUD at `/admin/offers/{new,/{id}/edit,/{id}/delete}`. Per-campaign `/admin/campaigns/{cid}/offers` is now a read-only "offers used by this campaign's flows" view.
- **Cross-domain pixel** — `/p/event` now ships permissive CORS (`Access-Control-Allow-Origin: *`) + OPTIONS preflight handler so any external lander can fire events into a campaign. See `docker-compose.pixel-test.yml`.
- **Brand identity** — `public/favicon.svg` (PCB / Circuit Chip mark in warm-stone palette), reusable PHP partials `_partials/chip-mark.php` + `_partials/wordmark.php` (light/dark variants).
- **Dark mode** — opt-in via `data-theme="dark"` on `<html>`, persists in `localStorage`, applied before paint to avoid flash. Toggle in admin sidebar bottom and login page footer.
- **Responsive shell** — fixed-position sidebar on desktop (≥901px), off-canvas drawer with backdrop on mobile, top-bar with menu button. Tables wrapped in `.tbl-scroll` with soft-fade scroll indicators.
- **CSS utility classes** — `.page-title`, `.section-title`, `.eyebrow`, `.label-uppercase`, `.meta-mono`, `.action-link`, `.danger-link`, `.breadcrumb`, `.input-underline`, `.btn-danger`, status-dot variants `{warn,error,info}`, `.form-row/.form-help/.form-error`. Sweeping inline `style=` out of view templates.
- **Browser test** — `tests/Browser/PixelCrossDomain.test.php` exercises real headless Chromium across 4 lander domains (3 pages each with internal nav → real `document.referrer`).

---

## What's in slimTDS

### Engine

- **Hot-path `/<slug>`** — handles organic traffic, resolves visitor, runs filter pipeline, picks offer, renders schema response
- **VisitorResolver** — cookie ID → server fingerprint (24h, IP+UA+lang hash) → FingerprintJS (30d) → new UUIDv7
- **GeoLookup** — MaxMind GeoLite2 City/Country/ASN (optional; silently skips if databases absent)
- **BotDetector** — bot IP list + ASN table + UA signatures; `bots:update` cron pulls from myip.ms
- **FilterCompiler** — JSONB filter → PHP closure; AND-groups within OR
- **OfferPicker** — weighted random selection with active-only guard
- **MacroExpander** — `{click_id}`, `{country}`, `{city}`, `{device_type}`, `{payout}`, `{rand:1-100}`, etc.
- **15 schema types** — HTTP 301–308, Meta refresh, Double Meta, iFrame, HTML page, Text, JSON, Curl forward, No Action, HTTP code, Formula
- **Async click logging** — UUIDv7, bot flag, all visitor metadata stored in `stats.clicks` (RANGE-partitioned, BRIN index)

### Pixel

- **`/p.js`** — FingerprintJS Community Edition with auto-fingerprint on load
- **`/p/event`** — server-side event ingestion, stored in `stats.pixel_events`
- **Per-campaign pixel page** — embed snippet + custom-event code sample at `/admin/campaigns/{id}/pixel`

### Session recording (rrweb)

- **Zero site-change** — rides on the existing `/p.js` pixel; rrweb is bundled inside and activates automatically when the script is loaded
- **`/p/rec`** — chunk-ingest endpoint; events land in `stats.rrweb_chunks` (daily-partitioned)
- **`rrweb:flush`** cron (every minute) assembles completed sessions and writes final records
- **`/admin/sessions`** — replay UI with campaign filter and rrweb-player
- **Settings** — `rrweb_sample_rate` (0–100 %) controls what fraction of visitors are recorded; `retention_rrweb_days` (default 7) controls partition retention

### Postback

- **Incoming `/postback`** — receives affiliate network callbacks; UPSERTs to `core.conversions` (idempotent on `subid+status`)
- **Outgoing S2S** — `core.postback_deliveries` outbox; worker with exponential-backoff retry (`postback:deliver` cron)
- **Per-offer postback URLs** with `{click_id}`, `{payout}`, `{status}` macro substitution

### Admin

- **Campaigns** — Base58 slug, soft-delete (trash mode), pagination + search
- **Offers** — global, top-level CRUD at `/admin/offers`. An offer is reusable across multiple campaigns through their flows. Postback token with rotation, macro URL builder, outgoing S2S postback URL templates per offer
- **Flows** — Alpine.js AND-in-OR filter builder with country/device/OS/browser/lang datalists, 15 schema types, weighted offer targets (offer selector lists ALL global offers)
- **Campaign workspace** — one-page hub: live slug URL, pixel snippet, derived offers list (used by campaign flows), postback patterns per offer, danger zone
- **`/admin/clicks`** — click log viewer with filters (campaign, country, device, date range), 7-day window
- **`/admin/conversions`** — conversion log with status breakdown
- **`/admin/statistics`** — ECharts time-series (clicks/conversions/revenue) + KPI cards + country/device breakdown
- **Per-campaign stats** — individual campaign dashboard at `/admin/campaigns/{id}/stats`
- **`/admin/settings`** — partition retention policy (clicks / pixel events / visitors)
- **Login + auth** — session-based, rate-limited (IP + login + cookie), must-change-password enforcement, audit log
- **i18n** — RU/EN via symfony/translation with proper Russian plurals; switchable from UI (incl. login page)
- **Brand + theme** — chip-mark logo, dark/light mode toggle (persisted, no flash on reload)

### Ops / Cron

| Command | Schedule | Purpose |
|---|---|---|
| `partitions:rotate` | daily | Create future month partitions; drop old partitions past retention |
| `bots:update` | weekly | Pull fresh bot-IP/ASN list from myip.ms |
| `geoip:check` | monthly | Warn if GeoLite2 databases are stale |
| `db:vacuum` | weekly | VACUUM ANALYZE on high-write tables |
| `telegram:digest` | daily | Send click/conversion/revenue summary to Telegram |
| `telegram:alerts` | hourly | Alert on anomalies (high bot rate, DB lag, etc.) |
| `db:backup` | daily | `pg_dump` to `/var/backups/`, keep last N dumps |
| `postback:deliver` | every 2 min | Flush outgoing postback outbox with exponential retry |
| `stats:refresh` | every 5 min | Refresh `clicks_hourly` materialized view |
| `rrweb:flush` | every minute | Assemble completed rrweb chunk sequences into session records |

### CI/CD

- **Gitea Actions** (primary) — lint + PHPStan + test on every push; Docker image on `v*` tags → Gitea registry
- **GitHub Actions** (mirror) — identical pipelines; image pushed to GHCR

### Tests

- **~227 tests** — unit (38) + integration (182) + arch (7) — all green on every push, run against isolated `slimtds_test` DB
- **Browser tests** (opt-in) — Pest 4 browser suite in `tests/Browser/`, skip-guarded with `BROWSER_TESTS=1`. Includes `PixelCrossDomain.test.php` that drives Chromium through 4 lander domains × 3 pages each, validating CORS + referrer attribution + FingerprintJS visitor stability across origins.

---

## Quick start (dev)

> Installing on a fresh server instead? Hand [`docs/AI-INSTALL-PROMPT.md`](docs/AI-INSTALL-PROMPT.md)
> to a coding agent — it follows [`docs/AI-INSTALL.md`](docs/AI-INSTALL.md) from bare OS to a
> smoke-tested install.

Requirements: **Docker Desktop** or **OrbStack** (recommended — provides auto-HTTPS `.local` domains via container labels).

```bash
git clone https://github.com/izzipizzy/slimtds.git
cd slimtds

# Copy env template and fill local secrets (generates APP_SECRET + ADMIN_PASSWORD)
make env

# Bring up db + app + cron
make up

# Run migrations
make migrate

# Seed dev data (1 admin + 3 campaigns + 5 offers + 10 flows)
make seed

# Open in browser
# https://slimtds.local/admin/login  — login "admin", password shown in make env output
```

---

## Project layout

```
slimTDS/
├── bin/console                    # Symfony Console CLI entry
├── config/
│   ├── app.php                    # Slim app factory
│   ├── di.php                     # PHP-DI container
│   ├── routes.php                 # All HTTP routes
│   └── frankenphp/                # Caddyfile.{dev,cf,direct}
├── docker/
│   ├── Dockerfile                 # multi-stage: assets-builder (Bun) + vendor (Composer) + runtime (FrankenPHP)
│   ├── entrypoint.sh              # picks Caddyfile by DEPLOY_MODE
│   └── supercronic/crontab
├── docker-compose.yml             # base
├── docker-compose.override.yml    # dev (auto-merged by Docker Compose)
├── docker-compose.prod.cf.yml     # prod behind Cloudflare
├── docker-compose.prod.direct.yml # prod with Let's Encrypt (Caddy)
├── migrations/                    # Phinx migrations
├── public/
│   ├── index.php                  # Slim entry point
│   ├── assets/                    # built CSS/JS with content-hash names (gitignored)
│   └── p.js                       # pixel tracker (built by Bun, gitignored)
├── resources/
│   ├── css/app.css                # Tailwind 4 + custom @layer
│   ├── js/
│   │   ├── app.js                 # Alpine + flowBuilder component
│   │   └── pixel.js               # FingerprintJS CE pixel tracker
│   ├── translations/              # messages.{ru,en}.yaml
│   └── views/
│       ├── layouts/{admin,public}.php
│       └── admin/{login,password,dashboard,campaigns,offers,flows,
│                  clicks,conversions,statistics,settings}/
├── src/
│   ├── Admin/                     # Controller/Form/Middleware/Repository/Command
│   ├── Engine/                    # ClickHandler, VisitorResolver, GeoLookup, BotDetector,
│   │                              #   FilterCompiler, FlowMatcher, OfferPicker, MacroExpander,
│   │                              #   Schema/* (15 types), SchemaRegistry
│   ├── Pixel/                     # ScriptController, EventController
│   ├── Postback/                  # PostbackController, OutgoingDeliveryWorker
│   ├── Stats/                     # StatsRepository, ClickAggregator
│   ├── Cron/Command/              # All console commands
│   └── Shared/                    # Auth/Asset/Db/I18n/RateLimit/Session/View/Telegram
├── geoip-data/                    # MaxMind .mmdb files (gitignored, populated by geoipupdate)
├── scripts/build.ts               # Bun build script
├── tests/
│   ├── Unit/                      # Pure function tests
│   ├── Integration/               # DB-backed tests
│   ├── Arch/                      # pest-plugin-arch invariants
│   └── Browser/                   # Pest 4 browser tests (opt-in)
├── composer.json
├── package.json
├── pest.xml
└── README.md
```

---

## Three deployment modes

| Mode | Compose command | TLS | Real-IP source |
|---|---|---|---|
| **dev** | `docker compose up` | OrbStack auto-HTTPS via `dev.orbstack.domains=slimtds.local` label | local |
| **prod-cf** | `docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml up -d` | Cloudflare (Flex or Full with Origin Cert) | `CF-Connecting-IP` header |
| **prod-direct** | `docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml up -d` | Caddy auto-TLS via Let's Encrypt | trusted proxies only |

CF mode: `DEPLOY_MODE=cf_flex` (HTTP :80 origin) vs `DEPLOY_MODE=cf_full` (HTTPS :443 with CF Origin cert).
Direct mode: set `DEPLOY_MODE=direct` and `DOMAIN=tds.example.com` in `.env`.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for full production setup instructions.

---

## Architecture decisions

The design rests on 23 numbered decisions (D1–D23). The ones worth knowing before reading the code:
- **D2** FrankenPHP worker mode everywhere (admin + engine). Classic mode is a fallback (`FRANKENPHP_WORKER_MODE=0`).
- **D3** RANGE-partitioned `stats.clicks`, `stats.pixel_events`, `stats.visitors_fingerprints` with BRIN on `created_at`.
- **D5** Flow filters = AND-groups within OR, stored as JSONB.
- **D7** Visitor ID: cookie → server-FP (24h, hash(ip+ua+accept-lang+salt)) → FingerprintJS CE (30d) → new UUIDv7.
- **D13** Campaign slug: Base58 (Bitcoin-style, excludes `0/O/I/l`), 6 chars, or custom alias `^[a-zA-Z0-9]{3,16}$`.
- **D19** 15 Keitaro-like schema types per flow (HTTP 301–308, Meta/Double Meta, iFrame, HTML, Text, JSON, Curl, No Action, HTTP Code, Formula).

---

## Testing the pixel from multiple domains

A standalone compose runs 4 mini-sites on distinct OrbStack `.local` domains, each a 3-page lander (`/`, `/about`, `/pricing`) with the same campaign pixel installed. Internal navigation between pages produces real `document.referrer`, so this also exercises the referrer chain.

```bash
make pixel-test-up    # docker compose -f docker-compose.pixel-test.yml up -d
```

Then open in a browser:

- `https://lander-a.local/` (+ `/about`, `/pricing`)
- `https://lander-b.local/`
- `https://lander-c.local/`
- `https://lander-d.local/`

Each loads the pixel via `<script async src="https://slimtds.local/p.js?c=demo01">`. Page-load auto-fires `pageview`, the index page has a buttoned custom event (`purchase`/`signup`/`add_to_cart`/`engagement`). Internal nav fires `view_about` / `view_pricing` events.

Watch the stream in `/admin/pixel` — Referrer column shows the source page (`https://lander-a.local/about` etc.).

```bash
make pixel-test-down
```

Headless verification via Playwright (Node, runs from host):

```bash
node /tmp/pixel-test.mjs   # walks all 4 sites + 3 pages each, verifies events + statuses
```

---

## Make targets

| Target | Description |
|---|---|
| `make env` | Copy `.env.example` → `.env` and generate `APP_SECRET` + `ADMIN_PASSWORD` |
| `make up` | Start dev stack |
| `make down` | Stop stack |
| `make restart` | Recreate app container (pick up code changes) |
| `make logs` | Tail app logs |
| `make shell` | Shell into app container |
| `make psql` | Open psql shell against dev DB |
| `make migrate` | Run Phinx migrations |
| `make seed` | Populate dev data (idempotent) |
| `make seed-fresh` | Wipe + re-seed dev data |
| `make test` | Run all suites: unit + integration + arch |
| `make test-unit` | Unit tests only |
| `make test-integration` | Integration tests only |
| `make test-arch` | Architecture tests only |
| `make test-browser` | Browser tests (requires Playwright + running stack) |
| `make stan` | PHPStan level 6 |
| `make build` | Rebuild Docker image |
| `make build-assets` | Rebuild Bun/Tailwind assets only |
| `make pixel-test-up` | Start 4-domain pixel test stand (`docker-compose.pixel-test.yml`) |
| `make pixel-test-down` | Stop pixel test stand |
| `make prod-up-cf` | Start prod stack behind Cloudflare (`docker-compose.prod.cf.yml`) |
| `make prod-up-direct` | Start prod stack with Caddy auto-TLS (`docker-compose.prod.direct.yml`) |
| `make prod-down` | Stop the active prod stack (CF or direct) |
| `make deploy` | Show deployment guide (see `docs/DEPLOYMENT.md`) |
| `make clean` | Remove `.env`, `vendor/`, `node_modules/`, `public/assets/` |

---

## Key URLs (dev stack)

- `https://slimtds.local/` — redirects to `/admin`
- `https://slimtds.local/__health` — JSON health check
- `https://slimtds.local/admin/login` — admin login
- `https://slimtds.local/admin/campaigns` — campaigns CRUD
- `https://slimtds.local/admin/offers` — global offers CRUD (M4)
- `https://slimtds.local/admin/flows` — cross-campaign flows list
- `https://slimtds.local/admin/clicks` — click log viewer
- `https://slimtds.local/admin/conversions` — conversion log
- `https://slimtds.local/admin/pixel` — cross-campaign pixel events feed (with Referrer column)
- `https://slimtds.local/admin/statistics` — statistics dashboard
- `https://slimtds.local/admin/settings` — retention settings
- `https://slimtds.local/demo01` — engine redirect (seeded campaign)
- `https://slimtds.local/p.js` — FingerprintJS pixel tracker
- `https://slimtds.local/postback` — incoming postback endpoint

---

## Docs

- [docs/TESTING.md](docs/TESTING.md) — test isolation, suites, browser tests
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — production deployment (all three modes)

---

## License

Licensed under the **GNU Affero General Public License v3.0 or later** (AGPL-3.0-or-later) — see [LICENSE](LICENSE).

You can self-host and modify slimTDS freely. If you run a modified version as a network service for others, the AGPL requires you to make your modified source available to those users.
