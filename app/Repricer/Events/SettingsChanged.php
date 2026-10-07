<?php

namespace App\Repricer\Events;

use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Events\Concerns\BroadcastsToDashboard;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** The kill switch or dry-run mode changed. */
final class SettingsChanged implements ShouldBroadcast
{
    use BroadcastsToDashboard, Dispatchable;

    public function broadcastAs(): string
    {
        return 'settings.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return app(DashboardQuery::class)->settings();
    }
}
