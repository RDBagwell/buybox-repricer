<?php

namespace App\Repricer\Events;

use App\Repricer\Events\Concerns\BroadcastsToDashboard;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after every recorded push attempt. */
final class PricePushed implements ShouldBroadcast
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(
        public readonly int $decisionId,
        public readonly int $productId,
        public readonly string $status,
        public readonly int $attempt,
    ) {}

    public function broadcastAs(): string
    {
        return 'push.recorded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'decision_id' => $this->decisionId,
            'product_id' => $this->productId,
            'status' => $this->status,
            'attempts' => $this->attempt,
        ];
    }
}
