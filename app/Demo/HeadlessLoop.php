<?php

namespace App\Demo;

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Pricing\CooldownSweeper;
use App\Simulator\Engine\TickResult;
use App\Simulator\SimulationRunner;

/**
 * Runs simulator and repricer together in one process: tick, drain notifications into the
 * repricer (whatever queue connection is configured), sweep cooldowns. Used to prime the demo,
 * record a replay, and in the headless simulation tests.
 */
final class HeadlessLoop
{
    public function __construct(
        private readonly SimulationRunner $runner,
        private readonly MarketAdapter $market,
        private readonly CooldownSweeper $sweeper,
    ) {}

    /**
     * @param  (\Closure(TickResult): void)|null  $afterTick
     * @return array{ticks: int, notifications: int}
     */
    public function run(int $ticks, ?\Closure $afterTick = null): array
    {
        $notifications = $this->drain();
        for ($t = 0; $t < $ticks; $t++) {
            $result = $this->runner->tick();
            $notifications += $this->drain();
            $this->sweeper->sweep();
            if ($afterTick !== null) {
                $afterTick($result);
            }
        }

        return ['ticks' => $ticks, 'notifications' => $notifications];
    }

    public function drain(): int
    {
        $count = 0;
        while (($batch = $this->market->receiveNotifications(50, 0)) !== []) {
            foreach ($batch as $n) {
                OfferChangeReceived::dispatch($n);
                $this->market->acknowledge($n);
                $count++;
            }
        }

        return $count;
    }
}
