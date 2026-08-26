# slimTDS — AI-agent install runbook

**Audience: an AI coding agent with shell access to a fresh Linux server.**
Humans should read [`DEPLOYMENT.md`](DEPLOYMENT.md) instead — it explains *why*. This file
tells an agent *exactly what to run*, in order, and how to know it worked.

Model-agnostic: Claude Code, Codex/GPT, Qwen, Kimi — anything that can run shell commands.
If you were handed [`AI-INSTALL-PROMPT.md`](AI-INSTALL-PROMPT.md), you are in the right place.

Sections are numbered §0–§10 and are referenced by number elsewhere. Do not skip ahead.

---

## §0 — Your role and the rules

You are installing slimTDS on a server you do not own. Behave accordingly.

1. **Non-destructive by default.** Do not stop, reconfigure, or uninstall anything already
   running on this machine. Do not reset firewall policy — §4 only *adds* rules.
2. **Never print secrets.** `APP_SECRET`, `DB_PASSWORD`, `MAXMIND_LICENSE_KEY` and the
   Telegram bot token must never appear in your output, not even partially. The admin
   password is the single secret you report, once, in §9.
3. **Do not modify application source.** Nothing under `src/`, `config/`, `docker/`, or
   `migrations/` may be edited. If something does not work, go to §10. Do not invent a patch.
4. **A failed §2 preflight check is a full stop.** Report it and wait. Do not work around it.
5. **Ask everything at once.** Collect all input in §1 in a single message, then run to
   completion without interrupting the user again.
6. **Report honestly.** If a §8 smoke check fails, say so in §9. A half-working install
   described as working is worse than a failed install.

---

## §1 — Collect input (one message, once)

Ask the user for all of this in a single message. Everything optional has a working default —
say what the default costs.

| Input | Required | Default / note |
|---|---|---|
| Deploy mode | **yes** | `direct` \| `cf_flex` \| `dev` — see the table below |
| Domain | yes for `direct` and `cf_flex` | must already have an A record pointing at this server |
| Admin password | no | you generate a strong one and report it in §9 |
| `APP_TZ` | no | `Europe/Moscow`. Affects how timestamps are displayed; storage stays UTC |
| MaxMind account ID + license key | no | **without it geo targeting silently does nothing** — country/city/ASN filters never match |
| Telegram bot token + chat ID | no | without it the daily digest and alerts are off |
| Cloudflare Origin Certificate (cert + key paths) | no | `cf_full` was the mode that would have needed one; it is not implemented |

Do not ask about the admin UI language — there is no environment variable for it. The
interface defaults to Russian and each admin switches it from the sidebar; the choice is
stored per account, with an `ui_lang` cookie and `Accept-Language` as fallbacks.

**Which mode:**

| Mode | Pick it when |
|---|---|
| `direct` | The server is the public origin. Caddy gets Let's Encrypt certificates itself. Ports 80 and 443 must be reachable from the internet. **This is the default choice for a plain VPS.** |
| `cf_flex` | The domain is proxied through Cloudflare and you accept plain HTTP between Cloudflare and this server. Caddy listens on `:80`. |
| ~~`cf_full`~~ | **Not implemented.** It was documented as an HTTPS origin, but no certificate was ever wired up: `:443` would serve plaintext. `entrypoint.sh` refuses to start in this mode. Use `cf_flex`. |
| `dev` | Local workstation only. **Publishes no ports** — see the warning in §6. Never use it on a server. |

---

## §2 — Preflight

Run all of these before touching anything. Record the results in a short table and show it to
the user. **Any FAIL stops the install** — report and wait.

```bash
# Distro and architecture
cat /etc/os-release | head -2 ; uname -m

# Root or passwordless sudo
id -u                                   # 0 → root; otherwise: sudo -n true

# Memory (MB) and free disk on / (GB)
free -m | awk '/Mem:/ {print $2}'
df -BG --output=avail / | tail -1

# Ports 80 and 443 must be free
ss -lntp | grep -E ':(80|443)\s' || echo "PORTS FREE"

# DNS — direct and cf_flex modes only. Must equal this server's public IP.
dig +short "$DOMAIN" ; curl -s https://api.ipify.org ; echo

# Outbound network
curl -sI https://github.com | head -1
```

| Check | Pass condition | On failure |
|---|---|---|
| Distro | Debian/Ubuntu or RHEL family; `x86_64` or `aarch64` | Stop. Another distro needs manual Docker install steps. |
| Privileges | `id -u` is `0`, or `sudo -n true` succeeds | Stop. Ask for root or passwordless sudo. |
| RAM | ≥ 2000 MB | Continue, but §4 **must** create swap. |
| Disk | ≥ 20 GB free | Stop. The image build plus Postgres data will not fit. |
| Ports 80/443 | prints `PORTS FREE` | Stop. Something already serves HTTP here — report what `ss` showed. This is the most common cause of a half-working install. |
| DNS (`direct`) | `dig` output equals the public IP | Stop. Let's Encrypt will fail. Ask the user to fix the A record first. |
| DNS (`cf_flex`) | `dig` returns Cloudflare IPs | Continue — that is expected when the record is proxied. |
| Outbound | `HTTP/2 200` | Stop. No internet, nothing further will work. |

---

## §3 — Install Docker

Skip if `docker compose version` already prints v2.x.

**Debian / Ubuntu:**

```bash
apt-get update
apt-get install -y ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/$(. /etc/os-release && echo "$ID")/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/$(. /etc/os-release && echo "$ID") \
$(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

**RHEL / Rocky / Alma / Fedora:**

```bash
dnf -y install dnf-plugins-core
dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
dnf -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

**Fallback**, if the above fails on an unusual distro: `curl -fsSL https://get.docker.com | sh`.

**Do not** install Docker from snap, and do not install the distro's `docker.io` /
`podman-docker` packages — you need Compose v2 available as `docker compose` (a subcommand,
not the old `docker-compose` binary).

Enable and verify:

```bash
systemctl enable --now docker
docker run --rm hello-world      # expect "Hello from Docker!"
docker compose version           # expect "Docker Compose version v2.x"
```

---

## §4 — OS hygiene

Additive only. Never flush or reset an existing configuration.

**Firewall.** If `ufw` or `firewalld` is installed and active, add the rules. If neither is
installed and the user did not mention a cloud firewall, install `ufw`.

```bash
# ufw (Debian/Ubuntu)
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp
ufw --force enable            # ONLY after 22/tcp is allowed — otherwise you lock yourself out

# firewalld (RHEL family)
firewall-cmd --permanent --add-service=ssh
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https
firewall-cmd --reload
```

> **Warning.** Enabling a firewall over SSH without allowing port 22 first ends the session
> and locks you out permanently. Allow 22 before `ufw --force enable`. Always.

**Swap** — only when RAM < 2 GB *and* `swapon --show` is empty:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

**Timezone** (cosmetic — the application sets its own database session timezone from `APP_TZ`):

```bash
timedatectl set-timezone "<APP_TZ from §1>"
```

---

## §5 — Get the code and write `.env`

```bash
git clone https://github.com/izzipizzy/slimtds.git slimtds
cd slimtds
make env
```

`make env` copies `.env.example` to `.env` and generates a random `APP_SECRET` and
`ADMIN_PASSWORD`. It uses the host's PHP if present and otherwise falls back to
`docker run --rm composer:2 php -r …` — which is why §3 comes first. It **refuses to
overwrite an existing `.env`**; if one exists, stop and ask the user before touching it.

Now edit `.env`. Keys that differ by mode:

| Key | `dev` | `cf_flex` | ~~`cf_full`~~ | `direct` |
|---|---|---|---|---|
| `APP_ENV` | `dev` | `prod` | `prod` | `prod` |
| `APP_DEBUG` | `true` | `false` | `false` | `false` |
| `DEPLOY_MODE` | `dev` | `cf_flex` | — | `direct` |
| `DOMAIN` | `slimtds.local` | your domain | your domain | your domain |
| `APP_URL` | `https://slimtds.local` | `https://<domain>` | `https://<domain>` | `https://<domain>` |
| `DB_PASSWORD` | default is fine | **change it** | **change it** | **change it** |

Everything else keeps its `.env.example` value unless §1 supplied one: `APP_TZ`,
`MAXMIND_ACCOUNT_ID` / `MAXMIND_LICENSE_KEY`, `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID`.

> **Change `DB_PASSWORD` before the first start, not after.** PostgreSQL applies
> `POSTGRES_PASSWORD` only when it initializes an empty data directory. Change it later and
> the database keeps the old password while the app sends the new one — authentication fails
> and the only fixes are resetting the password inside Postgres or destroying the volume.

**Real client IP needs no configuration.** Leave `TRUSTED_PROXIES` alone — nothing reads it.
In `cf_flex` `config/frankenphp/Caddyfile.cf` already trusts Cloudflare's ranges and reads
`CF-Connecting-IP`; `src/Shared/RealIp.php` then walks
`X-Slim-IP → X-Real-IP → CF-Connecting-IP → True-Client-IP → X-Forwarded-For → REMOTE_ADDR`.
Never hand-edit a `Caddyfile.*` — `docker/entrypoint.sh` picks the right one from
`DEPLOY_MODE`.

---

## §6 — Start the stack

```bash
make prod-up-direct     # DEPLOY_MODE=direct
make prod-up-cf         # DEPLOY_MODE=cf_flex
make up                 # dev only, local workstation
```

Both `prod-up-*` targets verify `.env` first and refuse to run against the wrong mode. The
first start builds the image (multi-stage: Bun assets → Composer vendor → FrankenPHP runtime)
and takes several minutes. That is expected.

> **Never run a bare `docker compose up` in a prod mode.**
> Compose auto-merges `docker-compose.override.yml`, which bind-mounts `./:/app` over the
> image. `public/assets/` and `public/p.js` are gitignored build artifacts baked in during the
> image build — the bind mount hides them. The result is an admin UI with no CSS and a
> `/p.js` that 404s, while every container reports healthy.
> Always use `make prod-up-*`, or the explicit pair
> `docker compose -f docker-compose.yml -f docker-compose.prod.<direct|cf>.yml …`.

Two consequences worth knowing:

- **No Bun, no Composer, no PHP on the host.** Everything builds inside the image. Do not
  install them.
- **`dev` mode publishes no ports.** `docker-compose.override.yml` gives the `app` service
  domain labels for a local development environment instead of a port mapping. On a plain
  Linux server `dev` is simply unreachable — use `direct`.

`cf_full` is not implemented. It was documented as an HTTPS origin backed by a Cloudflare
Origin Certificate, but `Caddyfile.cf` sets `auto_https off` and declares no `tls` directive,
and no compose file mounts a certificate — so `:443` served plaintext while the guide claimed
encryption. `entrypoint.sh` now refuses to start in that mode. Use `cf_flex`, where Cloudflare
terminates TLS and the origin is HTTP on `:80` by design.

---

## §7 — Initialize

Define the compose pair once so the commands below are literal:

```bash
# direct
DC="docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml"
# cf_flex
DC="docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml"
# dev
DC="docker compose"
```

Then, in order:

```bash
# 1. Schema
$DC run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php

# 2. Partitions — stats.clicks / pixel_events / rrweb data are RANGE-partitioned by month
$DC exec app php bin/console partitions:rotate

# 3. Admin account — reads ADMIN_LOGIN / ADMIN_PASSWORD from .env, first run only
$DC exec app php bin/console admin:init
```

**GeoIP (only if MaxMind credentials were given in §1).** Download once with a plain
`docker run` — this works identically in every mode and puts the databases exactly where the
app reads them (`./geoip-data`, mounted read-only into the container):

```bash
docker run --rm \
  -e GEOIPUPDATE_ACCOUNT_ID="<id>" \
  -e GEOIPUPDATE_LICENSE_KEY="<key>" \
  -e GEOIPUPDATE_EDITION_IDS="GeoLite2-Country GeoLite2-City GeoLite2-ASN" \
  -v "$PWD/geoip-data:/usr/share/GeoIP" \
  maxmindinc/geoipupdate:latest
ls geoip-data/                 # expect three .mmdb files
$DC restart app                # the app opens the databases at boot
```

> The `geoipupdate` service in the prod compose files starts automatically and, with empty
> MaxMind credentials, restart-loops harmlessly. In `direct` mode it also writes into a named
> volume that the app does not read, so its output never reaches the application. The
> `docker run` above is the reliable path in every mode. See §10.

---

## §8 — Smoke test — the definition of done

All six must pass. Anything else is not a finished install.

```bash
DOMAIN=<domain from §1>
```

| # | Check | Command | Pass |
|---|---|---|---|
| 1 | Containers | `$DC ps` | `app`, `db`, `cron` running; `app` is `healthy` |
| 2 | Health endpoint | `curl -sf "https://$DOMAIN/__health" -o /dev/null -w '%{http_code}\n'` | `200` |
| 3 | Migrations | `$DC run --rm --entrypoint="" app vendor/bin/phinx status -c phinx.php` | no migration in `down` state |
| 4 | Admin page | `curl -sI "https://$DOMAIN/admin/login" \| head -1` | `HTTP/2 200` |
| 5 | TLS | `curl -sI "https://$DOMAIN" -o /dev/null -w '%{http_code}\n'` | no certificate error (`cf_flex`: test through Cloudflare — the origin is HTTP by design) |
| 6 | Login works | the sequence below | `302` with `Location: /admin` |

Check 6, spelled out — fetch the form, take the CSRF token, post it back with the same cookie
jar. The hidden field is named `_csrf`:

```bash
JAR=$(mktemp)
CSRF=$(curl -s -c "$JAR" "https://$DOMAIN/admin/login" \
  | grep -oE 'name="_csrf" value="[^"]+"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
  -b "$JAR" -c "$JAR" \
  -d "_csrf=$CSRF" -d "login=admin" --data-urlencode "password=$ADMIN_PASSWORD" \
  "https://$DOMAIN/admin/login"
```

Expect `302 https://$DOMAIN/admin`. A `403` means the CSRF token did not survive the cookie
jar; a `200` means the credentials were rejected and the form was re-rendered. Note that
login is rate-limited to `RATE_LIMIT_LOGIN` attempts per minute (default 5) — if you loop on
this check, wait a minute between attempts.

Also confirm asset delivery, because this is the failure that hides behind six green checks:

```bash
curl -s "https://$DOMAIN/admin/login" | grep -oE '/assets/app\.[a-z0-9]+\.css' | head -1
curl -sI "https://$DOMAIN/p.js" | head -1     # expect 200, not 404
```

A 404 here means the dev override was merged — see §6 and §10.

---

## §9 — Report to the user

Final message, in this shape:

```
slimTDS is installed.

  URL       https://<domain>/admin/login
  Login     admin
  Password  <the generated password — printed once, tell them to change it>
  Mode      <direct|cf_flex>

Enabled:  <geo targeting | Telegram notifications | — none of the optional integrations>
Backups:  ./var/backups on the host (daily cron, last 7 kept)

Smoke test:
  1. Containers healthy      PASS
  2. /__health               PASS
  3. Migrations applied      PASS
  4. /admin/login reachable  PASS
  5. TLS valid               PASS
  6. Admin login             PASS

Next steps:
  - Change the admin password at /admin/settings
  - Create your first campaign at /admin/campaigns
  - Point a traffic domain at this server; the engine answers on /<campaign-slug>
```

Report failures in the same table rather than omitting them.

---

## §10 — Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Admin loads unstyled; `/p.js` returns 404 | A bare `docker compose up` merged `docker-compose.override.yml` and bind-mounted the source over the built image | `make prod-down`, then `make prod-up-direct` / `make prod-up-cf` (§6) |
| `app` container restart-loops | Worker-mode bootstrap error (bad `.env` value, missing migration) | Read `make logs`; set `FRANKENPHP_WORKER_MODE=0` in `.env` to fall back to classic mode and see the real error, then fix it and switch back |
| Let's Encrypt never issues a certificate | DNS does not point here, or port 80 is occupied | Re-run the §2 DNS and port checks. Caddy needs :80 reachable for the ACME challenge |
| Database authentication fails | `DB_PASSWORD` was changed after the volume was initialized | Either set it back, or `$DC down -v` (**destroys all data**) and start over |
| Visitor IP is the proxy's IP | `DEPLOY_MODE` is not `cf_flex`, so `entrypoint.sh` chose a Caddyfile without the Cloudflare trusted-proxy block. **Not** a `TRUSTED_PROXIES` problem — that key does nothing | Set `DEPLOY_MODE=cf_flex`, restart |
| Geo filters never match | `.mmdb` files are missing; `GeoLookup` no-ops by design when they are | Run the `docker run … geoipupdate` command in §7, then restart `app` |
| `geoipupdate` container restart-loops | It starts with the prod stack and has no MaxMind credentials | Harmless. Either add the credentials to `.env` or `$DC stop geoipupdate` |
| GeoIP downloaded but filters still fail in `direct` mode | The compose `geoipupdate` service writes to a named volume; the app reads the `./geoip-data` bind mount | Use the `docker run` form in §7 — it writes to the right place |
| Clicks are not recorded; partition errors in the log | `partitions:rotate` was never run | `$DC exec app php bin/console partitions:rotate` (§7) |
| Port 80/443 already in use | An existing nginx/apache/Caddy on the box | Stop that service or choose a different host. Do not silently reconfigure someone else's web server |
| `make env` says `.env already exists` | A previous install attempt | Stop and ask the user before overwriting — it holds `APP_SECRET`, and rotating it invalidates every session |

Useful while debugging:

```bash
$DC logs --tail=100 app
$DC logs --tail=50 db
$DC exec app php bin/console list      # every available console command
$DC exec app php bin/console db:backup # dump to ./var/backups before anything risky
```
