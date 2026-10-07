<?php

use Illuminate\Support\Facades\Schedule;

// Re-queue reprice decisions whose push never finished (worker died mid-job).
Schedule::command('repricer:resume-pushes')->everyMinute()->withoutOverlapping();

// Backstop for repricer:listen, which sweeps cooldowns after every batch.
Schedule::command('repricer:recheck-cooldowns')->everyMinute()->withoutOverlapping();

// Horizon's metrics graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Public demo: rebuild the shared world from its seed, so nothing a visitor does persists.
if (config('demo.enabled')) {
    $minutes = max(5, min(59, (int) config('demo.reset_minutes')));
    Schedule::command('demo:reset')->cron("*/{$minutes} * * * *")->withoutOverlapping(15);
}
