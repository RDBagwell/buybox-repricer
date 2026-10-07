<?php

namespace App\Repricer\Console;

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Pricing\CooldownSweeper;
use Illuminate\Console\Command;

/**
 * Consumes offer-change notifications from the adapter's delivery queue and fans them out as
 * events (-> DispatchRepricing -> RepriceJob). Acknowledges only after dispatching, so a crash
 * means redelivery (handled idempotently), never a lost event.
 */
class ListenCommand extends Command
{
    protected $signature = 'repricer:listen
        {--once : Process a single batch and exit}
        {--max-batches=0 : Stop after this many batches (0 = run until stopped)}';

    protected $description = 'Consume marketplace offer-change notifications and queue reprice jobs.';

    private bool $stop = false;

    public function handle(MarketAdapter $market, CooldownSweeper $sweeper): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        $batchSize = (int) config('repricer.listener.batch', 10);
        $waitMs = $this->option('once') ? 0 : (int) config('repricer.listener.wait_ms', 2000);
        $maxBatches = $this->option('once') ? 1 : (int) $this->option('max-batches');
        $batches = 0;
        $total = 0;

        $this->info('Listening for offer-change notifications…');

        while (! $this->stop) {
            $notifications = $market->receiveNotifications($batchSize, $waitMs);
            foreach ($notifications as $n) {
                OfferChangeReceived::dispatch($n);
                $market->acknowledge($n);
                $total++;
                $this->line(sprintf('%s %s trigger=%s buybox=%s', $n->eventTime->format('H:i:s'), $n->asin, $n->triggerSellerId, $n->buyBoxSellerId ?? '(none)'), verbosity: 'v');
            }

            // Re-check products whose cooldown expired while the market was quiet.
            $total += $sweeper->sweep();

            $batches++;
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }
        }

        $this->info("Dispatched {$total} notification(s) and cooldown re-check(s).");

        return self::SUCCESS;
    }
}
