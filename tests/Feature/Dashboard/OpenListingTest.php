<?php

use App\Demo\HeadlessLoop;
use App\Repricer\Catalog\Channel;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Models\PriceDecision;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

/*
 * Open-listing marketplaces (social-commerce style): every seller lists separately, there is no
 * Buy Box, and the repricer competes on price against comparable listings.
 */
beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    config(['demo.enabled' => true]);
});

it('runs a price war with no Buy Box: the listing never has a featured offer', function () {
    $product = Market::product('RNG-LED-10');
    expect($product->channel)->toBe(Channel::Open)
        ->and(DB::table('sim_listings')->where('asin', $product->asin)->value('model'))->toBe('open');

    app(HeadlessLoop::class)->run(60);

    expect(DB::table('sim_listings')->where('asin', $product->asin)->value('buybox_seller_id'))->toBeNull()
        ->and(PriceDecision::query()->where('product_id', $product->id)->where('outcome', 'reprice')->exists())->toBeTrue();

    // Guardrails still hold, and we end up just under the cheapest comparable listing.
    $product->refresh();
    expect($product->current_price->cents)->toBeGreaterThanOrEqual(1799)->toBeLessThanOrEqual(2999);
});

it('reports our price rank instead of a Buy Box win rate', function () {
    app(HeadlessLoop::class)->run(30);
    $row = collect(app(DashboardQuery::class)->state()['products'])->firstWhere('sku', 'RNG-LED-10');

    expect($row['channel'])->toBe('open')
        ->and($row['win_rate_24h_bps'])->toBeNull()
        ->and($row['rank']['of'])->toBe(3)
        ->and($row['rank']['position'])->toBeBetween(1, 3);

    $buyBoxRow = collect(app(DashboardQuery::class)->state()['products'])->firstWhere('sku', 'FP-1L-STEEL');
    expect($buyBoxRow['rank'])->toBeNull()->and($buyBoxRow['win_rate_24h_bps'])->toBeInt();
});

it('refuses "beat the Buy Box holder" where there is no Buy Box', function () {
    $product = Market::product('RNG-LED-10');
    $this->putJson("/api/products/{$product->id}/rule", [
        'strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 1799, 'ceiling' => 2999, 'min_margin' => 300, 'max_step_pct' => 5, 'cooldown_sec' => 180,
    ])->assertUnprocessable()->assertJsonValidationErrors('strategy');

    $this->postJson('/api/products', [
        'title' => 'Phone Tripod', 'sku' => 'TRI-PHN-1', 'channel' => 'open', 'cost' => 500, 'fees' => 200, 'shipping' => 0, 'price' => 1499, 'competitors' => [],
        'strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 999, 'ceiling' => 1999, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 120,
    ])->assertUnprocessable()->assertJsonValidationErrors('strategy');
});

it('adds a product on an open-listing marketplace', function () {
    $this->postJson('/api/products', [
        'title' => 'Phone Tripod', 'sku' => 'TRI-PHN-1', 'channel' => 'open', 'cost' => 500, 'fees' => 200, 'shipping' => 0, 'price' => 1499, 'competitors' => ['penny_pincher'],
        'strategy' => 'beat_lowest', 'offset' => 10, 'floor' => 999, 'ceiling' => 1999, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 120,
    ])->assertCreated()->assertJsonPath('product.channel', 'open');

    $asin = Market::product('TRI-PHN-1')->asin;
    expect(DB::table('sim_listings')->where('asin', $asin)->value('model'))->toBe('open');
});
