<?php

namespace App\Demo\Console;

use App\Demo\HeadlessLoop;
use Illuminate\Console\Command;

class DemoPrimeCommand extends Command
{
    protected $signature = 'demo:prime {--ticks= : Ticks to run (default: demo.prime_ticks)}';

    protected $description = 'Run the simulator and repricer synchronously so the dashboard opens mid-price-war.';

    public function handle(HeadlessLoop $loop): int
    {
        config(['queue.default' => 'sync']); // decide and push inline: the history exists when this returns

        $ticks = (int) ($this->option('ticks') ?? config('demo.prime_ticks'));
        $stats = $loop->run($ticks);
        $this->info("Primed: {$stats['ticks']} ticks, {$stats['notifications']} notifications handled.");

        return self::SUCCESS;
    }
}
