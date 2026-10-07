<?php

namespace App\Simulator\Console;

use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\SimClock;
use App\Simulator\Persistence\WorldRepository;
use App\Simulator\SimulationRunner;
use Illuminate\Console\Command;

class SimRunCommand extends Command
{
    protected $signature = 'sim:run
        {--ticks=500 : Number of ticks to run}
        {--seed= : Start a fresh run from the scenario with this seed (omit to continue the current world)}
        {--speed= : 1x–100x; real time per tick = tick_seconds / speed}
        {--fast : Do not sleep between ticks (headless max speed)}
        {--moves : Also print every competitor price move}';

    protected $description = 'Run the marketplace simulation and print Buy Box changes.';

    public function handle(SimulationRunner $runner, WorldRepository $worlds, BuyBoxScorer $scorer): int
    {
        $ticks = max(1, (int) $this->option('ticks'));
        $speed = (int) ($this->option('speed') ?? config('simulator.speed'));
        if ($speed < 1 || $speed > 100) {
            $this->error('--speed must be between 1 and 100.');

            return self::INVALID;
        }

        $seed = $this->option('seed');
        if ($seed !== null || ! $worlds->exists()) {
            $this->call('sim:reset', ['--seed' => (int) ($seed ?? 42)]);
        }

        $tickSeconds = (int) config('simulator.tick_seconds');
        $sleepUs = $this->option('fast') ? 0 : intdiv($tickSeconds * 1_000_000, $speed);
        $this->line(sprintf('Running %d ticks of %ds market time at %dx%s.', $ticks, $tickSeconds, $speed, $sleepUs === 0 ? ' (no sleep)' : ''));

        $bbChanges = 0;
        $events = 0;
        $moves = 0;
        for ($i = 0; $i < $ticks; $i++) {
            $result = $runner->tick();
            $events += count($result->events);
            $moves += count($result->moves);
            $at = SimClock::fromMs($result->marketTimeMs)->format('H:i:s');

            if ($this->option('moves')) {
                foreach ($result->moves as $m) {
                    $this->line(sprintf('  [t%04d %s] %s %-12s %s -> %s  (%s)', $result->tick, $at, $m['asin'], $m['seller'], number_format($m['from'] / 100, 2), number_format($m['to'] / 100, 2), $m['reason']));
                }
            }

            foreach ($result->buyBoxChanges as $c) {
                $bbChanges++;
                $this->line(sprintf('[t%04d %s] %s Buy Box: %s -> <info>%s</info> at %s landed (%s)',
                    $result->tick, $at, $c->asin, $c->from ?? '(none)', $c->to ?? '(suppressed)',
                    $c->winningLanded === null ? '-' : number_format($c->winningLanded / 100, 2), $c->reason));
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->newLine();
        $this->info(sprintf('Done: %d ticks, %d competitor moves, %d offer-change events published, %d Buy Box changes.', $ticks, $moves, $events, $bbChanges));
        $this->table(['ASIN', 'Buy Box', 'Offers (seller price+ship)'], array_map(fn ($l) => [
            $l->asin,
            $l->buyBoxSellerId ?? '(none)',
            implode('  ', array_map(fn ($o) => "{$o->sellerId} {$o->price}+{$o->shipping}", array_values($l->offers))),
        ], array_values($worlds->load()->listings)));

        return self::SUCCESS;
    }
}
