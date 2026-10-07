<?php

use App\Support\RateLimiting\RedisTokenBucket;
use Illuminate\Support\Facades\Redis;

it('allows a burst, then reports the wait until the next token', function () {
    $now = 1_000_000;
    $bucket = new RedisTokenBucket(Redis::connection(), 'tb:', function () use (&$now) {
        return $now;
    });

    expect($bucket->take('op', 3, 200))->toBe(0)
        ->and($bucket->take('op', 3, 200))->toBe(0)
        ->and($bucket->take('op', 3, 200))->toBe(0)
        ->and($bucket->take('op', 3, 200))->toBe(200);

    $now += 199;
    expect($bucket->take('op', 3, 200))->toBe(1);
    $now += 1;
    expect($bucket->take('op', 3, 200))->toBe(0)
        ->and($bucket->take('op', 3, 200))->toBe(200);
});

it('refills to the burst after idling, never beyond', function () {
    $now = 5_000_000;
    $bucket = new RedisTokenBucket(Redis::connection(), 'tb:', function () use (&$now) {
        return $now;
    });
    $bucket->take('op', 2, 1000);
    $now += 60_000;

    expect($bucket->take('op', 2, 1000))->toBe(0)
        ->and($bucket->take('op', 2, 1000))->toBe(0)
        ->and($bucket->take('op', 2, 1000))->toBeGreaterThan(0);
});

it('keeps sustained throughput at the configured rate', function () {
    $now = 9_000_000;
    $bucket = new RedisTokenBucket(Redis::connection(), 'tb:', function () use (&$now) {
        return $now;
    });
    $granted = 0;
    for ($ms = 0; $ms < 10_000; $ms += 10) { // try every 10 ms for 10 s at 5 req/s, burst 10
        $now = 9_000_000 + $ms;
        $granted += $bucket->take('op', 10, 200) === 0 ? 1 : 0;
    }
    expect($granted)->toBe(10 + 49); // the burst, then one per 200 ms over the remaining window
});
