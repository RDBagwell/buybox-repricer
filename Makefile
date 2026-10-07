.PHONY: up env down fresh sim sim-fast trace logs ps test shell

DC := docker compose
ART := $(DC) exec app php artisan

## Build and boot the stack, migrate and seed. Creates .env if missing and fills in APP_KEY if blank.
up: env
	$(DC) up -d --build
	@echo ""
	@echo "Stack is up. Try:  make sim   (then)  make trace SKU=FP-1L-STEEL"

## Ensure .env exists and has an APP_KEY (an existing .env with a blank key is fixed, a set key is kept).
env:
	@test -f .env || cp .env.example .env
	@if ! grep -q '^APP_KEY=base64:' .env; then \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak; \
		echo "Generated APP_KEY in .env"; \
	fi

down:
	$(DC) down

## Drop everything (including the database volume) and start again.
fresh:
	$(DC) down -v
	$(MAKE) up

## Run a live simulation the repricer reacts to (500 ticks, seed 42, 100x).
sim:
	$(ART) sim:run --ticks=$${TICKS:-500} --seed=$${SEED:-42} --speed=$${SPEED:-100}

## Latest decisions and rule traces for a product: make trace SKU=FP-1L-STEEL
trace:
	$(ART) repricer:trace $${SKU:-FP-1L-STEEL} --limit=$${LIMIT:-5}

logs:
	$(DC) logs -f horizon listener

ps:
	$(DC) ps

## Run the test suite inside the container against the stack's Postgres and Redis.
test:
	$(DC) exec -e DB_DATABASE=buybox_test app sh -c "composer install --no-interaction --quiet && vendor/bin/pest"

shell:
	$(DC) exec app sh
