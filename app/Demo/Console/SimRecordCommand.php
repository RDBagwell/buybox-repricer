<?php

namespace App\Demo\Console;

use App\Demo\HeadlessLoop;
use App\Demo\SimulatorPanel;
use App\Repricer\Dashboard\DashboardQuery;
use App\Repricer\Events\BuyBoxChanged;
use App\Repricer\Events\DecisionRecorded;
use App\Repricer\Events\PricePushed;
use App\Repricer\Events\ProductUpdated;
use App\Repricer\Events\SettingsChanged;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\Product;
use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\TickResult;
use App\Simulator\SimulationRunner;
use Database\Seeders\CatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records a real, seeded simulation run (simulator + repricer, in-process) as JSON for the
 * dashboard's Replay mode: the initial dashboard state and chart series, then, tick by tick,
 * exactly the payloads the server would have broadcast.
 *
 * Runs inside a database transaction that is rolled back, on its own notification stream, so the
 * database is left as it was. It takes table locks while it runs: stop the live processes first.
 */
class SimRecordCommand extends Command
{
    protected $signature = 'sim:record
        {--seed= : Seed (default: simulator.seed)}
        {--prime=48 : Ticks run before recording starts (history already on screen)}
        {--ticks=360 : Ticks to record}
        {--out=public/recordings/demo.json : Output file}';

    protected $description = 'Record a seeded simulation run to JSON for the dashboard Replay mode (database is rolled back).';

    public function handle(): int
    {
        $seed = (int) ($this->option('seed') ?? config('simulator.seed', 42));
        $prime = max(0, (int) $this->option('prime'));
        $ticks = max(1, (int) $this->option('ticks'));

        // Isolate the run: inline queue, nothing sent to Reverb, a private notification stream.
        config([
            'queue.default' => 'sync',
            'broadcasting.default' => 'null',
            'simulator.notifications.stream' => 'market:notifications:record:'.getmypid(),
        ]);
        foreach ([RedisStreamNotifications::class, NotificationPublisher::class, MarketAdapter::class] as $abstract) {
            app()->forgetInstance($abstract);
        }

        $frame = [];
        Event::listen([DecisionRecorded::class, PricePushed::class, BuyBoxChanged::class, ProductUpdated::class, SettingsChanged::class], function (ShouldBroadcast $e) use (&$frame): void {
            /** @var DecisionRecorded|PricePushed|BuyBoxChanged|ProductUpdated|SettingsChanged $e */
            $frame[] = ['type' => $e->broadcastAs(), 'payload' => $e->broadcastWith()];
        });

        DB::beginTransaction();
        try {
            DB::statement('TRUNCATE '.implode(', ', DemoResetCommand::TABLES).' RESTART IDENTITY CASCADE');
            $this->callSilent('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
            app(SimulationRunner::class)->reset($seed);

            $loop = app(HeadlessLoop::class);
            $loop->run($prime);
            $frame = []; // the primed history is part of the initial state, not the frames

            $query = app(DashboardQuery::class);
            $initial = $query->state(150) + ['simulator' => app(SimulatorPanel::class)->state()];
            $series = [];
            foreach (Product::query()->with('rule')->get() as $p) {
                $series[$p->id] = $query->series($p, 3);
            }

            $frames = [];
            $loop->run($ticks, function (TickResult $r) use (&$frames, &$frame): void {
                $frames[] = ['t' => $r->marketTimeMs, 'events' => $frame];
                $frame = [];
            });
        } finally {
            DB::rollBack();
            app(RedisStreamNotifications::class)->purge();
        }

        $recording = [
            'version' => 1,
            'meta' => [
                'seed' => $seed,
                'prime_ticks' => $prime,
                'ticks' => $ticks,
                'tick_seconds' => (int) config('simulator.tick_seconds'),
                'our_seller_id' => (string) config('market.seller_id'),
                'command' => "php artisan sim:record --seed={$seed} --prime={$prime} --ticks={$ticks}",
                'recorded_at' => now()->toIso8601String(),
            ],
            'initial' => $initial,
            'series' => $series,
            'frames' => $frames,
        ];

        $out = base_path((string) $this->option('out'));
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, json_encode($recording, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $events = array_sum(array_map(fn ($f) => count($f['events']), $frames));
        $this->info(sprintf('Recorded %d ticks (%d events, %d decisions in the initial state) to %s (%s KB).', count($frames), $events, count($initial['decisions']), $this->option('out'), number_format(filesize($out) / 1024)));

        return self::SUCCESS;
    }
}
