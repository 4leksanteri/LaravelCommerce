# Laravel Commerce
#
# Routine work has a short command here. If you find yourself typing a long
# `docker compose exec ...` line twice, it belongs in this file.
#
# Where things run, and why:
#
#   PHP tooling      inside the api container, so nobody needs PHP 8.5, the
#                    pdo_pgsql extension or a matching Composer on their laptop
#   JS tooling       on the host, via pnpm. Editors need host node_modules for
#                    TypeScript and ESLint to work at all, so they are there
#                    regardless and running the same install twice would only
#                    give the two a way to disagree
#
# `make setup` does both.

SHELL := /bin/sh

COMPOSE     := docker compose
COMPOSE_PROD := docker compose -f docker-compose.prod.yml

# -T disables TTY allocation, which is what makes these usable from a script
# and from CI as well as from a terminal.
API  := $(COMPOSE) exec -T api
WEB  := $(COMPOSE) exec -T web

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'


# --- Setup ------------------------------------------------------------------

.PHONY: setup
setup: .env node_modules ## First run: environment, dependencies, containers, database
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo ""
	@echo "Web  http://localhost:$${WEB_PORT:-3000}"
	@echo "API  http://localhost:$${API_PORT:-8000}/api/v1/health  (development only)"

.env:
	@cp .env.example .env
	@echo "Created .env from .env.example."
	@$(MAKE) --no-print-directory key

node_modules:
	pnpm install

.PHONY: key
key: ## Generate APP_KEY and write it into .env
	@test -f .env || { echo ".env does not exist. Run 'make setup'."; exit 1; }
	@key=$$(docker run --rm dunglas/frankenphp:1-php8.5-alpine \
		php -r 'echo "base64:".base64_encode(random_bytes(32));'); \
	if grep -q '^APP_KEY=' .env; then \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$key|" .env && rm -f .env.bak; \
	else \
		printf 'APP_KEY=%s\n' "$$key" >> .env; \
	fi
	@echo "APP_KEY written to .env."


# --- Running ----------------------------------------------------------------

.PHONY: up
up: ## Start the development stack
	$(COMPOSE) up -d

.PHONY: dev
dev: ## Start the development stack and follow its logs
	$(COMPOSE) up

.PHONY: down
down: ## Stop the development stack
	$(COMPOSE) down

.PHONY: restart
restart: down up ## Restart the development stack

.PHONY: reset
reset: ## Destroy containers AND data, then set up again from scratch
	$(COMPOSE) down --volumes
	$(MAKE) setup

.PHONY: build
build: ## Rebuild the development images
	$(COMPOSE) build

.PHONY: ps
ps: ## Show service status
	$(COMPOSE) ps

.PHONY: logs
logs: ## Follow logs from every service
	$(COMPOSE) logs --follow

.PHONY: logs-api
logs-api: ## Follow the API log
	$(COMPOSE) logs --follow api

.PHONY: logs-web
logs-web: ## Follow the web log
	$(COMPOSE) logs --follow web


# --- Shells -----------------------------------------------------------------

.PHONY: shell
shell: ## Shell into the API container
	$(COMPOSE) exec api sh

.PHONY: shell-web
shell-web: ## Shell into the web container
	$(COMPOSE) exec web sh

.PHONY: psql
psql: ## Open psql against the development database
	$(COMPOSE) exec postgres psql -U $${DB_USERNAME} -d $${DB_DATABASE}

.PHONY: tinker
tinker: ## Open a Laravel REPL
	$(COMPOSE) exec api php artisan tinker


# --- Database ---------------------------------------------------------------

.PHONY: migrate
migrate: ## Run pending migrations
	$(API) php artisan migrate

.PHONY: migrate-fresh
migrate-fresh: ## Drop every table and migrate from scratch (destroys data)
	$(API) php artisan migrate:fresh

.PHONY: rollback
rollback: ## Roll back the last migration batch
	$(API) php artisan rollback 2>/dev/null || $(API) php artisan migrate:rollback

.PHONY: seed
seed: ## Run database seeders
	$(API) php artisan db:seed

# Separate from `seed` on purpose: categories are platform data every
# environment needs, and five invented shops are not.
.PHONY: seed-demo
seed-demo: ## Fill the dev database with demo shops and listings (idempotent)
	$(API) php artisan db:seed --class=DemoCatalogueSeeder


# --- Quality ----------------------------------------------------------------
#
# `make check` is the gate. Run it before calling anything done; it is what CI
# runs and it is cheaper to be told here.

.PHONY: check
check: lint typecheck api-check test ## Everything: lint, types, contract, tests

.PHONY: lint
lint: lint-api lint-web format-check charset ## Lint both applications, formatting and charset

.PHONY: charset
charset: ## Refuse invisible and confusable characters (CLAUDE.md section 15)
	node scripts/check-charset.mjs

.PHONY: lint-api
lint-api: ## Pint (formatting) and PHPStan (static analysis)
	$(API) composer lint
	$(API) composer analyse

.PHONY: lint-web
lint-web: ## ESLint
	pnpm --filter web lint

.PHONY: format
format: ## Apply formatting: Pint for PHP, Prettier for everything else
	$(API) composer format
	pnpm format

.PHONY: format-check
format-check: ## Check formatting without changing anything
	pnpm format:check

.PHONY: typecheck
typecheck: ## TypeScript
	pnpm --filter web typecheck

.PHONY: test
test: test-api ## Run the test suites

.PHONY: test-api
test-api: ## PHPUnit, against PostgreSQL
	# phpunit directly, not `artisan test`. Collision's test command reads the
	# .env file to decide which variables to clear before running, and this
	# stack deliberately has no .env inside the container - configuration
	# arrives as real environment variables. The result is a warning on every
	# test, about a file whose absence is the design.
	$(API) composer test


# --- Artisan and Composer ---------------------------------------------------
#
#   make artisan ARGS="make:model Product -m"
#   make composer ARGS="require stripe/stripe-php"

.PHONY: artisan
artisan: ## Run an artisan command: make artisan ARGS="route:list"
	$(COMPOSE) exec api php artisan $(ARGS)

.PHONY: composer
composer: ## Run a composer command: make composer ARGS="require vendor/package"
	$(COMPOSE) exec api composer $(ARGS)

.PHONY: routes
routes: ## List the API's routes
	$(API) php artisan route:list


# --- The API contract -------------------------------------------------------
#
# One source of truth. Scramble reads the Laravel routes, form requests and
# resources and writes apps/api/openapi.json; the frontend's types and the
# Postman collection are both generated from that file and nothing else.
#
# All three outputs are committed, so a change to the contract shows up in
# review as a diff rather than as a surprise at runtime.

GENERATED_CONTRACT := apps/api/openapi.json apps/web/src/lib/api/generated docs/postman

.PHONY: api-docs
api-docs: ## Regenerate the OpenAPI document, the frontend types and the Postman collection
	$(API) php artisan scramble:export
	pnpm --filter web api:generate
	node scripts/build-postman-collection.mjs

.PHONY: api-check
api-check: ## Fail when the committed contract has drifted from the code
	@$(MAKE) --no-print-directory api-docs >/dev/null
	@# Two questions, because one is not enough. `git diff` compares the
	@# regenerated files against what git has recorded, which is the actual
	@# drift check and which correctly passes for files staged in this very
	@# commit. It says nothing about a file git has never seen, so untracked
	@# paths are asked about separately.
	@changed=$$(git diff --name-only -- $(GENERATED_CONTRACT)); \
	untracked=$$(git ls-files --others --exclude-standard -- $(GENERATED_CONTRACT)); \
	if [ -n "$$changed" ] || [ -n "$$untracked" ]; then \
		echo ""; \
		echo "The generated API contract is not the one that is recorded:"; \
		echo ""; \
		for f in $$changed; do echo "  drifted    $$f"; done; \
		for f in $$untracked; do echo "  untracked  $$f"; done; \
		echo ""; \
		echo "Run 'make api-docs' and commit the result."; \
		exit 1; \
	fi
	@echo "API contract is current."


# --- Production -------------------------------------------------------------
#
# These act on docker-compose.prod.yml. Migrations are their own step,
# deliberately: under more than one replica, a container that migrates on boot
# is a race.

.PHONY: prod-build
prod-build: ## Build the production images
	$(COMPOSE_PROD) build

.PHONY: prod-up
prod-up: ## Start the production stack
	$(COMPOSE_PROD) up -d

.PHONY: prod-down
prod-down: ## Stop the production stack
	$(COMPOSE_PROD) down

.PHONY: prod-logs
prod-logs: ## Follow production logs
	$(COMPOSE_PROD) logs --follow

.PHONY: deploy-migrate
deploy-migrate: ## Run migrations against production, once, before cutting over
	$(COMPOSE_PROD) run --rm api php artisan migrate --force
