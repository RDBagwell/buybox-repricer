<?php

use App\Demo\HeadlessLoop;
use App\Repricer\Models\OfferSnapshot;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

/*
 * The Sleeper (NAPTIME, $19.99 on the water bottle) sells for a while, goes out of stock, then
 * comes back. The repricer should raise toward the next competitor while it is away, and fight
 * again when it returns. Cycle of 50 ticks awake, 50 asleep: each phase outlasts the rule's
 * 600 s (40-tick) cooldown, so both reactions can land.
 */
beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    DB::table('sim_offers')->where('seller_id', 'NAPTIME')
        ->update(['bot_params' => json_encode(['price' => 1999, 'awake_ticks' => 50, 'asleep_ticks' => 50])]);
});

/** @return list<string> */
function sellersSeen(PriceDecision $d): array
{
    return $d->snapshots()->pluck('seller')->map(fn ($s) => (string) $s)->all();
}

it('raises when the competition disappears and fights again when it returns', function () {
    $product = Product::query()->where('sku', 'BTL-INS-750')->firstOrFail();

    app(HeadlessLoop::class)->run(150);

    $reprices = PriceDecision::query()->where('product_id', $product->id)
        ->where('outcome', 'reprice')->orderBy('id')->get();

    $raise = $reprices->first(fn (PriceDecision $d) => $d->new_price->cents > $d->old_price->cents
        && ! in_array('NAPTIME', sellersSeen($d), true));
    expect($raise)->not->toBeNull('expected a raise while NAPTIME was out of stock');

    $cut = $reprices->first(fn (PriceDecision $d) => $d->id > $raise->id
        && $d->new_price->cents < $d->old_price->cents
        && in_array('NAPTIME', sellersSeen($d), true));
    expect($cut)->not->toBeNull('expected a cut once NAPTIME was back in stock');

    // And the guardrails held throughout.
    $rule = $product->rule;
    foreach ($reprices as $d) {
        expect($d->new_price->cents)->toBeGreaterThanOrEqual($rule->floor->cents)
            ->toBeLessThanOrEqual($rule->ceiling->cents);
    }
});
