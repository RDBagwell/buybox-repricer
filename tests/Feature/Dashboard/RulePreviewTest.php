<?php

use App\Demo\HeadlessLoop;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\AuditEntry;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;
use App\Repricer\Settings\RepricerSettings;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    config(['demo.enabled' => true]);
    app(HeadlessLoop::class)->drain(); // the opening snapshots get decided
});

/** The baking mat's current rule, with overrides (integer cents). */
function draft(array $overrides = []): array
{
    return array_replace(['strategy' => 'beat_lowest', 'offset' => 5, 'floor' => 1099, 'ceiling' => 1799, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 300], $overrides);
}

it('previews the price the draft rules would set, from the latest snapshot, without writing anything', function () {
    $product = Market::product();
    $before = [PriceDecision::query()->count(), PricePush::query()->count(), AuditEntry::query()->count()];

    $r = $this->postJson("/api/products/{$product->id}/rule/preview", draft())->assertOk();

    expect($r->json('preview.available'))->toBeTrue()
        ->and($r->json('preview.old_price'))->toBe($product->fresh()->current_price->cents)
        ->and($r->json('preview.trace'))->not->toBeEmpty()
        ->and([PriceDecision::query()->count(), PricePush::query()->count(), AuditEntry::query()->count()])->toBe($before);
});

it('reflects the draft: a higher floor is respected in the previewed price', function () {
    $product = Market::product();
    $floor = $product->fresh()->current_price->cents + 200;

    $r = $this->postJson("/api/products/{$product->id}/rule/preview", draft(['floor' => $floor, 'max_step_pct' => 50]))->assertOk();

    expect($r->json('preview.outcome'))->toBe('reprice')
        ->and($r->json('preview.new_price'))->toBeGreaterThanOrEqual($floor);
});

it('leaves the gates open but reports them as notes', function () {
    $product = Market::product();
    app(RepricerSettings::class)->set('kill_switch', true);
    $product->forceFill(['paused' => true, 'last_price_change_at' => app(MarketAdapter::class)->now()])->save();

    $notes = $this->postJson("/api/products/{$product->id}/rule/preview", draft())->assertOk()->json('preview.notes');

    expect(implode(' ', $notes))->toContain('kill switch')->toContain('paused')->toContain('cooling down');
});

it('validates the draft exactly like the rule editor', function () {
    $product = Market::product();
    $this->postJson("/api/products/{$product->id}/rule/preview", draft(['floor' => 500]))
        ->assertUnprocessable()->assertJsonValidationErrors('floor');
});

it('says so when there is no snapshot yet', function () {
    $this->postJson('/api/products', [
        'title' => 'Ceramic Mug, 12 oz', 'sku' => 'MUG-PRE-1', 'cost' => 400, 'fees' => 150, 'shipping' => 0, 'price' => 1499, 'competitors' => [],
        'strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 899, 'ceiling' => 1999, 'min_margin' => 200, 'max_step_pct' => 5, 'cooldown_sec' => 120,
    ])->assertCreated();
    $id = Market::product('MUG-PRE-1')->id;

    $this->postJson("/api/products/{$id}/rule/preview", draft(['floor' => 899, 'ceiling' => 1999, 'min_margin' => 200]))
        ->assertOk()->assertJsonPath('preview.available', false);
});
