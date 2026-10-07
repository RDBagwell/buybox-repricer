<?php

use App\Repricer\Models\AuditEntry;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    config(['demo.enabled' => true]);
});

/** FP-1L-STEEL: cost 14.00 + fees 4.35; with min margin 3.00 the margin floor is 21.35. */
function validRule(array $overrides = []): array
{
    return $overrides + ['strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 2699, 'ceiling' => 3499, 'min_margin' => 300, 'max_step_pct' => 5, 'cooldown_sec' => 120];
}

function fpId(): int
{
    return Market::product('FP-1L-STEEL')->id;
}

it('saves a valid rule and audits exactly what changed, before and after', function () {
    $this->putJson('/api/products/'.fpId().'/rule', validRule(['floor' => 2599, 'max_step_pct' => 8]))
        ->assertOk()->assertJsonPath('product.rule.floor', 2599);

    $audit = AuditEntry::query()->where('action', 'rule.updated')->sole();
    expect($audit->before)->toBe(['floor' => 2699, 'max_step_pct' => 5])
        ->and($audit->after)->toBe(['floor' => 2599, 'max_step_pct' => 8])
        ->and($audit->actor)->toStartWith('visitor:')
        ->and($audit->product_id)->toBe(fpId());
});

it('rejects invalid rules on the server', function (array $override, string $field, string $message) {
    $this->putJson('/api/products/'.fpId().'/rule', validRule($override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'floor above ceiling' => [['floor' => 3600, 'ceiling' => 3500], 'floor', 'must not be above the ceiling'],
    'floor below margin floor' => [['floor' => 2000], 'floor', 'below the margin floor (21.35'],
    'step percentage too big' => [['max_step_pct' => 75], 'max_step_pct', 'must not be greater than 50'],
    'step percentage zero' => [['max_step_pct' => 0], 'max_step_pct', 'must be at least 1'],
    'negative offset' => [['offset' => -1], 'offset', 'must be at least 0'],
    'unknown strategy' => [['strategy' => 'yolo'], 'strategy', 'invalid'],
    'fractional cents' => [['floor' => 2699.5], 'floor', 'must be an integer'],
    'missing ceiling' => [['ceiling' => null], 'ceiling', 'required'],
]);

it('cannot be used to write fields outside the rule (mass assignment)', function () {
    $id = fpId();
    $this->putJson("/api/products/{$id}/rule", validRule(['product_id' => 999, 'id' => 999, 'cost' => 1]))->assertOk();
    expect(Market::product('FP-1L-STEEL')->rule?->product_id)->toBe($id)
        ->and(Market::product('FP-1L-STEEL')->cost->cents)->toBe(1400);
});
