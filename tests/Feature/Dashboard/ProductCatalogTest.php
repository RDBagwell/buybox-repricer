<?php

use App\Demo\HeadlessLoop;
use App\Repricer\Models\AuditEntry;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    config(['demo.enabled' => true]);
});

/** A valid add-product payload (integer cents), with overrides. */
function newProduct(array $overrides = []): array
{
    return array_replace([
        'title' => 'Ceramic Mug, 12 oz', 'sku' => 'MUG-CER-12', 'cost' => 400, 'fees' => 150, 'shipping' => 0, 'price' => 1499,
        'competitors' => ['penny_pincher'],
        'strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 899, 'ceiling' => 1999, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 120,
    ], $overrides);
}

it('adds a product: catalogue entry, rule, a simulated listing with our offer and the competitors, and an audit row', function () {
    $r = $this->postJson('/api/products', newProduct(['competitors' => ['penny_pincher', 'anchor']]))->assertCreated();

    $product = Product::query()->where('sku', 'MUG-CER-12')->firstOrFail();
    expect($product->asin)->toBe('B0SIM00007') // after the six seeded listings
        ->and($product->current_price->cents)->toBe(1499)
        ->and($product->rule?->floor->cents)->toBe(899)
        ->and($r->json('product.archived'))->toBeFalse()
        ->and(collect($r->json('simulator.listings'))->firstWhere('asin', 'B0SIM00007')['offers'])->toHaveCount(3);

    $sellers = DB::table('sim_offers')->where('asin', 'B0SIM00007')->orderBy('seller_id')->pluck('bot', 'seller_id')->all();
    expect($sellers)->toBe(['ANCHOR-1' => 'anchor', 'OUR-STORE' => null, 'PENNY_PINCHER-1' => 'penny_pincher']);

    $audit = AuditEntry::query()->where('action', 'product.created')->sole();
    expect($audit->product_id)->toBe($product->id)
        ->and($audit->after['sku'] ?? null)->toBe('MUG-CER-12')
        ->and($audit->after['competitors'] ?? null)->toBe(['penny_pincher', 'anchor']);
});

it('starts repricing the new product straight away', function () {
    $this->postJson('/api/products', newProduct())->assertCreated();
    $product = Product::query()->where('sku', 'MUG-CER-12')->firstOrFail();

    app(HeadlessLoop::class)->run(30);

    expect(PriceDecision::query()->where('product_id', $product->id)->count())->toBeGreaterThan(0)
        ->and(PriceDecision::query()->where('product_id', $product->id)->where('outcome', 'reprice')->exists())->toBeTrue();
    $product->refresh();
    expect($product->current_price->cents)->toBeGreaterThanOrEqual(899)->toBeLessThanOrEqual(1999);
});

it('validates new products on the server', function (array $override, string $field) {
    $this->postJson('/api/products', newProduct($override))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Product::query()->where('sku', $override['sku'] ?? 'MUG-CER-12')->exists())->toBe(($override['sku'] ?? null) === 'MAT-SIL-2');
})->with([
    'floor below margin floor' => [['floor' => 700], 'floor'],
    'floor above ceiling' => [['floor' => 2500, 'ceiling' => 2000, 'price' => 2200], 'floor'],
    'price outside floor..ceiling' => [['price' => 2999], 'price'],
    'sku already used' => [['sku' => 'MAT-SIL-2'], 'sku'],
    'sku with spaces' => [['sku' => 'my mug'], 'sku'],
    'unknown competitor' => [['competitors' => ['shark']], 'competitors.0'],
    'too many competitors' => [['competitors' => ['anchor', 'matcher', 'chaos']], 'competitors'],
    'fractional cents' => [['cost' => 4.5], 'cost'],
    'title too short' => [['title' => 'ab'], 'title'],
]);

it('caps the catalogue size on the public demo', function () {
    config(['demo.max_products' => 7]);
    $this->postJson('/api/products', newProduct())->assertCreated();
    $this->postJson('/api/products', newProduct(['sku' => 'MUG-CER-2']))
        ->assertUnprocessable()->assertJsonPath('message', 'The public demo holds at most 7 products. Archive one first.');
});

it('archives instead of deleting: stops repricing, leaves the simulator, keeps the history', function () {
    $product = Market::product();
    $decisions = PriceDecision::query()->where('product_id', $product->id)->count();

    $this->postJson("/api/products/{$product->id}/archive", [])->assertUnprocessable(); // needs confirm
    $r = $this->postJson("/api/products/{$product->id}/archive", ['confirm' => true])->assertOk();

    $product->refresh();
    expect($product->archived_at)->not->toBeNull()
        ->and($product->paused)->toBeTrue()
        ->and($r->json('product.archived'))->toBeTrue()
        ->and(DB::table('sim_listings')->where('asin', $product->asin)->exists())->toBeFalse()
        ->and(PriceDecision::query()->where('product_id', $product->id)->count())->toBe($decisions)
        ->and(AuditEntry::query()->where('action', 'product.archived')->where('product_id', $product->id)->exists())->toBeTrue();

    // The rest of the world keeps running, and nothing more is decided for the archived product.
    app(HeadlessLoop::class)->run(20);
    expect(PriceDecision::query()->where('product_id', $product->id)->count())->toBe($decisions);

    // It can't be resumed, and archiving again is a no-op.
    $this->postJson("/api/products/{$product->id}/resume", ['acknowledge' => true])->assertUnprocessable();
    $this->postJson("/api/products/{$product->id}/archive", ['confirm' => true])->assertOk();
    expect(AuditEntry::query()->where('action', 'product.archived')->count())->toBe(1);
});

it('never reuses an archived product\'s ASIN for a new one', function () {
    $last = Product::query()->where('asin', 'B0SIM00006')->firstOrFail();
    $this->postJson("/api/products/{$last->id}/archive", ['confirm' => true])->assertOk();

    $this->postJson('/api/products', newProduct())->assertCreated();
    expect(Product::query()->where('sku', 'MUG-CER-12')->value('asin'))->toBe('B0SIM00007');
});

it('does not count archived products against the demo cap', function () {
    config(['demo.max_products' => 6]);
    $this->postJson('/api/products', newProduct())->assertUnprocessable();
    $this->postJson('/api/products/'.Market::product()->id.'/archive', ['confirm' => true])->assertOk();
    $this->postJson('/api/products', newProduct())->assertCreated();
});

it('restores an archived product with the competitors it had, and repricing resumes', function () {
    $product = Market::product();
    $bots = fn () => DB::table('sim_offers')->where('asin', $product->asin)->whereNotNull('bot')->orderBy('seller_id')->pluck('bot')->all();
    $before = $bots();
    expect($before)->not->toBeEmpty();

    $this->postJson("/api/products/{$product->id}/archive", ['confirm' => true])->assertOk();
    expect(DB::table('sim_listings')->where('asin', $product->asin)->exists())->toBeFalse();

    $this->postJson("/api/products/{$product->id}/restore", [])->assertUnprocessable(); // needs confirm
    $r = $this->postJson("/api/products/{$product->id}/restore", ['confirm' => true])->assertOk();

    $product->refresh();
    expect($product->archived_at)->toBeNull()
        ->and($product->paused)->toBeFalse()
        ->and($r->json('product.archived'))->toBeFalse()
        ->and(DB::table('sim_listings')->where('asin', $product->asin)->exists())->toBeTrue()
        ->and($bots())->toBe($before)
        ->and(AuditEntry::query()->where('action', 'product.restored')->where('product_id', $product->id)->firstOrFail()->after['competitors'] ?? null)
        ->toEqualCanonicalizing($before);

    // Back in the war: new decisions are made for it again.
    $decisions = PriceDecision::query()->where('product_id', $product->id)->count();
    app(HeadlessLoop::class)->run(40);
    expect(PriceDecision::query()->where('product_id', $product->id)->count())->toBeGreaterThan($decisions);

    // Restoring an active product is a no-op.
    $this->postJson("/api/products/{$product->id}/restore", ['confirm' => true])->assertOk();
    expect(AuditEntry::query()->where('action', 'product.restored')->count())->toBe(1);
});

it('restores against a penny-pincher and an anchor when no competitors were recorded', function () {
    $product = Market::product();
    // Archived the old way: no competitors on the audit row.
    $product->forceFill(['archived_at' => now(), 'paused' => true])->save();
    DB::table('sim_offers')->where('asin', $product->asin)->delete();
    DB::table('sim_listings')->where('asin', $product->asin)->delete();

    $this->postJson("/api/products/{$product->id}/restore", ['confirm' => true])->assertOk();
    expect(DB::table('sim_offers')->where('asin', $product->asin)->whereNotNull('bot')->orderBy('bot')->pluck('bot')->all())
        ->toBe(['anchor', 'penny_pincher']);
});

it('respects the demo cap when restoring', function () {
    config(['demo.max_products' => 6]);
    $product = Market::product();
    $this->postJson("/api/products/{$product->id}/archive", ['confirm' => true])->assertOk();
    $this->postJson('/api/products', newProduct())->assertCreated();

    $this->postJson("/api/products/{$product->id}/restore", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('message', 'The public demo holds at most 6 products. Archive one first.');
    expect($product->refresh()->archived_at)->not->toBeNull();
});
