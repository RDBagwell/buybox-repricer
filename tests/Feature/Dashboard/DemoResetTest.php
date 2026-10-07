<?php

use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;
use App\Repricer\Settings\RepricerSettings;
use App\Support\RateLimiting\TokenBucket;
use Illuminate\Support\Facades\DB;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Adapters::simulator();
    config(['demo.prime_ticks' => 24]);
});

it('rebuilds the world from its seed, wiping what visitors changed', function () {
    config(['demo.enabled' => true]);
    app(RepricerSettings::class)->set('kill_switch', true);
    Product::query()->update(['paused' => true, 'paused_reason' => 'visitor']);
    DB::table('sim_state')->update(['speed' => 1, 'fault_503_bps' => 3000]);

    $this->artisan('demo:reset')->assertSuccessful();

    expect(app(RepricerSettings::class)->killSwitch())->toBeFalse()
        ->and(Product::query()->where('paused', true)->count())->toBe(0)
        ->and(DB::table('sim_state')->value('fault_503_bps'))->toBe(0)
        ->and(DB::table('sim_state')->value('tick'))->toBe(24);
});

it('primes a price war with every push settled, so the war is not frozen after a reset', function () {
    config(['demo.enabled' => true]);
    // The prime runs on the sync queue, where a push that has to wait for a rate-limit token
    // is released and dropped. Make every other push wait, as a busy prime does for real.
    app()->instance(TokenBucket::class, new class implements TokenBucket
    {
        private int $calls = 0;

        public function take(string $bucket, int $burst, int $refillMs): int
        {
            return str_starts_with($bucket, 'repricer:') && $this->calls++ % 2 === 0 ? 50 : 0;
        }
    });
    $this->artisan('demo:reset')->assertSuccessful();

    $unfinished = PriceDecision::query()->where('outcome', 'reprice')
        ->whereDoesntHave('pushes', fn ($q) => $q->whereIn('status', PushStatus::terminalValues()))
        ->count();

    expect(PriceDecision::query()->where('outcome', 'reprice')->count())->toBeGreaterThan(0)
        ->and($unfinished)->toBe(0);
});

it('refuses to wipe anything outside demo mode', function () {
    config(['demo.enabled' => false]);
    $before = PriceDecision::query()->count();

    $this->artisan('demo:reset')->assertFailed();

    expect(PriceDecision::query()->count())->toBe($before);
});
