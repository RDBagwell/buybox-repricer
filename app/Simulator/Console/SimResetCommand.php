<?php

namespace App\Simulator\Console;

use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Persistence\WorldRepository;
use Illuminate\Console\Command;

class SimResetCommand extends Command
{
    protected $signature = 'sim:reset {--seed=42 : RNG seed for the new run} {--purge : Also drop undelivered notifications}';

    protected $description = 'Rebuild the simulated marketplace from the scenario (market time keeps moving forward).';

    public function handle(WorldRepository $worlds, BuyBoxScorer $scorer, RedisStreamNotifications $stream): int
    {
        $seed = (int) $this->option('seed');
        $world = $worlds->reset(
            (array) config('simulator.scenario'), // @phpstan-ignore argument.type
            $seed,
            (string) config('market.seller_id'),
            (string) config('simulator.epoch'),
            (int) config('simulator.tick_seconds'),
            $scorer,
        );

        if ($this->option('purge')) {
            $stream->purge();
        }

        $this->info(sprintf('Simulator reset: %d listings, seed %d, market time %s.', count($world->listings), $seed, $world->clock->now()->format('Y-m-d H:i:s')));

        return self::SUCCESS;
    }
}
