<?php

use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Scenario;
use App\Simulator\Engine\SimClock;
use App\Simulator\Engine\Simulation;
use App\Simulator\Persistence\WorldRepository;
use App\Simulator\SimulationRunner;
use Illuminate\Support\Facades\DB;

/** Recursively key-sort (jsonb does not preserve key order). */
function ksortDeep(mixed $v): mixed
{
    if (! is_array($v)) {
        return $v;
    }
    $v = array_map('ksortDeep', $v);
    if (! array_is_list($v)) {
        ksort($v);
    }

    return $v;
}

/** @return list<array<string, mixed>> event payloads without the per-run event id */
function persistedRun(int $seed, int $ticks): array
{
    test()->artisan('sim:reset', ['--seed' => $seed])->assertSuccessful();
    $runner = app(SimulationRunner::class);
    for ($i = 0; $i < $ticks; $i++) {
        $runner->tick();
    }

    return DB::table('sim_events')->where('run', app(WorldRepository::class)->currentRun())->orderBy('id')->pluck('payload')
        ->map(function ($p) {
            $payload = json_decode((string) $p, true);
            unset($payload['notification_id']);

            return ksortDeep($payload);
        })->all();
}

it('persists and reloads the world without changing the run (DB-backed == in-memory)', function () {
    persistedRun(42, 0);
    $config = config('simulator');
    $memory = Scenario::build($config['scenario'], 42, config('market.seller_id'), SimClock::at($config['epoch'], $config['tick_seconds']), app(BuyBoxScorer::class));
    $sim = new Simulation(app(BuyBoxScorer::class), new BotRegistry);
    $memoryEvents = [];
    for ($i = 0; $i < 150; $i++) {
        foreach ($sim->tick($memory)->events as $e) {
            $p = $e->toPayload();
            unset($p['notification_id']);
            $memoryEvents[] = ksortDeep($p);
        }
    }

    $runner = app(SimulationRunner::class);
    for ($i = 0; $i < 150; $i++) {
        $runner->tick();
    }
    $dbEvents = DB::table('sim_events')->orderBy('id')->pluck('payload')->map(function ($p) {
        $payload = json_decode((string) $p, true);
        unset($payload['notification_id']);

        return ksortDeep($payload);
    })->all();

    expect($dbEvents)->toBe($memoryEvents)
        ->and(app(WorldRepository::class)->load()->snapshot())->toBe($memory->snapshot());
});

it('reproduces the same persisted run from the same seed', function () {
    $first = persistedRun(42, 120);
    // Market time keeps moving forward across resets, so compare everything but time.
    $strip = fn (array $events) => array_map(function ($e) {
        unset($e['event_time'], $e['event_time_ms'], $e['payload']['change_trigger']['time_of_change']);

        return $e;
    }, $events);

    expect($first)->not->toBeEmpty()
        ->and($strip(persistedRun(42, 120)))->toBe($strip($first));
});

it('keeps market time monotonic across resets', function () {
    persistedRun(1, 10);
    $before = app(WorldRepository::class)->clockMs();
    $this->artisan('sim:reset', ['--seed' => 2])->assertSuccessful();
    expect(app(WorldRepository::class)->clockMs())->toBeGreaterThanOrEqual($before);
});

it('assigns every published event a unique id and publishes it to the stream', function () {
    persistedRun(42, 200);
    $ids = DB::table('sim_events')->pluck('event_id');
    expect($ids->unique()->count())->toBe($ids->count())
        ->and(app(RedisStreamNotifications::class)->read('t', 1000, 0))->toHaveCount($ids->count());
});

it('runs headless from the CLI and prints Buy Box changes', function () {
    $this->artisan('sim:run', ['--ticks' => 200, '--seed' => 42, '--fast' => true])
        ->expectsOutputToContain('Buy Box:')
        ->expectsOutputToContain('Done: 200 ticks')
        ->assertSuccessful();
});

it('rejects a speed outside 1x–100x', function () {
    $this->artisan('sim:run', ['--ticks' => 1, '--speed' => 500])->assertFailed();
});
