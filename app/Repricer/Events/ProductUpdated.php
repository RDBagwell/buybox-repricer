<?php

namespace App\Repricer\Events;

use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Events\Concerns\BroadcastsToDashboard;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** A product's state changed: price, pause/resume, circuit breaker, or rule edit. */
final class ProductUpdated implements ShouldBroadcast
{
    use BroadcastsToDashboard, Dispatchable;

    public function __construct(public readonly int $productId) {}

    public function broadcastAs(): string
    {
        return 'product.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return app(DashboardQuery::class)->product($this->productId) ?? ['id' => $this->productId, 'missing' => true];
    }
}
