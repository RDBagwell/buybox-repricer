<?php

namespace Database\Seeders;

use App\Simulator\Persistence\WorldRepository;
use App\Simulator\SimulationRunner;
use Illuminate\Database\Seeder;

/**
 * Builds the simulated marketplace from config/simulator.php (seed 42) if it does not exist yet.
 */
class MarketplaceSeeder extends Seeder
{
    public function run(WorldRepository $worlds, SimulationRunner $runner): void
    {
        if (! $worlds->exists()) {
            $runner->reset(42);
        }
    }
}
