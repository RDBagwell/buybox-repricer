<?php

use Illuminate\Support\Facades\Schedule;

// Re-queue reprice decisions whose push never finished (worker died mid-job).
Schedule::command('repricer:resume-pushes')->everyMinute()->withoutOverlapping();

// Backstop for repricer:listen, which sweeps cooldowns after every batch.
Schedule::command('repricer:recheck-cooldowns')->everyMinute()->withoutOverlapping();

// Horizon's metrics graphs.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
