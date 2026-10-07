<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDashboardAccess();
    }

    /**
     * Who may see and operate the repricer dashboard, and how often they may change things.
     *
     * Demo mode: anyone (including anonymous visitors) may view and operate, rate-limited per IP.
     * Operator mode: a logged-in user is required for every dashboard page, API call and channel.
     */
    protected function configureDashboardAccess(): void
    {
        Gate::define('view-dashboard', fn (?User $user): bool => (bool) config('demo.enabled') || $user !== null);
        Gate::define('operate', fn (?User $user): bool => (bool) config('demo.enabled') || $user !== null);

        RateLimiter::for('dashboard-mutations', fn (Request $request) => Limit::perMinute((int) config('demo.mutations_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Resetting the world is heavier: a couple per minute is plenty.
        RateLimiter::for('dashboard-reset', fn (Request $request) => Limit::perMinute(2)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
