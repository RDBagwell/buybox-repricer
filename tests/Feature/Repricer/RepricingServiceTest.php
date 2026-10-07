<?php

use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Models\BuyBoxHistory;
use App\Repricer\Models\DuplicateDelivery;
use App\Repricer\Models\OfferSnapshot;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Pricing\RepricingService;
use App\Repricer\Settings\RepricerSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Queue::fake([PushPriceJob::class]);
});

function service(): RepricingService
{
    return app(RepricingService::class);
}

it('records a decision with its input snapshot and rule trace, and queues the push', function () {
    $d = service()->handle(Market::product(), Market::notification());

    expect($d->outcome)->toBe('reprice')
        // lowest eligible is PENNYWISE 13.99 (BARGAIN-BIN filtered) → 13.94, step 5% of 14.99 = 0.74 → 14.25
        ->and($d->new_price?->cents)->toBe(1425)
        ->and($d->old_price->cents)->toBe(1499)
        ->and(array_column($d->rule_trace, 'rule'))->toContain('competitor_filter', 'strategy', 'step_limit', 'floor_ceiling')
        ->and(OfferSnapshot::query()->where('decision_id', $d->id)->count())->toBe(3)
        ->and(OfferSnapshot::query()->where('decision_id', $d->id)->where('is_ours', true)->count())->toBe(1);

    Queue::assertPushed(PushPriceJob::class, fn (PushPriceJob $j) => $j->decisionId === $d->id);
});

it('treats a duplicate event as a recorded no-op, never a second reprice', function () {
    $n = Market::notification();
    $first = service()->handle(Market::product(), $n);
    $again = service()->handle(Market::product(), $n);

    expect($again->id)->toBe($first->id)
        ->and(PriceDecision::query()->count())->toBe(1)
        ->and(DuplicateDelivery::query()->where('decision_id', $first->id)->count())->toBe(1);
});

it('is idempotent through the job too: the same event delivered twice yields one decision', function () {
    $n = Market::notification();
    $product = Market::product();
    (new RepriceJob($product->id, $n->toArray()))->handle(service());
    (new RepriceJob($product->id, $n->toArray()))->handle(service());

    expect(PriceDecision::query()->count())->toBe(1);
});

it('recovers from a crash mid-job: the retry writes exactly one decision', function () {
    $n = Market::notification();
    $crash = true;
    DB::listen(function ($query) use (&$crash) {
        if ($crash && str_starts_with($query->sql, 'insert into "offer_snapshots"')) {
            $crash = false;
            throw new RuntimeException('worker killed');
        }
    });

    expect(fn () => service()->handle(Market::product(), $n))->toThrow(RuntimeException::class, 'worker killed');
    expect(PriceDecision::query()->count())->toBe(0); // the transaction rolled back

    $d = service()->handle(Market::product(), $n);
    expect(PriceDecision::query()->count())->toBe(1)
        ->and($d->snapshots()->count())->toBe(3);
});

it('resumes the push when a crash lost it after the decision committed', function () {
    $n = Market::notification();
    $d = service()->handle(Market::product(), $n);
    Queue::fake([PushPriceJob::class]); // forget the push: as if the worker died before it was queued

    service()->handle(Market::product(), $n);

    Queue::assertPushed(PushPriceJob::class, fn (PushPriceJob $j) => $j->decisionId === $d->id);
    expect(PriceDecision::query()->count())->toBe(1);
});

it('skips and records an event older than the snapshot behind the latest decision', function () {
    service()->handle(Market::product(), Market::notification(time: '2026-01-01T00:10:00Z'));
    $stale = service()->handle(Market::product(), Market::notification(time: '2026-01-01T00:09:59Z'));

    expect($stale->outcome)->toBe('stale')
        ->and($stale->new_price)->toBeNull()
        ->and($stale->reason)->toContain('older than');
    Queue::assertPushed(PushPriceJob::class, 1);
});

it('does not treat an event with the same snapshot time as stale', function () {
    service()->handle(Market::product(), Market::notification(time: '2026-01-01T00:10:00Z'));
    $same = service()->handle(Market::product(), Market::notification(time: '2026-01-01T00:10:00Z'));
    expect($same->outcome)->not->toBe('stale');
});

it('records a skip and pushes nothing when the kill switch is on', function () {
    app(RepricerSettings::class)->set(RepricerSettings::KILL_SWITCH, true);
    $d = service()->handle(Market::product(), Market::notification());

    expect($d->outcome)->toBe('skipped')->and($d->reason_code)->toBe('kill_switch');
    Queue::assertNothingPushed();
});

it('records dry-run decisions with the price it would set, and never pushes', function () {
    app(RepricerSettings::class)->set(RepricerSettings::DRY_RUN, true);
    $d = service()->handle(Market::product(), Market::notification());

    expect($d->outcome)->toBe('dry_run')
        ->and($d->new_price?->cents)->toBe(1425)
        ->and($d->reason)->toStartWith('Dry run');
    Queue::assertNothingPushed();
});

it('records a skip for a paused product', function () {
    $p = Market::product();
    $p->forceFill(['paused' => true])->save();

    expect(service()->handle($p, Market::notification())->reason_code)->toBe('paused');
    Queue::assertNothingPushed();
});

it('records configuration errors instead of pricing', function () {
    $p = Market::product();
    $p->forceFill(['cost' => 5000])->save(); // margin floor above the ceiling

    $d = service()->handle($p->fresh('rule') ?? $p, Market::notification());
    expect($d->outcome)->toBe('config_error');
    Queue::assertNothingPushed();
});

it('appends Buy Box history only when the winner changes', function () {
    service()->handle(Market::product(), Market::notification(buyBox: 'PENNYWISE', time: '2026-01-01T00:10:00Z'));
    service()->handle(Market::product(), Market::notification(buyBox: 'PENNYWISE', time: '2026-01-01T00:11:00Z'));
    service()->handle(Market::product(), Market::notification(buyBox: Market::US, time: '2026-01-01T00:12:00Z'));

    expect(BuyBoxHistory::query()->orderBy('id')->pluck('winner')->all())->toBe(['PENNYWISE', Market::US]);
});

it('uses market time, not wall-clock time, for decided_at', function () {
    $d = service()->handle(Market::product(), Market::notification());
    expect($d->decided_at->format('Y'))->toBe('2026')
        ->and($d->decided_at->format('Y-m-d'))->toBe('2026-01-01');
});

describe('the audit log is append-only', function () {
    it('refuses updates and deletes through the models', function () {
        $d = service()->handle(Market::product(), Market::notification());
        expect(fn () => $d->update(['reason' => 'rewritten']))->toThrow(LogicException::class, 'append-only');
        expect(fn () => $d->delete())->toThrow(LogicException::class, 'append-only');
    });

    it('refuses updates and deletes in the database itself', function () {
        $d = service()->handle(Market::product(), Market::notification());
        expect(fn () => DB::table('price_decisions')->where('id', $d->id)->update(['reason' => 'x']))->toThrow(QueryException::class, 'append-only');
    });

    it('refuses deletes of offer snapshots in the database', function () {
        service()->handle(Market::product(), Market::notification());
        expect(fn () => DB::table('offer_snapshots')->delete())->toThrow(QueryException::class, 'append-only');
    });
});
