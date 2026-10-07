<?php

use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Pricing\RepricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Market;

beforeEach(function () {
    Market::seed();
    Queue::fake([PushPriceJob::class]);
});

it('builds one chart point per decision with landed prices and the Buy Box holder', function () {
    DB::table('sim_state')->update(['clock_ms' => (new DateTimeImmutable('2026-01-01T00:30:00Z'))->getTimestamp() * 1000]);
    app(RepricingService::class)->handle(Market::product(), Market::notification(time: '2026-01-01T00:10:00Z'));

    $series = app(DashboardQuery::class)->series(Market::product()->load('rule'));
    $last = end($series['points']);

    expect($last['prices'])->toMatchArray(['ours' => 1499, 'PENNYWISE' => 1399])
        ->and($last['buybox'])->toBe('PENNYWISE')
        ->and($series['floor'])->toBe(1099)
        ->and($series['ceiling'])->toBe(1799);
});

it('carries the last known prices into the window when the listing has been quiet', function () {
    app(RepricingService::class)->handle(Market::product(), Market::notification(time: '2026-01-01T00:10:00Z'));
    DB::table('sim_state')->update(['clock_ms' => (new DateTimeImmutable('2026-01-01T09:00:00Z'))->getTimestamp() * 1000]);

    $series = app(DashboardQuery::class)->series(Market::product()->load('rule'), 3);

    expect($series['points'])->not->toBeEmpty()
        ->and($series['points'][0]['t'])->toBe($series['from'])
        ->and($series['points'][0]['prices']['ours'] ?? null)->toBe(1499);
});
