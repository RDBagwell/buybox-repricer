<?php

namespace App\Simulator;

use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\Simulation;
use App\Simulator\Engine\TickResult;
use App\Simulator\Persistence\WorldRepository;
use Illuminate\Support\Str;

/**
 * Runs persisted ticks: lock → load → tick (pure) → save → record events, in one transaction,
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
    ) {}

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
