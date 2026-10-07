<?php

namespace App\Demo\Events;

use App\Repricer\Dashboard\DashboardChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** The demo world was rebuilt: dashboards reload their state. */
final class WorldReset implements ShouldBroadcastNow
{
    use Dispatchable;

    public function broadcastOn(): Channel
    {
        return DashboardChannel::channel();
    }

    public function broadcastAs(): string
    {
        return 'world.reset';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [];
    }
}
