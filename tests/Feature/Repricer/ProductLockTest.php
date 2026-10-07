<?php

use App\Repricer\Jobs\ProductLock;
use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Models\PriceDecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    config(['queue.default' => 'redis']);
});

function queuedJobs(string $queue): int
{
    return (int) Queue::connection('redis')->size($queue);
}

it('serialises jobs for the same product: a held lock defers the job instead of racing it', function () {
    $product = Market::product();
    $lock = Cache::lock('laravel-queue-overlap:reprice:product:'.$product->id, 60);
    expect($lock->get())->toBeTrue();

    RepriceJob::dispatch($product->id, Market::notification()->toArray());
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'reprice', '--once' => true])->assertSuccessful();

    expect(PriceDecision::query()->count())->toBe(0)  // did not run while locked…
        ->and(queuedJobs('reprice'))->toBe(1);         // …but was released back, not lost

    $lock->release();
    sleep(1); // the release delay is one second
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'reprice,push', '--stop-when-empty' => true])->assertSuccessful();

    expect(PriceDecision::query()->count())->toBe(1);
});

it('does not block other products', function () {
    $locked = Market::product('MAT-SIL-2');
    $other = Market::product('FP-1L-STEEL');
    Cache::lock('laravel-queue-overlap:reprice:product:'.$locked->id, 60)->get();

    RepriceJob::dispatch($other->id, Market::notification(asin: 'B0SIM00001', offers: [[Market::US, 2899], ['PENNYWISE', 2799]])->toArray());
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'reprice', '--once' => true])->assertSuccessful();

    expect(PriceDecision::query()->where('product_id', $other->id)->count())->toBe(1);
});

it('uses separate reprice and push scopes so a decision never waits on its own push', function () {
    expect(ProductLock::for(ProductLock::REPRICE, 1)->key)->toBe('reprice:product:1')
        ->and(ProductLock::for(ProductLock::PUSH, 1)->key)->toBe('push:product:1')
        ->and((new RepriceJob(1, []))->middleware()[0]->key)->toBe('reprice:product:1');
});

it('runs the queued reprice and push end to end through Redis', function () {
    RepriceJob::dispatch(Market::product()->id, Market::notification()->toArray());
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'reprice,push', '--stop-when-empty' => true])->assertSuccessful();

    $d = PriceDecision::query()->sole();
    expect($d->pushes()->sole()->status)->toBe('succeeded');
    expect(Redis::connection()->command('keys', ['*overlap*']))->toBe([]); // locks released
});

it('retries a throttled push through the queue with a Retry-After delay', function () {
    Adapters::simulator(http429Bps: 10_000, retryAfterMs: 1_000);
    RepriceJob::dispatch(Market::product()->id, Market::notification()->toArray());
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'reprice,push', '--stop-when-empty' => true])->assertSuccessful();

    $d = PriceDecision::query()->sole();
    expect($d->pushes()->count())->toBe(1)
        ->and(queuedJobs('push'))->toBe(1); // released with a delay, waiting to retry

    Adapters::simulator(); // the market recovers
    sleep(2);
    $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'push', '--stop-when-empty' => true])->assertSuccessful();

    expect($d->pushes()->pluck('status')->all())->toBe(['retrying', 'succeeded']);
});
