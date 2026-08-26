# Build identity, computed on the host where git exists. Exported so both
# `docker compose build` (build args) and the dev overlay (runtime env) see the
# same values. A checkout with no tags yields a bare hash, which the app
# reports as a source build — never as a version it does not have.
# --match 'v*' on purpose: `git describe --tags` takes the nearest reachable
# tag of ANY kind, so a `rollback/...` tag left on a production checkout
# becomes the reported version. Only release tags may name a build.
GIT_VERSION := $(shell git describe --tags --always --dirty --match 'v*' 2>/dev/null)
GIT_COMMIT  := $(shell git rev-parse --short HEAD 2>/dev/null)
GIT_DATE    := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)
export APP_VERSION    := $(GIT_VERSION)
export APP_COMMIT     := $(GIT_COMMIT)
export APP_BUILD_DATE := $(GIT_DATE)
export APP_BUILD_KIND := source

.DEFAULT_GOAL := help
.PHONY: help env up down restart logs shell psql migrate seed seed-fresh test test-up test-down test-unit test-integration test-arch test-browser test-publish release-publish stan benchmark build build-assets clean pixel-test-up pixel-test-down prod-up-cf prod-up-direct prod-down deploy seed-stats demo-up demo-down

## help — Show this help
help:
	@grep -hE '^## ' $(MAKEFILE_LIST) | sed 's/## //'
	@echo ""
	@echo "Targets (make <target>):"
	@grep -hE '^[a-z][a-zA-Z0-9_-]+:' $(MAKEFILE_LIST) | sed 's/:.*//' | sort -u | sed 's/^/  /'

## env — Copy .env.example → .env and generate random secrets
env:
	@if [ -f .env ]; then echo ".env already exists; not overwriting"; exit 0; fi
	@cp .env.example .env
	@APP_SECRET=$$(php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || docker run --rm composer:2 php -r 'echo bin2hex(random_bytes(32));'); \
	 ADMIN_PASSWORD=$$(php -r 'echo bin2hex(random_bytes(8));' 2>/dev/null || docker run --rm composer:2 php -r 'echo bin2hex(random_bytes(8));'); \
	 awk -v s="$$APP_SECRET" -v p="$$ADMIN_PASSWORD" \
	     '/^APP_SECRET=/ {print "APP_SECRET=" s; next} /^ADMIN_PASSWORD=/ {print "ADMIN_PASSWORD=" p; next} {print}' \
	     .env > .env.tmp && mv .env.tmp .env
	@echo ".env generated. Admin password is in .env (grep ADMIN_PASSWORD)"
	@grep -E '^(APP_SECRET|ADMIN_PASSWORD)=' .env | head -c 80 && echo "..."

## up — Start dev stack
up:
	docker compose up -d
	@echo ""
	@echo "Stack up. https://slimtds.local/admin/login"

## down — Stop stack
down:
	docker compose down

## restart — Recreate app container (pick up code changes)
restart:
	docker compose up -d --force-recreate --no-deps app

## logs — Tail app logs
logs:
	docker compose logs -f app

## shell — Shell into app container
shell:
	docker compose exec app sh

## psql — Open psql shell
psql:
	docker compose exec db psql -U slimtds -d slimtds

## migrate — Run Phinx migrations
migrate:
	docker compose run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php

## seed — Populate dev data (idempotent)
seed:
	docker compose exec app php bin/console db:seed

## seed-fresh — Wipe campaigns/offers/flows then re-seed
seed-fresh:
	docker compose exec app php bin/console db:seed --fresh

## seed-stats — seed demo statistics (clicks/conversions/pixel) over a rolling 30d window
seed-stats:
	docker compose exec app php bin/console db:seed-stats --fresh

## test — Run unit + integration + arch tests against isolated db-test
test: test-up
	docker compose exec app ./vendor/bin/pest --testsuite=Unit
	docker compose exec app ./vendor/bin/pest --testsuite=Integration
	docker compose exec app ./vendor/bin/pest --testsuite=Arch

## test-up — Bring up db-test container and run migrations
test-up:
	docker compose --profile test up -d db-test
	@for i in 1 2 3 4 5 6 7 8 9 10; do \
		docker compose exec db-test pg_isready -U slimtds -d slimtds_test 2>/dev/null && break; \
		sleep 1; \
	done
	docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
		app vendor/bin/phinx migrate -c phinx.php -e test
	docker compose exec -e 'DB_DSN=pgsql:host=db-test;port=5432;dbname=slimtds_test' \
		app php bin/console partitions:rotate

## test-down — Stop db-test (test data is on tmpfs, gone with the container)
test-down:
	docker compose --profile test down db-test

## test-unit — Run unit tests only
test-unit:
	docker compose exec app ./vendor/bin/pest --testsuite=Unit

## test-integration — Run integration tests only
test-integration:
	docker compose exec app ./vendor/bin/pest --testsuite=Integration

## test-arch — Run architecture tests only
test-arch:
	docker compose exec app ./vendor/bin/pest --testsuite=Arch

## test-browser — Run browser tests (requires BROWSER_TESTS=1 + Playwright)
test-browser:
	docker compose exec -e BROWSER_TESTS=1 app ./vendor/bin/pest --testsuite=Browser

## release-publish — Publish to GitHub (usage: make release-publish VERSION=0.7.1 [DRY=1])
release-publish:
	@[ -n "$(VERSION)" ] || { echo "usage: make release-publish VERSION=x.y.z [DRY=1]"; exit 1; }
	bash scripts/publish.sh $(if $(DRY),--dry-run,) $(VERSION)

## test-publish — Run the scripts/publish.sh tests (on the host, not in Docker)
test-publish:
	bash tests/publish/run.sh

## stan — PHPStan level 6
stan:
	docker compose exec app vendor/bin/phpstan analyse --memory-limit=512M

## benchmark — Load-test the engine hot-path with fortio (usage: make benchmark SLUG=<slug> [CONNS=50] [DURATION=15s])
benchmark:
	@SLUG="$(SLUG)" CONNS="$(CONNS)" DURATION="$(DURATION)" THREADS="$(THREADS)" PORT="$(PORT)" ./scripts/benchmark.sh

## build — Rebuild Docker image
build:
	docker compose build app

## build-assets — Rebuild frontend assets (Bun + Tailwind)
build-assets:
	docker run --rm -v "$(shell pwd):/app" -w /app oven/bun:1-alpine sh -c "bun install && bun run build"

## pixel-test-up — Start the 4-domain pixel test stand (lander-{a,b,c,d}.local, 3 pages each)
pixel-test-up:
	docker compose -f docker-compose.pixel-test.yml up -d
	@echo ""
	@echo "Open https://lander-a.local/  (also lander-{b,c,d}.local). Each lander has /, /about, /pricing."
	@echo "Watch events at https://slimtds.local/admin/pixel"

## pixel-test-down — Stop the pixel test stand
pixel-test-down:
	docker compose -f docker-compose.pixel-test.yml down

## prod-up-cf — Start prod stack behind Cloudflare (set DEPLOY_MODE=cf_flex + DOMAIN in .env)
prod-up-cf:
	@grep -qE '^DEPLOY_MODE=cf_flex' .env 2>/dev/null || { echo "Set DEPLOY_MODE=cf_flex in .env first (cf_full is not implemented — see docs/DEPLOYMENT.md)"; exit 1; }
	docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml up -d
	@echo ""
	@echo "Prod stack (Cloudflare) up. See docs/DEPLOYMENT.md."

## prod-up-direct — Start prod stack with Caddy auto-TLS (set DEPLOY_MODE=direct + DOMAIN in .env)
prod-up-direct:
	@grep -qE '^DEPLOY_MODE=direct' .env 2>/dev/null || { echo "Set DEPLOY_MODE=direct in .env first"; exit 1; }
	@grep -qE '^DOMAIN=' .env 2>/dev/null || { echo "Set DOMAIN=your.domain.tld in .env first"; exit 1; }
	docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml up -d
	@echo ""
	@echo "Prod stack (direct) up. Caddy will provision Let's Encrypt cert for $$(grep ^DOMAIN= .env | cut -d= -f2)."

## prod-down — Stop the active prod stack (auto-detects CF vs direct from .env)
prod-down:
	@if grep -qE '^DEPLOY_MODE=cf' .env 2>/dev/null; then \
		docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml down; \
	elif grep -qE '^DEPLOY_MODE=direct' .env 2>/dev/null; then \
		docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml down; \
	else \
		echo "DEPLOY_MODE in .env is neither 'cf*' nor 'direct'; pick one and re-run."; exit 1; \
	fi

## demo-up — bring up the self-resetting demo stack (run on the demo VPS)
demo-up:
	@grep -qE '^DEMO_MODE=1' .env 2>/dev/null || { echo "Set DEMO_MODE=1 in .env first"; exit 1; }
	@grep -qE '^DEMO_DOMAIN=.' .env 2>/dev/null || { echo "Set DEMO_DOMAIN=demo.your.tld in .env first"; exit 1; }
	docker compose -f docker-compose.yml -f docker-compose.demo.yml up -d --build
	@echo ""
	@echo "Demo stack up. Caddy will provision Let's Encrypt cert for $$(grep ^DEMO_DOMAIN= .env | cut -d= -f2). Resets every 4h."

## demo-down — stop the demo stack
demo-down:
	docker compose -f docker-compose.yml -f docker-compose.demo.yml down

## deploy — Print deployment guide
deploy:
	@echo "Production deployment is a checklist, not a single command."
	@echo "Read docs/DEPLOYMENT.md and pick one of:"
	@echo "  make prod-up-cf       # if behind Cloudflare"
	@echo "  make prod-up-direct   # if Caddy terminates TLS via Let's Encrypt"
	@echo ""
	@echo "Both require .env populated with secrets, MaxMind license, Telegram tokens, DEPLOY_MODE, DOMAIN."

## clean — Remove .env, vendor, node_modules, public/assets (DESTRUCTIVE)
clean:
	@echo "Removing .env, vendor/, node_modules/, public/assets/, bun.lockb, composer.lock"
	@rm -rf .env vendor node_modules public/assets/app.*.css public/assets/app.*.js public/assets/manifest.json public/p.js
