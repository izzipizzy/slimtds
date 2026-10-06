# MCP server

slimTDS exposes a read-only [Model Context Protocol](https://modelcontextprotocol.io)
server at `POST /mcp` so an AI client — Claude Code, Codex, Cursor, VS Code,
Windsurf, Claude Desktop and similar — can query a running instance's traffic
reports directly, without anyone hand-copying numbers into a chat. It is not a
general database connector: nine fixed report tools cover campaigns, clicks,
conversions and pixel events, there is no raw SQL, and no tool writes
anything.

## What it is

slimTDS routes traffic through campaigns, flows and offers; the MCP server
lets a model read the results of that routing — clicks, conversions, bot and
trash volume, pixel events — the same way the admin UI's reports do, through
the same repositories, so the numbers never drift apart.

Every `tools/call` runs inside a transaction with
`SET LOCAL transaction_read_only = on` and a 15-second
`SET LOCAL statement_timeout`, so the read-only guarantee is a property of the
database connection itself, not just a promise the code makes. A bug in a
tool cannot change data, and a slow query cannot hang the connection.

**Protocol:** Streamable HTTP, stateless — no sessions, no SSE stream — with
JSON-RPC 2.0 messages over `POST`. Protocol versions `2025-11-25`,
`2025-06-18` and `2025-03-26` are accepted as requested; anything else gets
`2025-06-18` back.

## Turn it on

**Settings → MCP → Generate key.**

The plaintext key is shown exactly once, on the page rendered right after
generation. Copy it now — only its SHA-256 hash is stored, so it cannot be
shown again. Afterwards the settings tab shows just the key's prefix, when it
was created and when it was last used.

- **Regenerate** issues a new key immediately; the old one stops working at
  once, so every connected client needs the new value.
- **Revoke** turns the feature off entirely. `/mcp` goes back to answering
  `404` until a key exists again — the endpoint does not exist until the
  owner turns it on.

## Connect a client

The settings tab fills in your instance's own address and, right after
generation, the new key. Elsewhere in this doc the placeholders
`https://tds.example.com` and `stds_YOUR_KEY` stand in for those two values.

**Claude Code**

```bash
claude mcp add --transport http slimtds https://tds.example.com/mcp \
  --header "Authorization: Bearer stds_YOUR_KEY"
```

**Codex** — `~/.codex/config.toml`:

```toml
[mcp_servers.slimtds]
url = "https://tds.example.com/mcp"
bearer_token_env_var = "SLIMTDS_API_KEY"
```

and export the key in your shell profile:

```bash
export SLIMTDS_API_KEY="stds_YOUR_KEY"
```

**Cursor, VS Code, Windsurf** — `mcp.json`:

```json
{
    "mcpServers": {
        "slimtds": {
            "url": "https://tds.example.com/mcp",
            "headers": {
                "Authorization": "Bearer stds_YOUR_KEY"
            }
        }
    }
}
```

**Claude Desktop** — `claude_desktop_config.json` (it speaks stdio only, so
`mcp-remote` bridges to the HTTP endpoint):

```json
{
    "mcpServers": {
        "slimtds": {
            "command": "npx",
            "args": [
                "-y",
                "mcp-remote",
                "https://tds.example.com/mcp",
                "--header",
                "Authorization: Bearer stds_YOUR_KEY"
            ]
        }
    }
}
```

Confirm the connection: `claude mcp list` should show `slimtds` as connected;
inside a Claude Code session, `/mcp` lists it along with its nine tools.

`claude.ai` in the browser is **not supported** — custom connectors there
require OAuth, which this server does not implement. Use one of the clients
above instead.

## Agent skill

Beyond the tools themselves, slimTDS ships two more layers of guidance so a
client that never reads a skill still knows how to use the reports well: the
MCP `initialize` result carries a short `instructions` paragraph, and three
ready-made prompts — `traffic_review` (argument `campaign`, optional
`period`), `bot_audit` and `funnel_leaks` — which Claude Code surfaces as
slash commands (`/mcp__slimtds__traffic_review`, and so on).

For the long version — vocabulary, a step-by-step method, and full workflows
for campaign reviews, bot audits and funnel leaks — install the skill.
`GET /mcp/skill` serves `SKILL.md` as plain text with no authentication (it
holds no secrets and ships in the public repository already; it 404s while
MCP is off):

```bash
mkdir -p ~/.claude/skills/slimtds-traffic-analysis && \
  curl -fsSL https://tds.example.com/mcp/skill -o ~/.claude/skills/slimtds-traffic-analysis/SKILL.md

mkdir -p ~/.codex/skills/slimtds-traffic-analysis && \
  curl -fsSL https://tds.example.com/mcp/skill -o ~/.codex/skills/slimtds-traffic-analysis/SKILL.md
```

## Tools

All nine tools carry `annotations.readOnlyHint = true`. Every result comes
back both as `structuredContent` and as the same JSON in a `text` content
block, for clients that only read one of the two.

| Tool | Returns | Arguments |
|---|---|---|
| `list_campaigns` | Every campaign with its slug, status, number of flows, clicks, approved conversions and revenue in the period. Start here: the slug is what the other tools take as `campaign`. | `period`, `from`, `to` |
| `get_campaign` | One campaign with its flows in matching order: each flow's filters (AND inside a group, OR across groups), target offers with weights, and what happens to clicks no flow matches (trash mode). | `campaign` (required) |
| `traffic_summary` | KPIs for a period: clicks, unique visitors, conversions (with a per-status split: approved, pending, hold, rejected), approved revenue, CR and EPC, plus how many bot and trash clicks fall under the same filters. Call it twice to compare periods. | the common arguments |
| `traffic_timeline` | Clicks, unique clicks and bot clicks per time bucket. Hourly for periods up to 8 days, daily beyond. Set `bots=include` to see the bot series. | the common arguments |
| `traffic_breakdown` | Clicks grouped by one dimension, with unique clicks, conversions, approved revenue and CR per row, largest first. | `dimension` (required, one of `country`, `city`, `device`, `os`, `browser`, `referer_domain`, `utm_source`, `lander`, `offer`, `flow`, `asn`, `bot_name`, `hour_of_day`), the common arguments, `limit` (rows, default 50) |
| `list_clicks` | Individual clicks, newest first, under the same filters as the reports — for checking a hypothesis on real rows, not for counting. | the common arguments, `limit` (rows per page, default 50), `page` (default 1) |
| `visitor_journey` | Everything one visitor did, newest first: lander pageviews, clicks and conversions. Identify the visitor by `visitor_uuid` or by `fp_js` (the browser fingerprint, survives cleared cookies); both come from `list_clicks`. | `visitor_uuid`, `fp_js` (one of the two required), `days` (default 30, max 90), `limit` (default 80, max 200) |
| `conversions_summary` | Conversions that **arrived** in the period, by status (approved, pending, hold, rejected) and by offer and campaign. Includes postbacks with no matching click, so totals can differ from `traffic_summary`, which counts conversions of the clicks **made** in the period. | `period`, `from`, `to`, `campaign` |
| `pixel_summary` | What happened on the landers before the click: pixel events by name, the most visited pages, and the share of lander visitors who went on to click into the campaign. | `period`, `from`, `to`, `campaign`, `country`, `bots`, `limit` (pages, default 50) |

`traffic_breakdown`'s dimension is mapped to a SQL column through a fixed
whitelist in code — no argument ever reaches the database as an identifier.
`entry_source` filters traffic but is not itself a breakdown dimension.

In `traffic_summary`, `bot_clicks` counts bots including trash, while
`trash_clicks` follows the `bots` filter (default: excludes bots).

## Common arguments

Accepted wherever they make sense, across the tools above:

| Argument | Meaning | Default |
|---|---|---|
| `period` | Rolling window: `today`, `yesterday`, `7d`, `30d`, `90d`. Ignored when `from`/`to` are given. | `7d` |
| `from` / `to` | Explicit days, `YYYY-MM-DD`, in the instance's own time zone. `from` is clamped to the first day of data on record. | — |
| `campaign` | Slug, alias or UUID. Omit for all campaigns. | all campaigns |
| `country` | ISO 3166-1 alpha-2 code. | — |
| `device` | Device class, as reported by `traffic_breakdown` with `dimension=device`. | — |
| `entry_source` | The search engine a visitor originally came from, recorded by the pixel: `any`, `none`, or an engine key such as `google`. | — |
| `bots` | `exclude`, `include`, or `only`. | `exclude` |
| `trash` | Clicks that matched no flow: `exclude`, `include`, or `only`. | `exclude` |
| `limit` | Row cap, name and default vary slightly by tool (see the table above). | 50, max 200 |

## Security

- The key is stored as a SHA-256 hash only — no salt, since the key itself is
  a 238-bit random secret. Only the SHA-256 hash is stored in settings. The
  plaintext is held in your admin session for at most five minutes, until the
  page that shows it is rendered once, and nowhere else.
- `/mcp` answers `404` until a key has been generated: the endpoint does not
  exist until the owner turns the feature on.
- A missing or wrong key gets `401` with a `WWW-Authenticate: Bearer`
  challenge.
- Rate limits, both returning `429` with `Retry-After`: 10 failed
  authentication attempts per minute, keyed on the connecting address as the
  web server reports it (`REMOTE_ADDR`): the real client in the shipped
  Cloudflare and direct modes; behind a proxy that does not rewrite it,
  everyone shares the proxy's counter — and 120 requests per minute for the
  key overall. A valid key is always accepted, even from a currently
  locked-out address; the lockout only ever answers requests that do not
  carry the right key.
- IPv4 addresses are masked to `/24` and IPv6 to `/48` by default in
  `list_clicks`, the only tool that returns an IP address, because this data
  leaves the instance for an LLM provider. The **"Return full IP addresses"**
  checkbox on the MCP settings tab turns masking off for the whole key.
- `list_clicks` returns `referer` and `out_url` verbatim: an offer URL into
  which the operator embedded a secret (e.g. a token in the query string)
  would be visible to the model.
- `GET` / `DELETE /mcp` answer `404` while the endpoint is disabled (every
  method does), and `405` with `Allow: POST` once a key exists — this server
  has no notification stream to open and no session to end, so only `POST`
  is meaningful.
- Never returned by any tool, regardless of arguments: offer postback
  tokens, a conversion's `raw_query`, password hashes, or session data.
- Key generation and revocation are written to the audit log
  (`mcp_key_generated`, `mcp_key_revoked`) with the admin login, IP and user
  agent — never the plaintext key, only its prefix.

## Troubleshooting

- **404 on `/mcp`** — no key has been generated yet. Settings → MCP →
  Generate key.
- **401** — either the key is wrong, or a proxy in front of the app is
  stripping the `Authorization` header before it reaches slimTDS. Check the
  header actually arrives (some reverse-proxy configurations drop
  non-standard headers by default).
- **403 behind Cloudflare** — Bot Fight Mode treats an MCP client as a bot.
  Add a WAF rule that skips `/mcp` from Bot Fight Mode / Super Bot Fight
  Mode.
- **405 opening `/mcp` in a browser** — expected once a key exists: a plain
  `GET` isn't a valid MCP request, and this server has nothing to stream or
  a session to open, so it answers `405` with `Allow: POST` rather than
  pretend the method works.
- **429** — two different causes. Either a wrong key was tried from an
  address that has already failed 10 times this minute — wait for
  `Retry-After`, then fix the key — or a *valid* key made more than 120
  requests in a minute. Wait for `Retry-After` either way.
- **400 instead of a JSON-RPC `-32700` parse error** — a malformed JSON body
  sent with `Content-Type: application/json` is rejected by the framework's
  own body parsing before the request ever reaches the JSON-RPC layer, so it
  comes back as a plain `400`, not a JSON-RPC error object.
- **claude.ai in the browser doesn't see it** — custom connectors on the web
  app require OAuth, which this server does not implement. Use Claude Code,
  Codex, Cursor, VS Code, Windsurf or Claude Desktop (via `mcp-remote`)
  instead.
- **The numbers differ from the admin Clicks page** — same query, verified
  to match exactly, but different defaults. `traffic_summary` excludes bots
  and trash (clicks no flow matched) by default, and counts clicks with or
  without a browser fingerprint. The admin Clicks page by default shows only
  clicks that carry a fingerprint, and includes bots and trash. Set the same
  filters on both sides — in the admin: humans only, routed only,
  fingerprint filter "any" — and the KPIs match.
