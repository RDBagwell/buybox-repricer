<?php

namespace App\Repricer\Console;

use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;
use App\Repricer\Models\Product;
use App\Support\Money;
use Illuminate\Console\Command;

class TraceCommand extends Command
{
    protected $signature = 'repricer:trace {product : Product id, SKU or ASIN} {--limit=5 : Number of decisions}';

    protected $description = 'Print the latest pricing decisions for a product with their full rule traces.';

    public function handle(): int
    {
        $key = (string) $this->argument('product');
        $product = Product::query()
            ->when(ctype_digit($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->where('sku', $key)->orWhere('asin', $key))
            ->first();

        if ($product === null) {
            $this->error("No product matches [{$key}].");

            return self::FAILURE;
        }

        $this->info("{$product->title}  (SKU {$product->sku}, ASIN {$product->asin})  current price {$product->current_price}");

        $decisions = PriceDecision::query()->where('product_id', $product->id)->latest('id')->limit(max(1, (int) $this->option('limit')))->get();
        if ($decisions->isEmpty()) {
            $this->line('No decisions yet.');

            return self::SUCCESS;
        }

        foreach ($decisions as $d) {
            $this->newLine();
            $this->line(sprintf('<comment>#%d</comment> %s  event %s  <info>%s</info>  %s -> %s',
                $d->id, $d->decided_at->format('Y-m-d H:i:s'), $d->event_id, strtoupper($d->outcome), $d->old_price, $d->new_price ?? '—'));
            $this->line('  '.$d->reason);

            if ($d->rule_trace !== []) {
                $this->table(['rule', 'verdict', 'before', 'after', 'reason'], array_map(fn (array $t) => [
                    $t['rule'], $t['verdict'].($t['code'] !== null ? " ({$t['code']})" : ''),
                    Money::cents($t['price_before'])->format(), Money::cents($t['price_after'])->format(), $t['reason'],
                ], $d->rule_trace));
            }

            foreach ($d->pushes()->get() as $p) {
                /** @var PricePush $p */
                $this->line(sprintf('  push attempt %d: %s%s', $p->attempts, $p->status, $p->pushed_at !== null ? ' at '.$p->pushed_at->format('H:i:s') : ''));
            }
        }

        return self::SUCCESS;
    }
}
