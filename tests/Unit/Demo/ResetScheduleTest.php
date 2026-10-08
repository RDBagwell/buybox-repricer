<?php

use App\Demo\ResetSchedule;

it('turns the scheduled reset off at zero', function () {
    expect(ResetSchedule::cron(0))->toBeNull()
        ->and(ResetSchedule::cron(-5))->toBeNull();
});

it('clamps the interval to 5 to 30 minutes', function (int $minutes, string $cron) {
    expect(ResetSchedule::cron($minutes))->toBe($cron);
})->with([
    [1, '*/5 * * * *'],
    [15, '*/15 * * * *'],
    [30, '*/30 * * * *'],
    // */59 would fire at :00 and :59, two resets a minute apart.
    [59, '*/30 * * * *'],
]);

it('reports the interval it actually uses', function () {
    expect(ResetSchedule::minutes(0))->toBeNull()
        ->and(ResetSchedule::minutes(45))->toBe(30)
        ->and(ResetSchedule::minutes(30))->toBe(30);
});
