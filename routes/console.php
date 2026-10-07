<?php

use App\Demo\ResetSchedule;
use Illuminate\Support\Facades\Schedule;

// Re-queue reprice decisions whose push never finished (worker died mid-job).
Schedule::command('repricer:resume-pushes')->everyMinute()->withoutOverlapping();

// Backstop for repricer:listen, which sweeps cooldowns after every batch.
Schedule::command('repricer:recheck-cooldowns')->everyMinute()->withoutOverlapping();

// Horizon's metrics graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Public demo: rebuild the shared world from its seed, so nothing a visitor does persists.
// DEMO_RESET_MINUTES=0 turns the scheduled reset off (see App\Demo\ResetSchedule).
$resetCron = ResetSchedule::cron((int) config('demo.reset_minutes'));
if (config('demo.enabled') && $resetCron !== null) {
    Schedule::command('demo:reset')->cron($resetCron)->withoutOverlapping(15);
}
