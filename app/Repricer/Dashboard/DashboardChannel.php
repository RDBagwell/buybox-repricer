<?php

namespace App\Repricer\Dashboard;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The one broadcast channel the dashboard listens on.
 *
 * Demo mode: a public channel (anonymous visitors cannot authenticate a private one, and every
 * payload is presenter-shaped, public-safe data). Operator mode: a private channel authorised in
 * routes/channels.php for logged-in users only.
 */
final class DashboardChannel
{
    public const NAME = 'dashboard';

    public static function channel(): Channel
    {
        return config('demo.enabled') ? new Channel(self::NAME) : new PrivateChannel(self::NAME);
    }

    public static function isPrivate(): bool
    {
        return ! config('demo.enabled');
    }
}
