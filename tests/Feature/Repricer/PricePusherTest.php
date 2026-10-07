<?php

use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\PriceUpdate;
use App\Repricer\Market\ThrottledException;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;
use App\Repricer\Outbound\Backoff;
use App\Repricer\Outbound\PricePusher;
use App\Repricer\Pricing\RepricingService;
use App\Repricer\Settings\RepricerSettings;
use App\Simulator\Adapter\FaultInjector;
use App\Simulator\Persistence\WorldRepository;
use App\Support\Money;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Queue::fake([PushPriceJob::class]);
});

function decideReprice(string $time = '2026-01-01T00:10:00Z'): PriceDecision
{
    $d = app(RepricingService::class)->handle(Market::product(), Market::notification(time: $time));
    expect($d->outcome)->toBe('reprice');

    return $d;
}

function marketPrice(string $sku = 'MAT-SIL-2'): int
{
    return (int) DB::table('sim_offers')->where('seller_id', Market::US)->where('sku', $sku)->value('price');
}

function pusher(): PricePusher
{
    app()->instance(Backoff::class, new Backoff(500, 30_000, new Randomizer(new Xoshiro256StarStar(1))));

    return app(PricePusher::class);
}

it('pushes the decided price to the market and records the attempt', function () {
    Adapters::simulator();
    $d = decideReprice();

    $r = pusher()->attempt($d);

    expect($r->finished)->toBeTrue()
        ->and(marketPrice())->toBe(1425)
        ->and(Market::product()->current_price->cents)->toBe(1425)
        ->and(Market::product()->last_price_change_at)->not->toBeNull();
    $push = PricePush::query()->sole();
    expect($push->status)->toBe('succeeded')->and($push->attempts)->toBe(1)->and($push->api_response['status'] ?? null)->toBe('ACCEPTED');
});

it('stamps the price change with market time, which drives the next cooldown', function () {
    Adapters::simulator();
    pusher()->attempt(decideReprice());
    $marketNow = app(MarketAdapter::class)->now();

    expect(Market::product()->last_price_change_at?->getTimestamp())->toBe($marketNow->getTimestamp());

    $next = app(RepricingService::class)->handle(Market::product(), Market::notification([[Market::US, 1425], ['PENNYWISE', 1300]], time: '2026-01-01T00:20:00Z'));
    expect($next->outcome)->toBe('skipped')->and($next->reason_code)->toBe('cooldown');
});

it('is idempotent: a second attempt after success does nothing', function () {
    Adapters::simulator();
    $d = decideReprice();
    pusher()->attempt($d);
    $again = pusher()->attempt($d->fresh() ?? $d);

    expect($again->note)->toBe('already finished')
        ->and(PricePush::query()->count())->toBe(1)
        ->and(DB::table('sim_price_requests')->count())->toBe(1);
});

it('cannot apply twice when the response was lost and the push is retried', function () {
    Adapters::simulator();
    $d = decideReprice();

    // The request reached the market but the worker died before recording the response.
    app(MarketAdapter::class)->updatePrice(new PriceUpdate('MAT-SIL-2', Money::cents(1425), PricePusher::idempotencyKey($d)));
    $events = DB::table('sim_events')->count();

    pusher()->attempt($d);

    expect(DB::table('sim_price_requests')->count())->toBe(1)
        ->and(DB::table('sim_events')->count())->toBe($events) // nothing re-applied, no new notification
        ->and(PricePush::query()->sole()->api_response['replayed'] ?? null)->toBeTrue();
});

it('marks an older decision superseded instead of pushing it after a newer one', function () {
    Adapters::simulator();
    $old = decideReprice('2026-01-01T00:10:00Z');
    $new = app(RepricingService::class)->handle(Market::product(), Market::notification([[Market::US, 1499], ['PENNYWISE', 1450]], time: '2026-01-01T00:11:00Z'));

    pusher()->attempt($old);

    expect(PricePush::query()->where('decision_id', $old->id)->sole()->status)->toBe('superseded')
        ->and(marketPrice())->toBe(1499);
    pusher()->attempt($new);
    expect(marketPrice())->toBe($new->new_price?->cents);
});

it('cancels a queued push if the kill switch is turned on before it runs', function () {
    Adapters::simulator();
    $d = decideReprice();
    app(RepricerSettings::class)->set(RepricerSettings::KILL_SWITCH, true);

    pusher()->attempt($d);

    expect(PricePush::query()->sole()->status)->toBe('cancelled')->and(marketPrice())->toBe(1499);
});

it('cancels a queued push if dry run is turned on before it runs', function () {
    Adapters::simulator();
    $d = decideReprice();
    app(RepricerSettings::class)->set(RepricerSettings::DRY_RUN, true);

    pusher()->attempt($d);

    expect(PricePush::query()->sole()->status)->toBe('cancelled')->and(marketPrice())->toBe(1499);
});

describe('under injected 429 and 503 responses', function () {
    it('records a retrying attempt and backs off at least Retry-After on 429', function () {
        Adapters::simulator(http429Bps: 10_000, retryAfterMs: 2_000);
        $d = decideReprice();

        $r = pusher()->attempt($d);

        expect($r->finished)->toBeFalse()
            ->and($r->retryInMs)->toBeGreaterThanOrEqual(2_000);
        $push = PricePush::query()->sole();
        expect($push->status)->toBe('retrying')
            ->and($push->api_response['http_status'] ?? null)->toBe(429)
            ->and($push->api_response['retry_after_ms'] ?? null)->toBe(2_000);
    });

    it('retries 503s and gives up at the attempt cap, one row per attempt', function () {
        Adapters::simulator(http503Bps: 10_000, retryAfterMs: 100);
        $d = decideReprice();

        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $r = pusher()->attempt($d);
            if ($r->finished) {
                break;
            }
        }

        $rows = PricePush::query()->orderBy('attempts')->get();
        expect($rows->pluck('status')->all())->toBe(['retrying', 'retrying', 'retrying', 'retrying', 'failed'])
            ->and($rows->pluck('attempts')->all())->toBe([1, 2, 3, 4, 5])
            ->and(end($results)->finished)->toBeTrue()
            ->and(marketPrice())->toBe(1499);

        // Delays grow exponentially (equal jitter keeps each within [exp/2, exp]).
        $delays = array_map(fn ($r) => $r->retryInMs, array_slice($results, 0, 4));
        foreach ([500, 1000, 2000, 4000] as $i => $exp) {
            expect($delays[$i])->toBeGreaterThanOrEqual(intdiv($exp, 2))->toBeLessThanOrEqual($exp);
        }
    });

    it('eventually succeeds through intermittent faults without applying twice', function () {
        Adapters::simulator(http429Bps: 3_000, http503Bps: 2_000, retryAfterMs: 50, faultSeed: 11);
        $d = decideReprice();

        for ($i = 0; $i < 5; $i++) {
            if (pusher()->attempt($d)->finished) {
                break;
            }
        }

        $statuses = PricePush::query()->orderBy('attempts')->pluck('status')->all();
        expect(end($statuses))->toBe('succeeded')
            ->and(count($statuses))->toBeGreaterThan(1) // the seed is chosen so at least one fault hits
            ->and(DB::table('sim_price_requests')->count())->toBe(1)
            ->and(marketPrice())->toBe(1425);
    });

    it('does not retry a non-retryable error', function () {
        Adapters::simulator();
        $d = decideReprice();
        DB::table('sim_offers')->where('sku', 'MAT-SIL-2')->update(['sku' => 'RENAMED']); // the market no longer knows the SKU

        $r = pusher()->attempt($d);
        expect($r->finished)->toBeTrue()
            ->and(PricePush::query()->sole()->status)->toBe('failed')
            ->and(PricePush::query()->sole()->api_response['http_status'] ?? null)->toBe(404);
    });
});

describe('outbound rate limiting', function () {
    it('waits for a local token instead of spending market quota', function () {
        Adapters::simulator(quotas: ['getItemOffers' => ['burst' => 1, 'refill_ms' => 60_000], 'patchListingsItem' => ['burst' => 1, 'refill_ms' => 60_000]]);
        $first = decideReprice('2026-01-01T00:10:00Z');
        pusher()->attempt($first);

        $p = Market::product();
        $p->forceFill(['last_price_change_at' => null])->save(); // let the next decision through the cooldown
        $second = app(RepricingService::class)->handle(Market::product(), Market::notification([[Market::US, 1425], ['PENNYWISE', 1300]], time: '2026-01-01T00:11:00Z'));
        $requests = DB::table('sim_price_requests')->count();

        $r = pusher()->attempt($second);

        expect($r->finished)->toBeFalse()
            ->and($r->retryInMs)->toBeGreaterThan(50_000)
            ->and(PricePush::query()->where('decision_id', $second->id)->count())->toBe(0)
            ->and(DB::table('sim_price_requests')->count())->toBe($requests);
    });

    it('the simulator itself answers 429 with Retry-After when its token bucket is empty', function () {
        $adapter = Adapters::simulator(quotas: ['getItemOffers' => ['burst' => 1, 'refill_ms' => 2_000], 'patchListingsItem' => ['burst' => 1, 'refill_ms' => 2_000]]);
        $adapter->getItemOffers('B0SIM00002');

        try {
            $adapter->getItemOffers('B0SIM00002');
            $this->fail('expected a 429');
        } catch (ThrottledException $e) {
            expect($e->status)->toBe(429)->and($e->retryAfterMs)->toBeGreaterThan(0)->toBeLessThanOrEqual(2_000);
        }
    });
});

it('the fault sequence is reproducible from its seed', function () {
    $sequence = function (int $seed): array {
        app(Factory::class)->connection()->command('del', ['sim:faults:seq']);
        $f = new FaultInjector(Redis::connection(), $seed, 2_000, 1_000, 500);

        return array_map(fn () => $f->roll()['status'] ?? 200, range(1, 200));
    };

    $a = $sequence(5);
    expect($sequence(5))->toBe($a)
        ->and($sequence(6))->not->toBe($a)
        ->and(count(array_filter($a, fn ($s) => $s === 429)))->toBeGreaterThan(20)->toBeLessThan(60)
        ->and(count(array_filter($a, fn ($s) => $s === 503)))->toBeGreaterThan(5)->toBeLessThan(40);
});

it('world repository sees one applied request per idempotency key', function () {
    Adapters::simulator();
    $adapter = app(MarketAdapter::class);
    $a = $adapter->updatePrice(new PriceUpdate('MAT-SIL-2', Money::cents(1300), 'k1'));
    $b = $adapter->updatePrice(new PriceUpdate('MAT-SIL-2', Money::cents(1300), 'k1'));

    expect($b->submissionId)->toBe($a->submissionId)->and($b->replayed)->toBeTrue()->and($a->replayed)->toBeFalse()
        ->and(app(WorldRepository::class)->findPriceRequest('k1'))->not->toBeNull();
});
