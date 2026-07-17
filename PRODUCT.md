# Design context — slimTDS admin

## Target audience
Operator-class users: affiliate marketers / traffic arbitrage specialists. Tech-savvy. Spend hours per day reading dense data tables, configuring filters, monitoring conversions. Russian + English speakers.

## Use cases
- Configure many campaigns, offers, flows quickly (CRUD-heavy workflows)
- Read dense tabular data (clicks, conversions, postbacks) — prefer density over whitespace
- Build complex filter/routing rules visually (Alpine flow-builder)
- Monitor live state (status indicators, recent events, counts)

## Tone & aesthetic direction
**Refined operator / editorial-utility.** Inspired by Linear's density, Vercel's clarity, and old-school terminal tools — but warm and human, not cyan-on-dark "AI slop".

Key principles:
- Warm stone/cream background + cool slate text — no pure white/black
- Single muted accent (terracotta/burnt orange) — restraint over neon
- Dense tabular layouts with `tabular-nums` for IDs and counts
- Variable display font for headings (Bricolage Grotesque) + readable body sans (Manrope) + mono for IDs/code (JetBrains Mono)
- Sidebar navigation with clear hierarchy, no overdesigned dashboard cards
- Asymmetric content layouts — main content does NOT fight for attention with chrome

## What to avoid
- Cyan / purple / neon gradient accents
- Inter / Roboto / system defaults
- Glassmorphism, glow borders, pure dark mode by default
- Identical card grids with icon + heading + text
- Rounded cards everywhere — embrace flat sections divided by hairlines instead
- Centred content — left-align everything; tables fill available width

## Brand element
Single editorial detail: an angular, slightly-italic "/" or accent rule that recurs in headings — a quiet signature.
