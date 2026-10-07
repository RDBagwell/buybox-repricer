<?php

use App\Repricer\Outbound\Backoff;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

it('grows exponentially with equal jitter and respects the cap', function () {
    $b = new Backoff(500, 8_000, new Randomizer(new Xoshiro256StarStar(3)));
    foreach ([1 => 500, 2 => 1000, 3 => 2000, 4 => 4000, 5 => 8000, 6 => 8000, 10 => 8000] as $attempt => $exp) {
        for ($i = 0; $i < 50; $i++) {
            expect($b->delayMs($attempt, null))->toBeGreaterThanOrEqual(intdiv($exp, 2))->toBeLessThanOrEqual($exp);
        }
    }
});

it('never waits less than Retry-After', function () {
    $b = new Backoff(500, 8_000, new Randomizer(new Xoshiro256StarStar(3)));
    expect($b->delayMs(1, 5_000))->toBe(5_000)
        ->and($b->delayMs(5, 1))->toBeGreaterThanOrEqual(4_000);
});

it('is reproducible with a seeded randomizer', function () {
    $a = new Backoff(500, 30_000, new Randomizer(new Xoshiro256StarStar(9)));
    $b = new Backoff(500, 30_000, new Randomizer(new Xoshiro256StarStar(9)));
    expect(array_map(fn ($i) => $a->delayMs($i, null), range(1, 8)))->toBe(array_map(fn ($i) => $b->delayMs($i, null), range(1, 8)));
});
