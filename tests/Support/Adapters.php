<?php

namespace Tests\Support;

use App\Repricer\Market\MarketAdapter;
use App\Simulator\Adapter\FaultInjector;
use App\Simulator\Adapter\SimulatorMarketAdapter;
use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\Simulation;
use App\Simulator\Persistence\WorldRepository;
use App\Support\RateLimiting\TokenBucket;
use Illuminate\Support\Facades\Redis;

final class Adapters
{
    public const GENEROUS = [
        'getItemOffers' => ['burst' => 1000, 'refill_ms' => 1],
        'patchListingsItem' => ['burst' => 1000, 'refill_ms' => 1],
    ];

    /**
     * Build a simulator adapter with explicit realism knobs and bind it as the MarketAdapter.
     *
     * @param  array<string, array{burst: int, refill_ms: int}>  $quotas
     */
    public static function simulator(int $http429Bps = 0, int $http503Bps = 0, int $retryAfterMs = 1000, array $quotas = self::GENEROUS, int $faultSeed = 7): SimulatorMarketAdapter
    {
        $adapter = new SimulatorMarketAdapter(
            app(WorldRepository::class),
            app(Simulation::class),
            app(RedisStreamNotifications::class),
            app(NotificationPublisher::class),
            app(TokenBucket::class),
            new FaultInjector(Redis::connection(), $faultSeed, $http429Bps, $http503Bps, $retryAfterMs),
            (string) config('market.seller_id'),
            $quotas,
            'test-consumer',
        );

        app()->instance(MarketAdapter::class, $adapter);

        return $adapter;
    }
}
