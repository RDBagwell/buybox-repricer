.PHONY: up env down fresh sim sim-fast trace logs ps test test-js e2e demo-reset record shell

DC := docker compose
ART := $(DC) exec app php artisan

## Build and boot the stack, migrate and seed. Creates .env if missing and fills in APP_KEY if blank.
up: env
	$(DC) up -d --build
	@echo ""
	@echo "Stack is up. Try:  make sim   (then)  make trace SKU=FP-1L-STEEL"

## Ensure .env exists, has every variable .env.example defines (an older .env gains the new ones,
## existing values are kept), and has an APP_KEY and a Reverb secret of its own.
env:
	@test -f .env || cp .env.example .env
	@[ -z "$$(tail -c1 .env)" ] || echo >> .env
	@for key in $$(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' .env.example); do \
		if ! grep -q "^$$key=" .env; then \
			grep "^$$key=" .env.example >> .env; \
			echo "Added $$key to .env (from .env.example)"; \
		fi; \
	done
	@if ! grep -q '^APP_KEY=base64:' .env; then \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak; \
		echo "Generated APP_KEY in .env"; \
	fi
	@if grep -q '^REVERB_APP_SECRET=change-me$$' .env; then \
		sed -i.bak "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$$(openssl rand -hex 20)|" .env && rm -f .env.bak; \
		echo "Generated REVERB_APP_SECRET in .env"; \
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

## Rebuild the demo world from its seed (what the public demo does every 30 minutes).
demo-reset:
	$(ART) demo:reset --force

## Re-record the replay (public/recordings/demo.json) from a real seeded run.
record:
	$(ART) sim:record --seed=$${SEED:-42}
	$(DC) cp app:/var/www/html/public/recordings/demo.json public/recordings/demo.json

logs:
	$(DC) logs -f horizon listener reverb simulator

ps:
	$(DC) ps

## Run the test suite inside the container against the stack's Postgres and Redis.
test:
	$(DC) exec -e DB_DATABASE=buybox_test app sh -c "composer install --no-interaction --quiet && vendor/bin/pest"

## Frontend unit tests (Vitest), on the host.
test-js:
	npm test

## Browser tests against the running stack (needs Chromium; see playwright.config.ts).
e2e:
	E2E_BASE_URL=$${E2E_BASE_URL:-http://localhost:8080} npx playwright test

shell:
	$(DC) exec app sh
