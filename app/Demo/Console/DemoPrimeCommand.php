<?php

namespace App\Demo\Console;

use App\Demo\HeadlessLoop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoPrimeCommand extends Command
{
    protected $signature = 'demo:prime
        {--ticks= : Ticks to run (default: demo.prime_ticks)}
        {--if-fresh : Only prime a world that has not ticked yet (safe to run on every boot)}';

    protected $description = 'Run the simulator and repricer synchronously so the dashboard opens mid-price-war.';

    public function handle(HeadlessLoop $loop): int
    {
        if ($this->option('if-fresh') && (int) DB::table('sim_state')->value('tick') > 0) {
            $this->info('World already running; not primed.');

            return self::SUCCESS;
        }

        // Decide and push inline so the history exists when this returns. Nobody is watching
        // a prime, so nothing is broadcast (and a stopped Reverb cannot break it).
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);

        $ticks = (int) ($this->option('ticks') ?? config('demo.prime_ticks'));
        $stats = $loop->run($ticks);
        $this->info("Primed: {$stats['ticks']} ticks, {$stats['notifications']} notifications handled.");

        return self::SUCCESS;
    }
}
