---
name: slimtds-traffic-analysis
description: Analyse traffic of a slimTDS instance through its MCP server — campaign reviews, bot and junk audits, funnel leaks, period comparisons. Use when the user asks about clicks, conversions, CR, EPC, landers, offers, flows or bots in slimTDS, or mentions the slimtds MCP tools.
---

# slimTDS traffic analysis

slimTDS routes visitors: a campaign link (`/<slug>`) receives a click, the campaign's flows are tried in order, the first flow whose filters match picks an offer by weight, the visitor is redirected, and the affiliate network later reports a conversion by postback. A pixel on the landers records what happened before the click.

All tools are read-only. If they are missing, the `slimtds` MCP server is not connected — tell the user to open Settings → MCP in their slimTDS admin.

## Vocabulary

- **Trash** — a click no flow matched. It went to the campaign's trash mode, not to an offer. High trash means the flows' filters do not cover the traffic that arrives.
- **Bot** — flagged by IP list, ASN or user agent. Excluded by default; pass `bots=only` or `bots=include`.
- **Unique** — first click of the visitor in the campaign's uniqueness window.
- **CR** — approved conversions ÷ clicks. **EPC** — approved payout ÷ clicks.
- **Entry source** — the search engine the visitor came from originally, recorded by the pixel. A click's own `referer` is usually just the lander.
- `traffic_summary` counts conversions **of the clicks made in the period**. `conversions_summary` counts conversions **that arrived in the period**, including postbacks with no click. They differ, and both are right.

## Method

1. `list_campaigns` — get slugs and see where the volume is. Never guess a slug.
2. `traffic_summary` for the campaign and period. Note clicks, CR, EPC, and the bot and trash counters next to them.
3. `traffic_timeline` when the question is "what changed" — find the hour or day first, then explain it.
4. `traffic_breakdown`, one dimension per call. Useful order: `country`, `device`, `referer_domain`, `lander`, `offer`, `flow`. Drill down by adding a filter, for example `dimension=offer` with `country=ar`.
5. `get_campaign` before recommending any flow change, so the advice refers to flows and filters that exist.
6. `list_clicks` and `visitor_journey` to check a hypothesis on real rows before stating it.

## Workflows

**Campaign review.** Steps 1–5, then report: what the traffic is, where it converts, where it does not, how much is bots or trash, and up to three changes, each backed by a number from the tools.

**Bot and junk audit.** Compare `traffic_summary` with `bots=only` and `bots=exclude`. Break down `bots=only` by `bot_name`, `asn`, `country`. Then look inside `bots=exclude` for unflagged junk: an ASN or referer with many clicks, a very low unique share, and no conversions. Confirm with `list_clicks` before calling it junk.

**Funnel leaks.** `pixel_summary` gives lander visitors and the share that clicked. `traffic_breakdown` by `lander` and by `offer` gives click → conversion. `conversions_summary` shows whether conversions are stuck in `pending` or `hold`, or rejected. Name the weakest step and what is responsible for it.

**Period comparison.** Call `traffic_summary` twice with explicit `from` / `to` of equal length. Then repeat the one `traffic_breakdown` that explains the difference.

## Reporting

- Give numbers with their period and filters. "CR 2.1% over 30d, bots excluded" — not "CR is low".
- Do not draw conclusions from rows with a handful of clicks; say the sample is too small.
- Dates are in the instance time zone, returned in every result as `period.timezone`.
- IP addresses are masked to the network unless the owner enabled full addresses. Do not try to work around it.
- You cannot change anything. Recommendations are for the user to apply in the admin UI.
- `traffic_summary` won't match the admin Clicks page unless the filters match: the tool defaults to excluding bots and trash and counts clicks regardless of fingerprint, while the admin page defaults to fingerprinted-only and includes bots and trash.
