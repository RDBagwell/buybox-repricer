<?php

namespace App\Providers;

use App\Repricer\Market\MarketAdapter;
use App\Repricer\Rules\DefaultPipeline;
use App\Repricer\Rules\Pipeline;
use App\Simulator\Adapter\FaultInjector;
use App\Simulator\Adapter\SimulatorMarketAdapter;
use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Simulation;
use App\Simulator\Persistence\WorldRepository;
use App\Support\RateLimiting\RedisTokenBucket;
use App\Support\RateLimiting\TokenBucket;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

/**
 * The one place that knows both sides: it binds the repricer's MarketAdapter to the simulator.
 * (Architecture tests forbid App\Repricer from importing App\Simulator anywhere else.)
 */
class MarketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Pipeline::class, fn () => DefaultPipeline::make());

        $this->app->singleton(TokenBucket::class, fn () => new RedisTokenBucket(Redis::connection()));

        // --- Simulator -------------------------------------------------------------
        $this->app->singleton(BuyBoxScorer::class, fn () => BuyBoxScorer::fromConfig((array) config('simulator.buybox')));
        $this->app->singleton(BotRegistry::class, fn () => new BotRegistry);
        $this->app->bind(WorldRepository::class, fn () => new WorldRepository(DB::connection()));

        $this->app->singleton(RedisStreamNotifications::class, fn () => new RedisStreamNotifications(
            Redis::connection(),
            (string) config('simulator.notifications.stream'),
            (string) config('simulator.notifications.group'),
            (int) config('simulator.notifications.maxlen'),
            (int) config('simulator.notifications.visibility_timeout_ms'),
        ));
        $this->app->alias(RedisStreamNotifications::class, NotificationPublisher::class);

        $this->app->singleton(FaultInjector::class, fn (Application $app) => new FaultInjector(
            Redis::connection(),
            (int) config('simulator.faults.seed'),
            (int) config('simulator.faults.http_429_bps'),
            (int) config('simulator.faults.http_503_bps'),
            (int) config('simulator.faults.retry_after_ms'),
            'sim:faults:seq',
            // Dashboard-controlled rates (sim_state) win over config once the world exists.
            function () use ($app): array {
                $worlds = $app->make(WorldRepository::class);
                if (! $worlds->exists()) {
                    return [(int) config('simulator.faults.http_429_bps'), (int) config('simulator.faults.http_503_bps')];
                }
                $c = $worlds->controls();

                return [max($c['fault_429_bps'], (int) config('simulator.faults.http_429_bps')), max($c['fault_503_bps'], (int) config('simulator.faults.http_503_bps'))];
            },
        ));

        // --- The boundary ----------------------------------------------------------
        $this->app->singleton(MarketAdapter::class, fn (Application $app) => new SimulatorMarketAdapter(
            $app->make(WorldRepository::class),
            $app->make(Simulation::class),
            $app->make(RedisStreamNotifications::class),
            $app->make(NotificationPublisher::class),
            $app->make(TokenBucket::class),
            $app->make(FaultInjector::class),
            (string) config('market.seller_id'),
            (array) config('simulator.quotas'),
            (string) config('simulator.notifications.consumer'),
        ));
    }
}
