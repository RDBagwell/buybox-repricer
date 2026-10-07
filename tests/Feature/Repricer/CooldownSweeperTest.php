<?php

use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Pricing\CooldownSweeper;
use App\Repricer\Pricing\RepricingService;
use App\Simulator\Persistence\WorldRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    Queue::fake([PushPriceJob::class]);
});

function setMarketClock(string $iso): void
{
    DB::table('sim_state')->update(['clock_ms' => (new DateTimeImmutable($iso))->getTimestamp() * 1000]);
}

it('re-decides from the audited snapshot once a skipped cooldown expires in market time', function () {
    $p = Market::product();
    $p->forceFill(['last_price_change_at' => '2026-01-01T00:09:00Z'])->save();
    setMarketClock('2026-01-01T00:10:00Z');

    $skipped = app(RepricingService::class)->handle($p, Market::notification(time: '2026-01-01T00:10:00Z'));
    expect($skipped->reason_code)->toBe('cooldown');

    // Still cooling down (cooldown is 300 s): nothing to do.
    expect(app(CooldownSweeper::class)->sweep())->toBe(0);

    // Market time passes with no new notifications.
    setMarketClock('2026-01-01T00:14:01Z');
    expect(app(CooldownSweeper::class)->sweep())->toBe(1);

    $recheck = PriceDecision::query()->latest('id')->firstOrFail();
    expect($recheck->event_id)->toBe($skipped->event_id.'#recheck')
        ->and($recheck->outcome)->toBe('reprice')
        ->and($recheck->snapshots()->count())->toBe($skipped->snapshots()->count());
});

it('does not queue the same re-check twice', function () {
    $p = Market::product();
    $p->forceFill(['last_price_change_at' => '2026-01-01T00:09:00Z'])->save();
    setMarketClock('2026-01-01T00:10:00Z');
    app(RepricingService::class)->handle($p, Market::notification(time: '2026-01-01T00:10:00Z'));
    setMarketClock('2026-01-01T00:20:00Z');

    Queue::fake();
    expect(app(CooldownSweeper::class)->sweep())->toBe(1)
        ->and(app(CooldownSweeper::class)->sweep())->toBe(0);
});

it('ignores products whose latest decision was not a cooldown skip', function () {
    app(RepricingService::class)->handle(Market::product(), Market::notification());
    setMarketClock('2026-01-02T00:00:00Z');

    expect(app(CooldownSweeper::class)->sweep())->toBe(0);
});

it('reads market time from the adapter', function () {
    setMarketClock('2026-03-01T00:00:00Z');
    expect(app(WorldRepository::class)->clockMs())->toBe((new DateTimeImmutable('2026-03-01T00:00:00Z'))->getTimestamp() * 1000);
});

it('drops a queued re-check that a newer decision has superseded, instead of recording it as stale', function () {
    Queue::fake();
    $p = Market::product();
    $p->forceFill(['last_price_change_at' => '2026-01-01T00:09:00Z'])->save();
    setMarketClock('2026-01-01T00:10:00Z');
    app(RepricingService::class)->handle($p, Market::notification(time: '2026-01-01T00:10:00Z'));
    setMarketClock('2026-01-01T00:20:00Z');
    app(CooldownSweeper::class)->sweep();

    $recheck = null;
    Queue::assertPushed(RepriceJob::class, function ($job) use (&$recheck) {
        $recheck = $job;

        return true;
    });

    // A newer notification is decided before the re-check job runs.
    app(RepricingService::class)->handle(Market::product(), Market::notification(time: '2026-01-01T00:19:00Z'));
    $before = PriceDecision::query()->count();

    $recheck->handle(app(RepricingService::class));

    expect(PriceDecision::query()->count())->toBe($before)
        ->and(PriceDecision::query()->where('outcome', 'stale')->count())->toBe(0);
});

it('still re-checks a cooldown skip that a late, stale notification was recorded after', function () {
    $p = Market::product();
    $p->forceFill(['last_price_change_at' => '2026-01-01T00:09:00Z'])->save();
    setMarketClock('2026-01-01T00:10:00Z');
    $skip = app(RepricingService::class)->handle($p, Market::notification(time: '2026-01-01T00:10:00Z'));
    expect($skip->reason_code)->toBe('cooldown');

    // A notification from before the skip arrives late: recorded as stale, after the skip.
    $stale = app(RepricingService::class)->handle(Market::product(), Market::notification(time: '2026-01-01T00:08:00Z'));
    expect($stale->outcome)->toBe('stale');

    setMarketClock('2026-01-01T00:20:00Z');
    Queue::fake();
    expect(app(CooldownSweeper::class)->sweep())->toBe(1);
});
