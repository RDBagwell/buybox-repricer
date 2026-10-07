<?php

namespace App\Demo;

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Outbound\PushStatus;
use App\Repricer\Pricing\CooldownSweeper;
use App\Repricer\Pricing\DecisionStatus;
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
            $this->retryPendingPushes();
            if ($afterTick !== null) {
                $afterTick($result);
            }
        }
        $this->settlePushes();

        return ['ticks' => $ticks, 'notifications' => $notifications];
    }

    /**
     * Run once more every reprice whose push has not finished. On the sync queue a push that
     * must wait (local rate limit, 429/503 backoff) is released and dropped; a real worker would
     * retry it later, so this loop does the same on the next tick instead. dispatchSync runs the
     * job's middleware, so the per-product push lock is honoured even with live workers running.
     */
    public function retryPendingPushes(): int
    {
        $pending = PriceDecision::query()
            ->where('outcome', DecisionStatus::Reprice->value)
            ->whereDoesntHave('pushes', fn ($q) => $q->whereIn('status', PushStatus::terminalValues()))
            ->orderBy('id')->pluck('id');

        foreach ($pending as $id) {
            PushPriceJob::dispatchSync((int) $id);
        }

        return $pending->count();
    }

    /** After the last tick: retry until nothing is pending (rate-limit tokens refill in wall time). */
    public function settlePushes(int $maxWaitMs = 10_000): void
    {
        $deadline = microtime(true) + $maxWaitMs / 1000;
        while ($this->retryPendingPushes() > 0 && microtime(true) < $deadline) {
            usleep(250_000);
        }
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
