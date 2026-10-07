<?php

namespace App\Simulator\Console;

use App\Simulator\Persistence\WorldRepository;
use App\Simulator\SimulationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the world ticking for the live dashboard, at the speed set in the dashboard
 * (sim_state.speed). With --idle-after, it only ticks while a viewer has been seen recently
 * (the dashboard refreshes the heartbeat), so an unwatched demo costs nothing.
 */
class SimDaemonCommand extends Command
{
    public const HEARTBEAT_KEY = 'sim:viewer-heartbeat';

    protected $signature = 'sim:daemon
        {--max-speed=100 : Cap on the speed setting}
        {--idle-after=0 : Pause when no viewer heartbeat for this many seconds (0 = always run)}';

    protected $description = 'Run the simulation continuously at the speed configured in the dashboard.';

    private bool $stop = false;

    public function handle(SimulationRunner $runner, WorldRepository $worlds): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $tickSeconds = (int) config('simulator.tick_seconds');
        $maxSpeed = max(1, min(100, (int) $this->option('max-speed')));
        $idleAfter = (int) $this->option('idle-after');
        $this->info('Simulator daemon running.');

        while (! $this->stop) {
            if (! $worlds->exists()) {
                sleep(1);

                continue;
            }

            $controls = $worlds->controls();
            $watched = $idleAfter === 0 || (time() - (int) Cache::get(self::HEARTBEAT_KEY, 0)) <= $idleAfter;
            if (! $controls['running'] || ! $watched) {
                usleep(500_000);

                continue;
            }

            try {
                $runner->tick();
            } catch (\Throwable $e) {
                report($e); // e.g. a demo reset dropped the tables mid-tick; try again shortly
                sleep(1);

                continue;
            }

            $speed = max(1, min($maxSpeed, $controls['speed']));
            usleep(intdiv($tickSeconds * 1_000_000, $speed));
        }

        return self::SUCCESS;
    }
}
