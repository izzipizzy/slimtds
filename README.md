# slimTDS

**English** · [Русский](README.ru.md)

slimTDS is a self-hosted traffic distribution system (TDS) for affiliate and
performance marketers who want full control of their click data instead of
routing it through a SaaS vendor. It's a modern rewrite of the classic zTDS,
built on **PHP 8.4**, **Slim 4** running on **FrankenPHP in worker mode**
(no per-request bootstrap — the app stays resident in memory), and
**PostgreSQL 18** with monthly-partitioned, BRIN-indexed click/event tables.
The admin UI is Tailwind 4 + Alpine.js + ECharts; visitor fingerprinting uses
FingerprintJS Community Edition.

Flows route visitors by country, device, OS, browser, language, and other
signals to weighted offers through any of 15 response schemas (HTTP redirects
301–308, meta refresh, iframe, curl proxy, JSON, formula-based, and more).
Bot filtering, GeoIP lookups, incoming/outgoing postbacks, session recording
(rrweb), and a statistics dashboard are all built in.

**Live demo:** [slimtds.com](https://slimtds.com) — a full, open sandbox. Log in at [/admin/login](https://slimtds.com/admin/login) with **`admin` / `demo`**. Everything **resets to baseline every 4 hours**, so feel free to change anything.
**Docs:** [slimtds.com/docs](https://slimtds.com/docs)

## Quickstart

Requires Docker (≥ 24) with the Compose v2 plugin, or OrbStack on macOS.

> Installing on a fresh server? Hand [`docs/AI-INSTALL-PROMPT.md`](docs/AI-INSTALL-PROMPT.md)
> to a coding agent — it follows [`docs/AI-INSTALL.md`](docs/AI-INSTALL.md) from bare OS to a
> smoke-tested install.

```bash
git clone https://github.com/izzipizzy/slimtds.git
cd slimtds

make env      # copy .env.example → .env, generate APP_SECRET + ADMIN_PASSWORD
make up       # bring up db + app + cron
make migrate  # run Phinx migrations
make seed     # idempotent dev fixtures: 1 admin + 3 campaigns + 5 offers + 10 flows
```

Then open the admin login shown at the end of `make up` and sign in with
`admin` / the password `make env` printed.

## What's inside

- **Engine hot-path (`/<slug>`)** — visitor resolution (cookie → server
  fingerprint → FingerprintJS → new UUIDv7), GeoIP, bot detection, JSONB
  filter matching (AND-groups within OR), weighted offer selection, macro
  expansion, and 15 response schema types. Clicks are logged async to a
  RANGE-partitioned, BRIN-indexed table.
- **Pixel** — `/p.js` (FingerprintJS CE) + `/p/event`, with permissive CORS so
  any external lander can report events. Includes zero-site-change session
  recording via a bundled rrweb client, which landers that only need pageviews
  can switch off with `?rec=0`. Captures the visit's external entry referrer so
  search traffic stays attributable behind server-side redirects.
- **Postback** — incoming `/postback` (GET/POST, idempotent UPSERT on
  `subid+status`, per-offer and campaign-level catch-all tokens) plus a
  decoupled outgoing delivery worker with exponential-backoff retry.
- **Admin** — campaigns, global offers (reusable across campaigns via flow
  targets), an Alpine.js flow/filter builder, click/conversion logs, an
  ECharts statistics dashboard, session replay, i18n (RU/EN), and dark mode.
- **Ops** — cron-driven partition rotation, bot-list refresh, GeoIP staleness
  checks, DB backups, Telegram digests/alerts, and materialized-view refresh.

## Deployment modes

| Mode | Compose | TLS | Real-IP source |
|---|---|---|---|
| `dev` | `docker compose up` | OrbStack auto-HTTPS via container label | local |
| `cf_flex` / `cf_full` | `docker-compose.prod.cf.yml` | Cloudflare (Flex or Full with Origin Cert) | `CF-Connecting-IP` header |
| `direct` | `docker-compose.prod.direct.yml` | Caddy auto-TLS via Let's Encrypt | trusted proxies only |

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for full setup, including
MaxMind GeoLite2 and Telegram configuration.

## Benchmarking

With the stack up, point the built-in load tester at any active campaign slug:

```bash
make benchmark SLUG=<slug>
```

It runs [fortio](https://github.com/fortio/fortio) against the engine's
internal HTTP port so results reflect the engine itself, not TLS/network
overhead. Use a dev/staging instance or a throwaway slug — every request logs
a click. See [slimtds.com/docs](https://slimtds.com/docs) for hardware
sizing guidance and reference numbers.

## Tests

```bash
make test          # unit + integration + arch (~10s), against an isolated db-test container
make test-browser   # opt-in, needs Playwright Chromium + a running dev stack
make stan            # PHPStan level 6
```

## License

Licensed under the **GNU Affero General Public License v3.0 or later**
(AGPL-3.0-or-later) — see [LICENSE](LICENSE). You can self-host and modify
slimTDS freely. If you run a modified version as a network service for
others, the AGPL requires you to make your modified source available to
those users.
