# Buy Box Repricer

An event-driven repricer for an Amazon-style marketplace. It watches competitor offers, decides a new price through a small, ordered and unit-tested rules pipeline, and pushes it, without ever going below a floor or above a ceiling. It runs against a **simulated marketplace**, so you can clone it and watch it work without a seller account.

> **Not affiliated with Amazon.** This is an independent portfolio project. "Buy Box", ASIN-style IDs and the shape of the API boundary are used descriptively. The simulator's Buy Box algorithm is an **approximation**: the real one is private.

```
Simulator ──AnyOfferChanged──▶ Redis stream ──▶ repricer:listen ──▶ RepriceJob (queued, idempotent, per-product lock)
                                                                          │
                                                             builds PricingContext (pure data)
                                                                          │
                                                                Rules pipeline (pure, no I/O)
                                                                          │
                                                Decision + rule trace ──▶ price_decisions (append-only audit)
                                                                          │
                                         PushPriceJob ──▶ rate limiter ──▶ MarketAdapter ──▶ Simulator
```

## Quick start

```bash
make up                         # build + boot: postgres, redis, php-fpm, nginx, horizon, listener, scheduler; migrate + seed
make sim                        # php artisan sim:run --ticks=500 --seed=42 --speed=100
make trace SKU=FP-1L-STEEL      # latest decisions with their full rule traces
```

Without Docker (PHP 8.3 with pdo_pgsql + phpredis, Postgres 16, Redis 7):

```bash
cp .env.example .env && composer install && php artisan key:generate
php artisan migrate --seed
php artisan horizon &            # or: php artisan queue:work redis --queue=reprice,push
php artisan repricer:listen &
php artisan sim:run --ticks=500 --seed=42 --speed=100
php artisan repricer:trace FP-1L-STEEL
```

### What a run looks like (measured)

With a queue worker and `repricer:listen` running, from a freshly seeded database:

```
$ php artisan sim:run --ticks=500 --seed=42 --speed=100
[t0006 00:01:30] B0SIM00004 Buy Box: PENNYWISE -> TIMBERLINE at 36.98 landed (lowest effective 35.87)
[api ] B0SIM00001 Buy Box: PENNYWISE -> OUR-STORE (after a price update)
[t0016 00:04:00] B0SIM00001 Buy Box: OUR-STORE -> PENNYWISE at 27.88 landed (lowest effective 27.88)
...
Done: 500 ticks, 25 competitor moves, 25 offer-change events published, 46 Buy Box changes.
```

That run took 81.8 s of wall time (500 ticks × 15 s of market time at 100x ≈ 75 s of sleep plus processing). It produced 92 audited decisions: 36 reprices, 43 cooldown skips, 9 stale-event skips and 4 no-ops. There were 29 successful pushes and 7 superseded ones (a newer decision existed before the push ran). The French Press (`FP-1L-STEEL`) price war against Penny Pincher ended with us holding exactly our 26.99 floor. Measured on the development container on 2026-10-07; your timings will differ.

## Layout

| Namespace | What lives there |
|---|---|
| `App\Repricer\Rules` | The pure pipeline: `PricingContext`, `Rule`, verdicts, `Pipeline`, the eight rules. No framework, no clock, no I/O (enforced by architecture tests). |
| `App\Repricer\Market` | The `MarketAdapter` interface and its DTOs: the only way the repricer sees a marketplace. |
| `App\Repricer\…` | Jobs, the repricing service, pushes, the listener, models, CLI. The impure shell around the rules. |
| `App\Simulator\Engine` | The deterministic marketplace: seeded RNG, market clock, Buy Box scorer, competitor bots. Pure PHP. |
| `App\Simulator\…` | Persistence (`sim_` tables only), the Redis-stream notification channel, the `SimulatorMarketAdapter`, the CLI. |
| `App\Support` | `Money`, `Rounding`, `Fulfillment`, the Redis token bucket. Depends on neither side. |
| `App\Providers\MarketServiceProvider` | The single place that binds `MarketAdapter` to the simulator. |

## The rules pipeline

Each rule takes the context and the price proposed so far, and returns a **proposal** (price + reason), a **pass** or a **veto** (stop, with a reason). Every verdict lands in the rule trace stored with the decision. Order (configured once, in `DefaultPipeline`):

1. **should_act**: veto on kill switch, paused product, or cooldown (in market time).
2. **competitor_filter**: drop competitors below the rating threshold or above the handling-time limit.
3. **already_winning**: if we hold the Buy Box, never cut. Consider raising toward the next competitor's landed price minus the offset.
4. **strategy**: `beat_lowest` by X, `match_lowest`, or `beat_buybox` by X, all on **landed price** (price + shipping).
5. **step_limit**: cap a single move at `max_step_pct` of the current price (rounded down, at least 1 cent).
6. **margin_floor**: never below cost + fees + minimum margin. **Guardrail.**
7. **floor_ceiling**: clamp to the product's hard floor and ceiling. **Guardrail.**
8. **no_op**: veto when the final price equals the current one.

The competitor filter runs *before* the strategy (the brief lists it after). A strategy that has already priced against an ineligible offer cannot be un-chased by a later rule, so filtering has to come first; a test pins this order.

**Guardrails always run last among the price-shaping rules, as a tested invariant.** Every rule declares a `Stage`. `Pipeline` refuses to be built if stages are out of order or either guardrail is missing. It throws if a filter or final-stage rule tries to propose a price. As a last line of defence, it vetoes any final price outside `[max(floor, margin floor), ceiling]`.

### Edge cases (all covered by scenario tests)

| Situation | Behaviour |
|---|---|
| No competitors | `no_competition=hold` keeps the price; `raise_to_ceiling` heads for the ceiling (still step-limited). |
| Every competitor filtered out | Same as no competitors; the trace says they were filtered. |
| Floor above every competitor | The floor wins; we stay at the floor even if we lose the Buy Box. |
| Margin floor above the ceiling | Configuration error: vetoed, recorded as `config_error`, never priced. Same for floor > ceiling. |
| We're the only seller | We hold the Buy Box; the no-competition setting applies. |
| Tie on landed price | The shared landed price is the reference: `beat_*` undercuts it, `match_lowest` joins the tie, which the marketplace gives to the incumbent. |
| Current price outside floor–ceiling | The guardrails move it into range even if that exceeds the step limit. This is the only way a move may exceed the step limit, and the property tests check exactly that. |

## Money and rounding

Money is **integer cents everywhere**: PHP (`App\Support\Money`), the database (integer columns; a test fails any migration that uses float/decimal), JSON. Percentages are basis points, and every division names its rounding mode:

- step limit: `Rounding::Down`, so the cap never exceeds the configured percentage;
- Buy Box fulfilment bonus and handling penalty: `Rounding::HalfUp`, which is neutral for scoring;
- `Rounding::Up` exists for minimums that must always be reached.

## Market time

The simulator has its own clock: a tick advances market time by `tick_seconds` (15 s). `--speed` (1x to 100x) only changes how long `sim:run` sleeps between ticks, never what a tick means. The adapter exposes this clock (`MarketAdapter::now()`), and the repricer uses it for cooldowns and the timestamps it stores (`decided_at`, `event_time`, `pushed_at`, `last_price_change_at`). **At 100x, a 5-minute cooldown is 20 ticks, which is 3 real seconds.** A real SP-API adapter would return wall-clock time.

Rate limits are the exception: token buckets measure **real** time, because they model request throughput.

## The Buy Box (an approximation)

Deterministic and documented in `BuyBoxScorer`:

- offers rated below 80% are disqualified;
- effective price = landed − 3% if marketplace-fulfilled + 1% per handling day beyond 2, so landed price dominates;
- lowest effective price wins; **ties go to the incumbent** (no flapping), otherwise lowest landed, then seller id.

## Competitor bots

Bots implement `CompetitorBot` (key + `act(BotContext): BotAction`). Bots get the listing, their own offer, their params, a small persisted memory, market time, the tick number and the shared seeded RNG. **Penny Pincher** undercuts the Buy Box holder by $0.01 down to its own floor, reacting on a seeded share of the ticks it is scheduled for. **Anchor** holds a fixed price. Matcher, Sleeper and Chaos fit the same interface (see "Notes for session 2" in the PR).

## Determinism

All simulation randomness comes from one seeded `Xoshiro256**` engine whose full state is stored in `sim_state`. Time comes only from the market clock. The same seed and starting world give an identical run, in memory and through the database (both tested). Event ids are UUIDv7s assigned when events are *published*, outside the engine, so re-running a seed never collides with ids the repricer has already processed. Market time never runs backwards across `sim:reset`.

## Event delivery: why a Redis stream

The simulator publishes `AnyOfferChanged` (modelled on the spirit of `ANY_OFFER_CHANGED`, with lowest landed prices, Buy Box winner, offers and a trigger, but not claiming compatibility) to a **Redis stream** read through a **consumer group**. This mirrors how the real marketplace delivers notifications to SQS:

- durable and **at-least-once**: `repricer:listen` acknowledges (`XACK`) only after dispatching jobs;
- a message left unacknowledged past the visibility timeout is reclaimed (`XAUTOCLAIM`) and redelivered;
- one consumer group can scale to several listeners;
- it lives behind `MarketAdapter::receiveNotifications()`/`acknowledge()`, so an SQS-backed adapter can replace it.

A simulator reset publishes one snapshot notification per listing, so the repricer starts from the current state.

A purely push-driven repricer has one gap: an event skipped for **cooldown** is never revisited if the market then goes quiet. `CooldownSweeper` closes it. When a product's latest decision is a cooldown skip and the cooldown has expired in market time, it re-decides from that decision's **own audited snapshot** as `"<event id>#recheck"`, spending no market quota. The listener runs it after every batch, and the scheduler runs it every minute.

## Idempotency, locks and retries

- **Decisions:** `price_decisions` is unique on `(product_id, event_id)`. A redelivered event records a `duplicate_deliveries` row and never reprices twice. A crash mid-transaction rolls back and the retry writes exactly one decision. A crash after the decision but before its push is resumed by the redelivery or by `repricer:resume-pushes` (scheduled).
- **Per-product locks:** Redis locks (`WithoutOverlapping`, shared keys) serialise **decisions** and, separately, **pushes** for each product. They are separate so a decision never waits on its own push, and an older price can never land after a newer one. A blocked job is released and retried, never dropped.
- **Stale events:** an event older than the snapshot behind the product's latest decision is skipped and recorded as `stale`.
- **Pushes:** the request carries `decision-<id>` as idempotency key (the simulator answers replays from `sim_price_requests`), and a decision with a finished push is never pushed again. One `price_pushes` row is written per attempt. A newer decision **supersedes** an older pending push. Turning on the kill switch or dry run **cancels** queued pushes.
- **Rate limiting:** before every push the repricer takes a token from a Redis GCRA token bucket sized to the adapter's published quota. If none is available it waits rather than spending quota on a 429.
- **Retries:** 429 and 503 are retried with exponential backoff and equal jitter, never sooner than `Retry-After`, up to 5 attempts. Other errors fail immediately.

## Safety

- **Kill switch** and **dry run** live in `settings` (`php artisan repricer:switch kill_switch on`), **pause** on `products` (`repricer:pause SKU`). All are read fresh by every decision and every push.
- **Audit log:** every decision, including skips, vetoes, no-ops and stale events, stores the input snapshot (`offer_snapshots`), the rule trace (JSON), old and new prices and the outcome. Audit tables are **append-only**, enforced both by the models and by Postgres triggers that reject `UPDATE`/`DELETE`.
- **Circuit breaker:** a seam (`App\Repricer\Safety\CircuitBreaker`, bound to `NeverTrips`) is consulted before every push and told every outcome; session 2 implements it.

## Tests

```bash
php artisan test        # or vendor/bin/pest
```

| Suite | Covers |
|---|---|
| `tests/Unit/Rules` | Every rule with hand-built contexts; table-driven pipeline scenarios; the ordering invariant; **property tests** (seeded loops of 5,000 and 2,000 cases: within floor/ceiling/margin floor, step limit unless a clamp forces it, deterministic). |
| `tests/Unit/Simulator` | Buy Box scoring (each factor, disqualification, incumbent tie-break), bots, determinism. |
| `tests/Feature/Simulator` | DB-backed runner ≡ in-memory engine, seed reproducibility, the CLI. |
| `tests/Feature/Repricer` | Decisions, duplicates, crash/retry, stale events, kill switch, dry run, append-only audit, pushes under injected 429/503, rate limiting, the Redis lock with a real queue worker, the listener and redelivery, cooldown re-checks. |
| `tests/Contract` | One `MarketAdapter` contract suite (`MarketAdapterContract::register`), run against the simulator adapter; a future SP-API client provides an `AdapterHarness` and runs the same suite. |
| `tests/Simulation` | Headless loop against Penny Pincher: never below the floor or margin floor, reprices bounded by the cooldown, every delivery audited; kill switch and dry run end to end. |
| `tests/Arch` | Repricer never imports the simulator; rules use no framework, clock, randomness or I/O; simulator only touches `sim_` tables; money columns are integers. |

The PHP tests need Postgres and Redis (CI provides both as services; locally they use the `buybox_test` database and Redis DBs 14/15).

## CLI

| Command | Purpose |
|---|---|
| `sim:run --ticks= --seed= --speed= [--fast] [--moves]` | Run the simulation; prints Buy Box changes (bot-driven and after our price updates). |
| `sim:reset --seed= [--purge]` | Rebuild the world from `config/simulator.php`. |
| `repricer:listen` | Consume notifications → `RepriceJob`s; sweep expired cooldowns. |
| `repricer:trace {product}` | Latest decisions with rule traces and push attempts. |
| `repricer:switch kill_switch\|dry_run [on\|off]` | Global switches. |
| `repricer:pause {sku} [--resume]` | Per-product pause. |
| `repricer:resume-pushes`, `repricer:recheck-cooldowns` | Safety-net sweeps (scheduled every minute). |
