<?php

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Repricer\Pricing\CooldownSweeper;
use App\Simulator\SimulationRunner;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

/*
 * The whole loop, headless and in-process: simulator ticks → notifications on the Redis stream →
 * listener → RepriceJob (sync queue, real Redis locks) → pipeline → audit → PushPriceJob →
 * adapter → simulator. Market time advances 15 s per tick.
 */

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
});

/**
 * @return array{ticks: int, notifications: int, dispatched: int}
 */
function runLoop(int $ticks): array
{
    $runner = app(SimulationRunner::class);
    $market = app(MarketAdapter::class);
    $notifications = 0;
    $dispatched = 0;

    for ($t = 0; $t < $ticks; $t++) {
        $runner->tick();
        // Drain: our own pushes emit notifications too, so keep reading until quiet.
        while (($batch = $market->receiveNotifications(50, 0)) !== []) {
            foreach ($batch as $n) {
                $notifications++;
                $dispatched += Product::query()->where('asin', $n->asin)->count();
                OfferChangeReceived::dispatch($n);
                $market->acknowledge($n);
            }
        }
        // What repricer:listen does after each batch: re-check expired cooldowns.
        $dispatched += app(CooldownSweeper::class)->sweep();
    }

    return ['ticks' => $ticks, 'notifications' => $notifications, 'dispatched' => $dispatched];
}

/** @return array<string, list<int>> SKU => prices the market accepted from us, in order */
function pricesAppliedBySku(): array
{
    $out = [];
    foreach (DB::table('sim_price_requests')->orderBy('id')->get() as $r) {
        $out[(string) $r->sku][] = (int) $r->price;
    }

    return $out;
}

it('never goes below the floor or margin floor against Penny Pincher, and keeps reprices bounded', function () {
    $ticks = 400;
    $stats = runLoop($ticks);
    $simSeconds = $ticks * (int) config('simulator.tick_seconds');

    $applied = pricesAppliedBySku();
    expect($applied)->not->toBeEmpty('the repricer never pushed a price');

    foreach (Product::query()->with('rule')->get() as $p) {
        $rule = $p->rule;
        assert($rule !== null);
        $low = max($rule->floor->cents, $p->cost->cents + $p->fees->cents + $rule->min_margin->cents);

        foreach ($applied[$p->sku] ?? [] as $price) {
            expect($price)->toBeGreaterThanOrEqual($low, "{$p->sku} pushed {$price} below {$low}")
                ->toBeLessThanOrEqual($rule->ceiling->cents, "{$p->sku} pushed {$price} above ceiling");
        }

        // The cooldown bounds how often a product can change price.
        $maxReprices = intdiv($simSeconds, $rule->cooldown_sec) + 1;
        expect(count($applied[$p->sku] ?? []))->toBeLessThanOrEqual($maxReprices);

        $marketPrice = (int) DB::table('sim_offers')->where('seller_id', Market::US)->where('sku', $p->sku)->value('price');
        expect($marketPrice)->toBeGreaterThanOrEqual($low)
            ->and($p->current_price->cents)->toBe($marketPrice); // our view matches the market
    }

    // Penny Pincher's floor (22.99) is below ours: we fight down to our floor and hold it there.
    $fp = Market::product('FP-1L-STEEL');
    expect(min($applied['FP-1L-STEEL'] ?? [PHP_INT_MAX]))->toBe($fp->rule?->floor->cents)
        ->and($fp->current_price->cents)->toBe($fp->rule?->floor->cents);

    // Every delivery for one of our products is accounted for in the audit log.
    expect(PriceDecision::query()->count() + DB::table('duplicate_deliveries')->count())->toBe($stats['dispatched']);
});

it('reacts to Penny Pincher end to end: pushes land in the market and every decision is audited with a trace', function () {
    runLoop(120);

    $pushed = PriceDecision::query()->where('outcome', 'reprice')->whereHas('pushes', fn ($q) => $q->where('status', 'succeeded'))->get();
    expect($pushed)->not->toBeEmpty();

    foreach ($pushed as $d) {
        expect($d->rule_trace)->not->toBeEmpty()
            ->and($d->snapshots()->count())->toBeGreaterThan(0);
    }

    expect(PriceDecision::query()->whereNotIn('outcome', ['reprice', 'dry_run', 'no_change', 'skipped', 'config_error', 'stale'])->count())->toBe(0)
        ->and(PriceDecision::query()->where('outcome', 'skipped')->where('reason_code', 'cooldown')->count())->toBeGreaterThan(0);

    $this->artisan('repricer:trace', ['product' => 'FP-1L-STEEL', '--limit' => 3])
        ->expectsOutputToContain('should_act')
        ->assertSuccessful();
});

it('does nothing to the market while the kill switch is on, but still audits every decision', function () {
    $this->artisan('repricer:switch', ['name' => 'kill_switch', 'state' => 'on'])->assertSuccessful();
    $stats = runLoop(80);

    expect(DB::table('sim_price_requests')->count())->toBe(0)
        ->and(PriceDecision::query()->count())->toBe($stats['dispatched'])
        ->and(PriceDecision::query()->where('reason_code', '!=', 'kill_switch')->count())->toBe(0);
});

it('records what it would do in dry-run mode and never pushes', function () {
    $this->artisan('repricer:switch', ['name' => 'dry_run', 'state' => 'on'])->assertSuccessful();
    runLoop(80);

    expect(DB::table('sim_price_requests')->count())->toBe(0)
        ->and(PriceDecision::query()->where('outcome', 'dry_run')->count())->toBeGreaterThan(0)
        ->and(DB::table('price_pushes')->count())->toBe(0);
});
