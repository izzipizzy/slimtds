#!/usr/bin/env bash
#
# Benchmark the slimTDS engine hot-path (GET /<slug>).
#
# Runs the fortio load tester in a throwaway container attached to the running
# app container's network namespace, hitting the engine directly on its internal
# HTTP port. This measures the engine itself and sidesteps TLS / published-port
# differences between deploy modes. fortio is used (not wrk) because its image is
# multi-arch, so this works on arm64 (Apple Silicon) as well as amd64 servers.
#
# Usage:
#   make benchmark SLUG=<slug>                          # 50 conns, 15s
#   CONNS=100 DURATION=30s make benchmark SLUG=<slug>
#
# Env / make vars:
#   SLUG       campaign slug to hit          (required, e.g. SLUG=demo)
#   CONNS      concurrent connections        (default 50)
#   DURATION   test duration                 (default 15s)
#   PORT       app internal HTTP port        (default 80)
#   APP        app container id/name         (default: docker compose ps -q app)
#
# Caveats (see docs -> Hardware & Benchmarks):
#   * Each request logs a click to stats.clicks — benchmark a dev/staging
#     instance or a throwaway slug, never your live money-path.
#   * The load generator shares the host CPU with the server, so the result is a
#     conservative floor; a dedicated client machine shows higher throughput.
#   * Make sure worker mode is engaged, or you are timing per-request bootstrap
#     rather than the engine.
set -euo pipefail

SLUG="${SLUG:-${1:-}}"
CONNS="${CONNS:-50}"
DURATION="${DURATION:-15s}"
PORT="${PORT:-80}"
UA="${UA:-Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36}"
FORTIO_IMAGE="${FORTIO_IMAGE:-fortio/fortio}"

if [ -z "$SLUG" ]; then
  echo "error: SLUG is required — e.g. 'make benchmark SLUG=demo'" >&2
  exit 1
fi

APP="${APP:-$(docker compose ps -q app 2>/dev/null || true)}"
if [ -z "$APP" ]; then
  echo "error: app container not found — is the stack up? ('make up')" >&2
  exit 1
fi

URL="http://127.0.0.1:${PORT}/${SLUG}"
echo "▶ target: ${URL}  (via app container network)"
echo "▶ load:   ${CONNS} connections, ${DURATION}"
echo "▶ running (fortio warms up automatically)…"
echo

OUT="$(docker run --rm --network "container:${APP}" "$FORTIO_IMAGE" load \
        -c "${CONNS}" -t "${DURATION}" -qps 0 -allow-initial-errors -loglevel error \
        -H "User-Agent: ${UA}" "${URL}" 2>&1 || true)"

tp="$(printf '%s\n' "$OUT" | grep -oE '[0-9.]+ qps' | head -1)"
if [ -z "$tp" ]; then
  echo "could not parse results — raw fortio output:" >&2
  printf '%s\n' "$OUT" | tail -25 >&2
  exit 1
fi

avg="$(printf '%s\n' "$OUT" | grep -oE '[0-9.]+ ms avg' | head -1)"
echo "──────────────────────────────────────────────"
echo "Throughput : ${tp}"
echo "Avg latency: ${avg:-n/a}"
echo "Latency percentiles:"
printf '%s\n' "$OUT" | LC_ALL=C awk '/^# target [0-9]/ {printf "  p%-6s %8.2f ms\n", $3, $4*1000}'
echo "Response codes:"
printf '%s\n' "$OUT" | grep -E '^Code [0-9]' | sed 's/^/  /'
echo "──────────────────────────────────────────────"
echo "note: engine returns 403 (no-flow trash) or 30x (redirect) — both run the"
echo "full pipeline incl. the click INSERT, so this reflects real hot-path cost."
