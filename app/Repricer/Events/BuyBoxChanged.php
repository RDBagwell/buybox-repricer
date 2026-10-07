<?php

namespace App\Repricer\Events;

use App\Repricer\Events\Concerns\BroadcastsToDashboard;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when a product's Buy Box winner changes. */
final class BuyBoxChanged implements ShouldBroadcast
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly bool $weWon,
    ) {}

    public function broadcastAs(): string
    {
        return 'buybox.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['product_id' => $this->productId, 'from' => $this->from, 'to' => $this->to, 'we_won' => $this->weWon];
    }
}
