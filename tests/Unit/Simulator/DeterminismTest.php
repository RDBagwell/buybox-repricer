<?php

use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Scenario;
use App\Simulator\Engine\SeededRng;
use App\Simulator\Engine\SimClock;
use App\Simulator\Engine\Simulation;
use App\Simulator\Engine\World;

function scenarioWorld(int $seed): World
{
    $config = require __DIR__.'/../../../config/simulator.php';

    return Scenario::build($config['scenario'], $seed, 'OUR-STORE', SimClock::at('2026-01-01T00:00:00Z', 15), BuyBoxScorer::fromConfig($config['buybox']));
}

/** @return array{events: list<array<string, mixed>>, final: array<string, mixed>} */
function runTicks(World $world, int $ticks): array
{
    $sim = new Simulation(new BuyBoxScorer, new BotRegistry);
    $events = [];
    for ($i = 0; $i < $ticks; $i++) {
        foreach ($sim->tick($world)->events as $e) {
            $events[] = $e->toPayload();
        }
    }

    return ['events' => $events, 'final' => $world->snapshot()];
}

it('reproduces the same run from the same seed', function () {
    $a = runTicks(scenarioWorld(42), 500);
    $b = runTicks(scenarioWorld(42), 500);

    expect($a['events'])->not->toBeEmpty()
        ->and($b)->toBe($a);
});

it('produces a different run from a different seed', function () {
    expect(runTicks(scenarioWorld(42), 300))->not->toBe(runTicks(scenarioWorld(43), 300));
});

it('continues identically after exporting and restoring the RNG state mid-run', function () {
    $straight = scenarioWorld(42);
    $full = runTicks($straight, 200);

    $split = scenarioWorld(42);
    runTicks($split, 100);
    $split->rng = SeededRng::restore($split->rng->export());
    runTicks($split, 100);

    expect($split->snapshot())->toBe($full['final']);
});

it('advances market time by the tick length only', function () {
    $w = scenarioWorld(1);
    $start = $w->clock->nowMs;
    runTicks($w, 20);
    expect($w->clock->nowMs - $start)->toBe(20 * 15_000);
});

it('emits at most one AnyOfferChanged per listing per tick, carrying lowest prices and the winner', function () {
    $w = scenarioWorld(42);
    $sim = new Simulation(new BuyBoxScorer, new BotRegistry);
    for ($i = 0; $i < 100; $i++) {
        $events = $sim->tick($w)->events;
        $asins = array_map(fn (AnyOfferChanged $e) => $e->asin, $events);
        expect($asins)->toBe(array_values(array_unique($asins)));
        foreach ($events as $e) {
            $p = $e->toPayload();
            expect($p['payload']['summary']['lowest_landed']['overall'])->toBeInt()
                ->and($p['payload']['summary']['buybox']['seller_id'])->toBe($w->listing($e->asin)?->buyBoxSellerId);
        }
    }
});

it('never lets a penny pincher go below its floor', function () {
    $w = scenarioWorld(42);
    runTicks($w, 500);
    foreach ($w->listings as $listing) {
        foreach ($listing->offers as $offer) {
            if ($offer->bot === 'penny_pincher') {
                expect($offer->price->cents)->toBeGreaterThanOrEqual((int) $offer->botParams['floor']);
            }
        }
    }
});
