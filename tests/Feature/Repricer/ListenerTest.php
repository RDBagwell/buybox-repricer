<?php

use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Market\MarketAdapter;
use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\SimulationRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Queue::fake([RepriceJob::class]);
});

function tickUntilEvents(int $max = 100): int
{
    $events = 0;
    for ($i = 0; $i < $max && $events === 0; $i++) {
        $events += count(app(SimulationRunner::class)->tick()->events);
    }

    return $events;
}

it('turns each notification into one RepriceJob per product on the ASIN and acknowledges it', function () {
    expect(tickUntilEvents())->toBeGreaterThan(0);

    $this->artisan('repricer:listen', ['--once' => true])->assertSuccessful();

    Queue::assertPushed(RepriceJob::class);
    expect(app(RedisStreamNotifications::class)->pendingCount())->toBe(0);
});

it('redelivers a notification that was received but never acknowledged (worker crash)', function () {
    config(['simulator.notifications.visibility_timeout_ms' => 0]);
    app()->forgetInstance(RedisStreamNotifications::class);
    app()->forgetInstance(MarketAdapter::class);
    tickUntilEvents();

    $first = app(MarketAdapter::class)->receiveNotifications(10);
    expect($first)->not->toBeEmpty();
    // …crash: no acknowledge.

    $again = app(MarketAdapter::class)->receiveNotifications(10);
    expect(array_map(fn ($n) => $n->notificationId, $again))->toBe(array_map(fn ($n) => $n->notificationId, $first));

    foreach ($again as $n) {
        app(MarketAdapter::class)->acknowledge($n);
    }
    expect(app(MarketAdapter::class)->receiveNotifications(10))->toBe([]);
});
