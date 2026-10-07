<?php

use App\Demo\HeadlessLoop;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;
use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\SimulatorControl;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

/*
 * Every bot at once (Penny Pincher, Anchor, Matcher, Sleeper, Chaos) with the market answering
 * ~15% of requests with 429 and ~10% with 503. The repricer runs in-process (sync queue); after
 * each tick, pushes left "retrying" are attempted again, standing in for the queue's delayed
 * retry.
 */

const TICKS = 400;

beforeEach(function () {
    Market::seed();
    Adapters::simulator(http429Bps: 1500, http503Bps: 1000, retryAfterMs: 10, faultSeed: 3);
    // More chaos: a Chaos bot on the price-war listing too, for bursts of events.
    app(SimulatorControl::class)->addBot('B0SIM00001', 'chaos');
});

it('holds every guardrail, bounds reprices and never duplicates a decision with all five bots and injected errors', function () {
    $bots = DB::table('sim_offers')->whereNotNull('bot')->distinct()->pluck('bot')->sort()->values()->all();
    expect($bots)->toBe(collect((new BotRegistry)->keys())->sort()->values()->all());

    app(HeadlessLoop::class)->run(TICKS); // retries unfinished pushes after every tick
    $simSeconds = TICKS * (int) config('simulator.tick_seconds');

    // The injected errors really happened, and were retried.
    $retries = PricePush::query()->where('status', PushStatus::Retrying->value)->count();
    expect($retries)->toBeGreaterThan(0);

    foreach (Product::query()->with('rule')->get() as $p) {
        $rule = $p->rule;
        assert($rule !== null);
        $low = max($rule->floor->cents, $p->cost->cents + $p->fees->cents + $rule->min_margin->cents);
        $applied = DB::table('sim_price_requests')->where('sku', $p->sku)->orderBy('id')->pluck('price')->map(fn ($v) => (int) $v);

        // Guardrails: every price the market accepted is within floor/margin floor and ceiling.
        foreach ($applied as $price) {
            expect($price)->toBeGreaterThanOrEqual($low, "{$p->sku} pushed {$price} below {$low}")
                ->toBeLessThanOrEqual($rule->ceiling->cents, "{$p->sku} pushed {$price} above the ceiling");
        }

        // Bounded: the cooldown caps reprices over the whole run…
        expect($applied->count())->toBeLessThanOrEqual(intdiv($simSeconds, $rule->cooldown_sec) + 1);

        // …and the circuit breaker caps any market hour.
        $limit = (int) config('repricer.breaker.max_reprices_per_hour');
        $times = PricePush::query()->join('price_decisions', 'price_decisions.id', '=', 'price_pushes.decision_id')
            ->where('price_decisions.product_id', $p->id)->where('price_pushes.status', 'succeeded')
            ->orderBy('price_pushes.pushed_at')->pluck('price_pushes.pushed_at')
            ->map(fn ($t) => (new DateTimeImmutable((string) $t))->getTimestamp())->all();
        foreach ($times as $i => $t) {
            $inHour = count(array_filter($times, fn ($u) => $u > $t - 3600 && $u <= $t));
            expect($inHour)->toBeLessThanOrEqual($limit, "{$p->sku} repriced {$inHour} times in a market hour");
        }
    }

    // No duplicate decisions, and no push ever applied twice.
    expect(PriceDecision::query()->select('product_id', 'event_id')->groupBy('product_id', 'event_id')->havingRaw('count(*) > 1')->count())->toBe(0);
    $succeededPerDecision = PricePush::query()->where('status', 'succeeded')->select('decision_id')->groupBy('decision_id')->havingRaw('count(*) > 1')->count();
    expect($succeededPerDecision)->toBe(0)
        ->and(DB::table('sim_price_requests')->count())->toBe(PricePush::query()->where('status', 'succeeded')->count());

    // Sleeper: when its competition disappears the repricer raises the water bottle price.
    $btl = Market::product('BTL-INS-750');
    $raised = PriceDecision::query()->where('product_id', $btl->id)->where('outcome', 'reprice')->whereColumn('new_price', '>', 'old_price')->exists();
    expect($raised)->toBeTrue();
});
