<?php

namespace App\Providers;

use App\Repricer\Dashboard\ProductPresenter;
use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Listeners\DispatchRepricing;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Outbound\Backoff;
use App\Repricer\Outbound\PricePusher;
use App\Repricer\Pricing\ContextBuilder;
use App\Repricer\Pricing\RepricingService;
use App\Repricer\Rules\Pipeline;
use App\Repricer\Safety\AuditLog;
use App\Repricer\Safety\CircuitBreaker;
use App\Repricer\Safety\RepriceRateBreaker;
use App\Repricer\Settings\RepricerSettings;
use App\Support\RateLimiting\TokenBucket;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class RepricerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RepricerSettings::class);

        $this->app->bind(ProductPresenter::class, fn () => new ProductPresenter((int) config('repricer.breaker.max_reprices_per_hour')));

        $this->app->bind(AuditLog::class, fn (Application $app) => new AuditLog($app->make(MarketAdapter::class)));
        $this->app->bind(CircuitBreaker::class, fn (Application $app) => new RepriceRateBreaker(
            $app->make(MarketAdapter::class),
            $app->make(AuditLog::class),
            (int) config('repricer.breaker.max_reprices_per_hour'),
        ));

        $this->app->bind(ContextBuilder::class, fn () => new ContextBuilder((string) config('market.seller_id')));

        $this->app->bind(RepricingService::class, fn (Application $app) => new RepricingService(
            $app->make(Pipeline::class),
            $app->make(ContextBuilder::class),
            $app->make(MarketAdapter::class),
            $app->make(RepricerSettings::class),
            (string) config('market.seller_id'),
        ));

        $this->app->bind(Backoff::class, fn () => new Backoff(
            (int) config('repricer.push.backoff_base_ms'),
            (int) config('repricer.push.backoff_cap_ms'),
        ));

        $this->app->bind(PricePusher::class, fn (Application $app) => new PricePusher(
            $app->make(MarketAdapter::class),
            $app->make(TokenBucket::class),
            $app->make(Backoff::class),
            $app->make(RepricerSettings::class),
            $app->make(CircuitBreaker::class),
            (int) config('repricer.push.max_attempts'),
        ));
    }

    public function boot(): void
    {
        Event::listen(OfferChangeReceived::class, DispatchRepricing::class);
    }
}
