<?php

namespace App\Simulator;

use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Simulation;
use App\Simulator\Engine\TickResult;
use App\Simulator\Engine\World;
use App\Simulator\Persistence\WorldRepository;
use Illuminate\Support\Str;

/**
 * Runs persisted ticks: lock -> load -> tick (pure) -> save -> record events, in one transaction,
 * then publish the notifications after commit.
 *
 * Event ids are UUIDv7s assigned here, outside the deterministic engine, so reruns of a seed
 * never collide with ids the repricer has already processed.
 */
final class SimulationRunner
{
    public function __construct(
        private readonly WorldRepository $worlds,
        private readonly Simulation $simulation,
        private readonly NotificationPublisher $publisher,
        private readonly BuyBoxScorer $scorer,
    ) {}

    /**
     * Rebuild the world from the scenario, then publish one "reset" AnyOfferChanged per listing
     * so subscribers start from the current state instead of waiting for the first change.
     */
    public function reset(int $seed): World
    {
        $world = $this->worlds->reset(
            (array) config('simulator.scenario'), // @phpstan-ignore argument.type
            $seed,
            (string) config('market.seller_id'),
            (string) config('simulator.epoch'),
            (int) config('simulator.tick_seconds'),
            $this->scorer,
        );

        $run = $this->worlds->currentRun();
        $events = [];
        foreach ($world->listings as $listing) {
            $event = AnyOfferChanged::fromListing($listing, 0, $world->clock->nowMs, (string) $listing->buyBoxSellerId, 'reset')
                ->withEventId((string) Str::uuid7());
            $this->worlds->recordEvent($event, $run);
            $events[] = $event;
        }

        foreach ($events as $event) {
            $this->publisher->publish($event);
        }

        return $world;
    }

    public function tick(): TickResult
    {
        /** @var TickResult $result */
        $result = $this->worlds->db()->transaction(function () {
            $world = $this->worlds->load(lock: true);
            $result = $this->simulation->tick($world);
            $this->worlds->saveTick($world);

            $run = $this->worlds->currentRun();
            $events = array_map(fn (AnyOfferChanged $e) => $e->withEventId((string) Str::uuid7()), $result->events);
            foreach ($events as $event) {
                $this->worlds->recordEvent($event, $run);
            }

            return new TickResult($result->tick, $result->marketTimeMs, $events, $result->buyBoxChanges, $result->moves, $result->changedAsins);
        });

        foreach ($result->events as $event) {
            $this->publisher->publish($event);
        }

        return $result;
    }
}
