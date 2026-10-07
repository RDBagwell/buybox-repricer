<?php

use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Models\AuditEntry;
use App\Repricer\Models\PricePush;
use App\Repricer\Outbound\PricePusher;
use App\Repricer\Pricing\RepricingService;
use App\Repricer\Safety\CircuitBreaker;
use App\Repricer\Safety\RepriceRateBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    Queue::fake([PushPriceJob::class]);
    config(['repricer.breaker.max_reprices_per_hour' => 3, 'demo.enabled' => true]);
});

/** Decide and push one reprice for MAT-SIL-2 at the given market minute, ignoring cooldown. */
function repriceOnce(int $minute, int $competitor): ?PricePush
{
    $p = Market::product();
    $p->forceFill(['last_price_change_at' => null])->save();
    DB::table('sim_state')->update(['clock_ms' => (new DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp() * 1000 + $minute * 60_000]);
    $d = app(RepricingService::class)->handle(Market::product(), Market::notification([[Market::US, $p->current_price->cents], ['PENNYWISE', $competitor]], time: (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify("+{$minute} minutes")->format(DATE_ATOM)));
    if ($d->outcome !== 'reprice') {
        return null;
    }
    app(PricePusher::class)->attempt($d);

    return PricePush::query()->where('decision_id', $d->id)->latest('id')->first();
}

it('trips after N reprices in a market hour: pauses, explains, audits and blocks the push', function () {
    expect(repriceOnce(1, 1450)?->status)->toBe('succeeded')
        ->and(repriceOnce(2, 1400)?->status)->toBe('succeeded')
        ->and(repriceOnce(3, 1350)?->status)->toBe('succeeded');

    $blocked = repriceOnce(4, 1300);

    expect($blocked?->status)->toBe('blocked')
        ->and(Market::product()->paused)->toBeTrue()
        ->and(Market::product()->paused_reason)->toContain('Circuit breaker: reprice #4 within one market hour blocked (limit 3)');

    $audit = AuditEntry::query()->where('action', 'breaker.tripped')->sole();
    expect($audit->actor)->toBe('system')
        ->and($audit->after)->toMatchArray(['paused' => true, 'reprices_last_hour' => 3, 'limit' => 3]);
});

it('does not resume on its own, even after the hour has passed', function () {
    foreach ([[1, 1450], [2, 1400], [3, 1350], [4, 1300]] as [$m, $c]) {
        repriceOnce($m, $c);
    }

    // Two market hours later, still paused: decisions are skipped as paused, nothing is pushed.
    DB::table('sim_state')->update(['clock_ms' => (new DateTimeImmutable('2026-01-01T03:00:00Z'))->getTimestamp() * 1000]);
    $d = app(RepricingService::class)->handle(Market::product(), Market::notification([[Market::US, 1350], ['PENNYWISE', 1200]], time: '2026-01-01T03:00:00Z'));

    expect($d->reason_code)->toBe('paused')
        ->and(Market::product()->paused)->toBeTrue();
});

it('only resumes through an explicit, audited operator action', function () {
    foreach ([[1, 1450], [2, 1400], [3, 1350], [4, 1300]] as [$m, $c]) {
        repriceOnce($m, $c);
    }

    $this->postJson('/api/products/'.Market::product()->id.'/resume', ['acknowledge' => true])->assertOk()
        ->assertJsonPath('product.paused', false);

    $audit = AuditEntry::query()->where('action', 'product.resumed')->sole();
    expect($audit->before['paused_reason'] ?? '')->toContain('Circuit breaker');
});

it('counts reprices in market time, not wall-clock time', function () {
    repriceOnce(1, 1450);
    repriceOnce(2, 1400);
    // 70 market minutes later the first two are outside the window.
    expect(repriceOnce(72, 1350)?->status)->toBe('succeeded')
        ->and(repriceOnce(73, 1300)?->status)->toBe('succeeded')
        ->and(Market::product()->paused)->toBeFalse();
});

it('is bound at the seam session 1 left', function () {
    expect(app(CircuitBreaker::class))->toBeInstanceOf(RepriceRateBreaker::class);
});
