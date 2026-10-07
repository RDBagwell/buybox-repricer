<?php

namespace App\Repricer\Events;

use App\Repricer\Dashboard\DecisionPresenter;
use App\Repricer\Events\Concerns\BroadcastsToDashboard;
use App\Repricer\Models\PriceDecision;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after every price_decisions row commits. */
final class DecisionRecorded implements ShouldBroadcast
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(
        public readonly int $decisionId,
        public readonly int $productId,
        public readonly string $outcome,
    ) {}

    public function broadcastAs(): string
    {
        return 'decision.recorded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $d = PriceDecision::query()->find($this->decisionId);

        return $d === null ? ['id' => $this->decisionId, 'missing' => true] : DecisionPresenter::present($d);
    }
}
