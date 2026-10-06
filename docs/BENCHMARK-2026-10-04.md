# Before/after engine benchmark — 2026-10-04

Baseline: repository HEAD `a367ffc`, exact archived source and original Composer/Bun locks, PHP 8.4.24 / FrankenPHP 1.12.6. Updated: approved dependency/removal worktree, PHP 8.5.11 / FrankenPHP 1.12.7 / Slim 4.15.3. Baseline asset build was made to consume its original frozen Bun lock: the original Dockerfile ignored that lock, which would have allowed newly resolved dependencies into the “before” image.

Measured image IDs: before `sha256:b124d67c6c4b1a1fbe9d36b4468a215a2f266720525609a769f5cbb91b59c65f`; after `sha256:00534087ab8f643650c13d091d48aa3e50c841657968a2641d69413d9e274bed`. Later image rebuilds add reports/test tooling without changing the measured application runtime behavior.

Both use separate copies of the same production backup (filename omitted), PostgreSQL 18.6, the same read-only GeoIP files, dummy runtime credentials and an internal Docker network with no cron. Only the new copy has the Clickunder-removal migration. This is not a direct comparison with release 0.7.3. This compares the complete application/runtime change; it does not isolate Slim or PHP or compare PostgreSQL major versions.

Host: Apple M4 Pro; OrbStack Docker aarch64 VM, 6 CPUs, 16.82 GB RAM. Each app: 2 CPUs / 1 GiB, 8 FrankenPHP workers, same worker configuration and OPcache/JIT. Each DB: 2 CPUs / 2 GiB. Fortio client: 2 CPUs / 256 MiB, shares the host. Other existing unrelated containers were left running; shared-host scheduling and I/O can affect results.

Fortio image: `fortio/fortio@sha256:942c86131c24ee9bd4a7754610be417ac1b9fe2f11d1860620a9f6932ceb712f`. Internal HTTP, keep-alive, no redirect following, fixed browser UA. Each result is a median of three 10-second unlimited-QPS runs, at 16 or 50 connections. Order alternates old/new → new/old → old/new; Fortio's connection warmup runs before measurement. Only synthetic benchmark campaign clicks are deleted before each run; existing copied production traffic stays intact. HTTP 302/403 are expected responses, not errors. No builds or test suites ran concurrently with measured trials.

## Results

| Scenario | Connections | Before req/s | After req/s | Change | Before avg ms | After avg ms | Before p99 ms | After p99 ms |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Redirect 302 | 16 | 1581.9 | 1698.6 | +7.4% | 10.10 | 9.42 | 33.57 | 33.61 |
| Redirect 302 | 50 | 1574.4 | 1685.9 | +7.1% | 31.71 | 29.63 | 60.08 | 58.01 |
| No matching flow 403 | 16 | 1589.0 | 1774.4 | +11.7% | 10.07 | 9.01 | 32.95 | 32.97 |
| No matching flow 403 | 50 | 1679.8 | 1776.8 | +5.8% | 29.73 | 28.11 | 58.76 | 58.04 |

Across 24 measured trials, all 400100 measured responses have the expected code; zero unexpected responses/transport errors. Average and p99 columns are medians of each run’s own statistics, not pooled request distributions.

## Variation and interpretation

| Scenario | Connections | Before req/s range | After req/s range |
|---|---:|---:|---:|
| Redirect 302 | 16 | 1557.1–1693.9 | 1529.9–1733.7 |
| Redirect 302 | 50 | 1571.1–1670.6 | 1640.6–1721.3 |
| No matching flow 403 | 16 | 1504.6–1680.6 | 1475.1–1785.9 |
| No matching flow 403 | 50 | 1667.6–1771.1 | 1742.0–1822.7 |

These short local samples measure the engine pipeline, including the click INSERT, with one synthetic route/offer fixture over a restored database. They do not represent every production campaign or authenticated report/rrweb workload. Ranges overlap for several scenarios, so the median differences should not be treated as a proven general speedup. The updated stack is comparable under this workload; no collapse in throughput or unexpected HTTP failures was observed. Tail latency and wider production workloads need longer dedicated-host trials before drawing a capacity conclusion.

## Evidence and reproduction

Per-run numerical results: [results.csv](benchmarks/2026-10-04/results.csv). Full Fortio JSON/logs, fixture SQL, validation Compose, original archive and comparison driver are retained outside the public repository in the isolated validation workspace (`benchmarks/`, `compare-bench.py`, `bench-fixture.sql`, `compose.yml`). The comparison driver deletes only the fixed synthetic campaign IDs on the isolated old/new DBs; do not run it against an operator database.

Each measured trial invokes:

```bash
docker run --rm --cpus=2 --memory=256m \
  --network container:slimtds-validation-app-old-1 \
  -v "$RESULTS:/results" \
  fortio/fortio@sha256:942c86131c24ee9bd4a7754610be417ac1b9fe2f11d1860620a9f6932ceb712f \
  load -c 16 -t 10s -qps 0 -allow-initial-errors -loglevel error \
  -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36' \
  -json /results/TRIAL.json http://127.0.0.1/benchroute
```

Use `app-new-1`, 50 connections and `benchmiss` for the other cells, alternating versions per repetition. Restore the same backup into fresh volumes and apply only the new migration on the updated side before a clean repeat. Raw results are retained for inspection rather than only reporting the best trial.
