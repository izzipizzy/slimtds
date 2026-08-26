# Deployment Guide

slimTDS supports three deployment modes. Choose based on your network topology.

> This guide is written for a human operator. If you are an AI agent installing on a fresh
> server, start at [`AI-INSTALL.md`](AI-INSTALL.md) instead — same stack, but ordered as an
> executable runbook with preflight checks and a smoke test.

---

## Prerequisites

- **Docker** (≥ 24) with Compose v2 plugin, or **OrbStack** (macOS, recommended for dev)
- A valid domain name (required for `prod-direct` mode; optional for `prod-cf`)
- A MaxMind account with a free GeoLite2 license key (optional but required for geo-targeting)

---

## Three deployment modes

### dev — OrbStack auto-HTTPS

OrbStack automatically provisions a wildcard TLS cert for `.local` domains and routes traffic based on container labels.

```bash
# First time only
make env      # generates .env with random secrets
make migrate  # run Phinx migrations

# Start / stop
make up
make down
```

The app container carries the label `dev.orbstack.domains=slimtds.local`, so OrbStack routes `https://slimtds.local` directly to the FrankenPHP container. No manual TLS setup required.

---

### prod-cf — Behind Cloudflare

Use when the origin is behind Cloudflare's proxy.

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml up -d
```

**Required `.env` variables:**

```env
DEPLOY_MODE=cf_flex      # cf_flex = HTTP-only origin (Cloudflare handles TLS)
                          # cf_full = not implemented; the container refuses to start
APP_SECRET=<64-hex-chars>
ADMIN_PASSWORD=<initial-password>
```

> **`cf_full` is not implemented and the container refuses to start in it.** The mode
> was documented as an encrypted origin, but `Caddyfile.cf` carries `auto_https off`
> and no `tls` directive, no Origin Certificate is referenced, and the compose file
> mounts none. Setting `CF_LISTEN_PORT=443` only moved *plaintext* HTTP to port 443,
> where Cloudflare Full answers with error 525 — while the operator believed traffic
> to the origin was encrypted. Wiring up the certificate is tracked separately; until
> then `entrypoint.sh` exits with `78` and says so, rather than serving an origin that
> silently contradicts this guide.

`cf_flex` mode: Caddy listens on `:80`, Cloudflare terminates TLS. Simpler but traffic between CF edge and your origin is unencrypted.

**Trusted proxies**: The app reads the real visitor IP from `CF-Connecting-IP`. `config/frankenphp/Caddyfile.cf` carries a **static, hardcoded** list of Cloudflare IP ranges (`trusted_proxies static …` + `client_ip_headers CF-Connecting-IP`) — nothing refreshes it, so it needs a manual update if Cloudflare changes its ranges. `src/Shared/RealIp.php` then walks `X-Slim-IP → X-Real-IP → CF-Connecting-IP → True-Client-IP → X-Forwarded-For → REMOTE_ADDR`.

The `TRUSTED_PROXIES` key in `.env.example` is **not read by anything** — leave it empty.

---

### prod-direct — Caddy Let's Encrypt

Use for a standalone public server without Cloudflare.

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml up -d
```

**Required `.env` variables:**

```env
DEPLOY_MODE=direct
DOMAIN=tds.example.com
APP_SECRET=<64-hex-chars>
ADMIN_PASSWORD=<initial-password>
```

Caddy automatically obtains and renews TLS certificates from Let's Encrypt. Port 80 and 443 must be publicly reachable.

---

## Initial bootstrap

### Generate a strong `APP_SECRET`

```bash
php -r 'echo bin2hex(random_bytes(32));'
# or:
openssl rand -hex 32
```

Paste the result into `.env` as `APP_SECRET`. This value signs sessions and CSRF tokens — rotate it to invalidate all existing sessions.

### Create the admin account

The first admin is created by the `admin:init` command, which reads `ADMIN_PASSWORD` from `.env`:

```bash
docker compose exec app php bin/console admin:init
```

After first login, navigate to `/admin/settings` to change the password via the UI, or use the CLI:

```bash
docker compose exec app php bin/console admin:set-password admin <new-password>
```

---

## MaxMind GeoLite2 setup

GeoLite2 databases are required for geo-targeting filters (country, city, ASN). They are **not bundled**.

1. [Sign up for a free MaxMind account](https://www.maxmind.com/en/geolite2/signup)
2. Generate a license key under **My License Key**
3. Add to `.env`:

```env
MAXMIND_ACCOUNT_ID=123456
MAXMIND_LICENSE_KEY=xxxxxxxxxxxx
```

4. Run the one-time download:

```bash
docker compose --profile geo up geoipupdate
```

This populates `./geoip-data/` with `GeoLite2-Country.mmdb`, `GeoLite2-City.mmdb`, and `GeoLite2-ASN.mmdb`.

The `geoip:check` cron command (runs monthly) warns via Telegram if databases are older than 45 days.

Without the databases, `GeoLookup` silently skips geo-resolution and all geo-filter tests are marked as skipped — that is expected behaviour.

---

## Telegram setup

Telegram notifications (daily digest + hourly alerts) are optional.

1. Create a bot via [BotFather](https://t.me/botfather) — copy the token
2. Find your chat ID: forward any message to [@userinfobot](https://t.me/userinfobot), it replies with your numeric ID
3. Add to `.env`:

```env
TELEGRAM_BOT_TOKEN=1234567890:AAAAA...
TELEGRAM_CHAT_ID=-100xxxxxxxx
```

The cron container will automatically start sending digests and alerts. To test immediately:

```bash
docker compose exec app php bin/console telegram:digest
docker compose exec app php bin/console telegram:alerts
```

---

## Build identity

The footer reports the running build from four variables baked into the image
at build time (`docker/Dockerfile:74-81`). They arrive as **build args**, which
means they must be exported in the shell that runs the build — there is
deliberately no `environment:` mapping for them, because a compose environment
entry overrides the image ENV even when it interpolates to an empty string, and
that would erase a correctly stamped release image.

`make` exports them for every target, so a dev build is stamped automatically.
A production update must not use `make` (it applies the dev overlay), so export
them by hand before `up -d --build`:

```bash
export APP_VERSION=$(git describe --tags --always --dirty --match 'v*')
export APP_COMMIT=$(git rev-parse --short HEAD)
export APP_BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
export APP_BUILD_KIND=source
```

Skip this and the image bakes empty strings; `BuildInfo` then reports `unknown`
and the footer shows no version at all, which is the honest outcome — it never
invents one.

`--match 'v*'` matters: `git describe --tags` takes the nearest reachable tag of
any kind, so a `rollback/...` tag on the checkout would become the reported
version.

`APP_BUILD_KIND=source` is correct for a server that builds its own image. Only
CI, building from a release tag, may set `release` — and only a `release` build
is ever compared against upstream, because the nearest *reachable* tag is not
the newest tag in the repository.

---

## CI/CD secrets

No manual secrets are needed. `GITHUB_TOKEN` is provided by Actions, and
`.github/workflows/release.yml` already declares `packages: write`, so a `v*` tag builds the
runtime image and pushes it to GitHub Container Registry on its own.

If you fork this onto another forge, the release workflow is the only place that needs
registry credentials.

---

## Backup and restore

### Backup

```bash
docker compose exec app php bin/console db:backup
```

Dumps are written to `/app/var/backups/` in the container, which compose bind-mounts to `./var/backups/` on the host — host-side copies need no extra volume. Files are `pg_dump --format=custom` archives named `<timestamp>.dump`. The daily cron prunes anything older than `RETENTION_DAYS` in `src/Cron/Command/DbBackupCommand.php`.

### Restore

```bash
# List available dumps
docker compose exec app ls /app/var/backups/

# Restore (the 'yes' argument confirms destructive operation).
# The path is absolute or relative to var/backups/.
docker compose exec app php bin/console db:restore 2026-04-24_03-00-00.dump yes
```

---

## Common operator tasks

### Change admin password

```bash
docker compose exec app php bin/console admin:set-password admin <new-password>
```

### Manually rotate partitions

```bash
docker compose exec app php bin/console partitions:rotate
```

Creates partitions for the next 3 months and drops partitions older than the retention window configured in `/admin/settings`.

### Re-seed dev data

```bash
make seed-fresh   # truncates campaigns/offers/flows then re-seeds
```

The seed creates 3 campaigns + 5 **global** offers + 11 flows. Note that since M4, offers are not bound to a campaign — one of the seeded offers (`Demo offer #1`) is reused by both `demo01` and `mixab` campaigns through their flows, demonstrating cross-campaign offer reuse.

### Add an offer

Since M4, offers live at the top level (`/admin/offers`), not under a campaign:

1. Go to `/admin/offers/new`, fill name + URL + payout
2. Edit a flow at `/admin/campaigns/{cid}/flows/{fid}/edit`
3. In the offer-target section, pick the new offer from the dropdown (lists ALL global offers)
4. Set weight, save

Per-campaign `/admin/campaigns/{cid}/offers` is now a read-only "offers used by this campaign's flows" view.

### Test the pixel from external lander domains

```bash
make pixel-test-up    # 4 nginx mini-sites on lander-{a,b,c,d}.local (OrbStack auto-HTTPS)
```

Each lander has 3 pages (`/`, `/about`, `/pricing`) and embeds `<script async src="https://slimtds.local/p.js?c=demo01">`. CORS is permissive on `/p/event` so cross-domain pixel events are accepted. Watch the stream in `/admin/pixel`.

```bash
make pixel-test-down
```

### Switch language

There is no environment variable for the interface language. `LocaleMiddleware` resolves it per request, in this order:

1. the signed-in admin's `ui_lang` column (set from the admin sidebar switcher, persisted per account),
2. the `ui_lang` cookie,
3. `Accept-Language`, when it starts with `en`,
4. otherwise the default, `ru`.

Supported values are `ru` and `en`. Switching from the sidebar takes effect immediately — no restart.

---

## Troubleshooting

### FrankenPHP worker mode crashes on startup

FrankenPHP worker mode (`FRANKENPHP_WORKER_MODE=1`) keeps the Slim app in memory between requests. If a global bootstrap error occurs (e.g., bad `.env` value, missing migration), the worker process may crash in a restart loop.

**To fall back to classic (CGI-like) mode:**

```env
FRANKENPHP_WORKER_MODE=0
```

This makes every request spawn a fresh PHP process — slower but simpler to debug. Check `make logs` for the PHP error.

### GeoIP databases missing or stale

If you see `GeoLookup: database not found` in logs:

```bash
docker compose --profile geo up geoipupdate
```

If the databases exist but are more than 45 days old, `geoip:check` will emit a Telegram alert. You can refresh manually at any time with the command above.

### `make test` wipes my dev data

It should not — `make test` uses the `db-test` profile, which points Pest at an isolated `slimtds_test` database in a separate container with `tmpfs` storage. The dev DB (`slimtds` on the `db` container) is never touched by the test suite.

If you still suspect data loss, check that your `.env` has `DB_DSN` pointing to the dev DB (not test), and that you're not accidentally running tests with a custom `DB_DSN` override.

### Browser tests are skipped

By design. The browser suite (`tests/Browser/`) requires:

1. Playwright Chromium installed inside the app container, OR Playwright on the host:
   ```bash
   # in container:
   docker compose exec app sh -lc 'npx playwright install chromium'
   # OR on host (faster, recommended):
   cd /tmp && npm install playwright && npx playwright install chromium
   ```
2. The dev stack running at `https://slimtds.local`
3. The environment variable `BROWSER_TESTS=1`
4. For `PixelCrossDomain.test.php`: `make pixel-test-up` to start the 4 lander services

Run with:

```bash
BROWSER_TESTS=1 docker compose exec -e BROWSER_TESTS=1 app ./vendor/bin/pest --testsuite=Browser
# or:
make test-browser
```

For headless verification of the cross-domain pixel chain without Pest, run:

```bash
node /tmp/pixel-test.mjs   # walks lander-a..d × home/about/pricing, asserts events + referrer chain
```

The CI pipelines do **not** run browser tests by default (Chromium not installed in the CI runner image).

---

## Monitoring suggestion

For production monitoring:

- **Postgres** — expose metrics via [`postgres_exporter`](https://github.com/prometheus-community/postgres_exporter) and import the [PG dashboards for Grafana](https://grafana.com/grafana/dashboards/9628)
- **Caddy** — Caddy exposes Prometheus metrics at `http://localhost:2019/metrics` by default; scrape and visualize request rates, latency, and TLS certificate expiry
- **Alerts** — slimTDS Telegram alerts cover high bot rate and DB lag; combine with Grafana alerting for disk/CPU/memory thresholds
