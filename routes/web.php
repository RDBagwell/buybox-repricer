<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\ProductController;
use App\Http\Controllers\Dashboard\SimulatorController;
use App\Http\Controllers\Dashboard\SwitchController;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// The repricer dashboard is the home page in demo mode (anyone) or for a logged-in operator;
// everyone else gets the welcome page with its login link.
Route::get('/', function () {
    return Gate::allows('view-dashboard')
        ? app(DashboardController::class)->page()
        : inertia('welcome');
})->name('home');

// Replay mode: a recorded run played in the browser. Public in every mode (it is recorded output).
Route::inertia('replay', 'repricer/replay')->name('replay');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'page'])->name('dashboard');
});

/*
| Dashboard API (session + CSRF via the web middleware group).
| Reads: can:view-dashboard. Every mutation: can:operate + a rate limit.
*/
Route::pattern('product', '[0-9]+');

Route::prefix('api')->name('api.')->group(function () {
    Route::middleware('can:view-dashboard')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'state'])->name('dashboard');
        Route::get('decisions', [DashboardController::class, 'decisions'])->name('decisions');
        Route::get('heartbeat', [DashboardController::class, 'ping'])->name('heartbeat');
        Route::get('products/{product}/series', [DashboardController::class, 'series'])->name('products.series');
        Route::get('simulator', [SimulatorController::class, 'show'])->name('simulator');
    });

    Route::middleware(['can:operate', 'throttle:dashboard-mutations'])->group(function () {
        Route::post('switches/kill', [SwitchController::class, 'killSwitch'])->name('switches.kill');
        Route::post('switches/dry-run', [SwitchController::class, 'dryRun'])->name('switches.dry-run');

        Route::post('products/{product}/pause', [ProductController::class, 'pause'])->name('products.pause');
        Route::post('products/{product}/resume', [ProductController::class, 'resume'])->name('products.resume');
        Route::put('products/{product}/rule', [ProductController::class, 'updateRule'])->name('products.rule');

        Route::post('simulator/speed', [SimulatorController::class, 'speed'])->name('simulator.speed');
        Route::post('simulator/running', [SimulatorController::class, 'running'])->name('simulator.running');
        Route::post('simulator/faults', [SimulatorController::class, 'faults'])->name('simulator.faults');
        Route::post('simulator/bots', [SimulatorController::class, 'addBot'])->name('simulator.bots.add');
        Route::delete('simulator/bots/{asin}/{seller}', [SimulatorController::class, 'removeBot'])->name('simulator.bots.remove');
        Route::post('simulator/stockout', [SimulatorController::class, 'stockout'])->name('simulator.stockout');
    });

    Route::post('simulator/reset', [SimulatorController::class, 'reset'])
        ->middleware(['can:operate', 'throttle:dashboard-reset'])->name('simulator.reset');
});

require __DIR__.'/settings.php';
