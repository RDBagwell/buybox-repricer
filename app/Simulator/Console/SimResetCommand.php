<?php

namespace App\Simulator\Console;

use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\SimulationRunner;
use Illuminate\Console\Command;

class SimResetCommand extends Command
{
    protected $signature = 'sim:reset {--seed=42 : RNG seed for the new run} {--purge : Also drop undelivered notifications}';

    protected $description = 'Rebuild the simulated marketplace from the scenario (market time keeps moving forward).';

    public function handle(SimulationRunner $runner, RedisStreamNotifications $stream): int
    {
        if ($this->option('purge')) {
            $stream->purge();
        }

        $seed = (int) $this->option('seed');
        $world = $runner->reset($seed);

        $this->info(sprintf('Simulator reset: %d listings, seed %d, market time %s. Published one snapshot notification per listing.', count($world->listings), $seed, $world->clock->now()->format('Y-m-d H:i:s')));

        return self::SUCCESS;
    }
}
