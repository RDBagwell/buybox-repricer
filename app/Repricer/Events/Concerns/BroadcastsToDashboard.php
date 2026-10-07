<?php

namespace App\Repricer\Events\Concerns;

use App\Repricer\Dashboard\DashboardChannel;
use Illuminate\Broadcasting\Channel;

/**
 * Shared broadcast plumbing: one dashboard channel, a dedicated queue (a Reverb outage can never
 * fail a reprice or push job), and payloads built only by the dashboard presenters.
 */
trait BroadcastsToDashboard
{
    public function broadcastOn(): Channel
    {
        return DashboardChannel::channel();
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
