<?php

namespace App\Demo;

/**
 * When the public demo rebuilds its world. DEMO_RESET_MINUTES=0 turns the scheduled reset off
 * (the boot reset still runs), for example during a presentation; otherwise the interval is
 * clamped to 5–30 minutes, so the cron fires at evenly spaced minutes past every hour.
 */
final class ResetSchedule
{
    /** The interval actually used, or null when the scheduled reset is off. */
    public static function minutes(int $configured): ?int
    {
        return $configured <= 0 ? null : max(5, min(30, $configured));
    }

    public static function cron(int $configured): ?string
    {
        $minutes = self::minutes($configured);

        return $minutes === null ? null : "*/{$minutes} * * * *";
    }
}
