<?php

namespace App\Demo\Console;

use App\Demo\Events\WorldReset;
use App\Simulator\Delivery\RedisStreamNotifications;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\MarketplaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the public demo from scratch: empties every repricer and simulator table, reseeds the
 * world and catalogue, and primes some history. Only runs in demo mode; it is how "nothing a
 * visitor does persists" is guaranteed.
 *
 * TRUNCATE (not migrate:fresh, which is blocked in production) empties the append-only audit
 * tables: their triggers guard UPDATE/DELETE by the application, not a deliberate wipe of the
 * whole demo. Users, sessions and the migrations table are untouched.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset {--force : Run even when DEMO_MODE is off}';

    protected $description = 'Rebuild the demo world from its seed (empties repricer and simulator tables). Demo mode only.';

    /** @var list<string> */
    public const TABLES = [
        'audit_log', 'duplicate_deliveries', 'price_pushes', 'offer_snapshots', 'price_decisions',
        'buybox_history', 'pricing_rules', 'products', 'settings',
        'sim_price_requests', 'sim_events', 'sim_offers', 'sim_listings', 'sim_state',
    ];

    public function handle(RedisStreamNotifications $stream): int
    {
        if (! config('demo.enabled') && ! $this->option('force')) {
            $this->error('demo:reset wipes the database and only runs in demo mode (DEMO_MODE=true).');

            return self::FAILURE;
        }

        $lock = Cache::lock('demo:reset', 600);
        if (! $lock->get()) {
            $this->warn('A reset is already running.');

            return self::SUCCESS;
        }

        try {
            $stream->purge();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');
            } else {
                foreach (self::TABLES as $table) {
                    DB::table($table)->truncate();
                }
            }
            // Catalogue first, so the marketplace's opening snapshots find our products.
            $this->callSilent('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
            $this->callSilent('db:seed', ['--class' => MarketplaceSeeder::class, '--force' => true]);
            $this->call('demo:prime');
            try {
                WorldReset::dispatch(); // open dashboards reload
            } catch (\Throwable $e) {
                report($e); // Reverb down: dashboards will catch up on reconnect
            }
            $this->info('Demo world reset.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
