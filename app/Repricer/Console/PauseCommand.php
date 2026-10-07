<?php

namespace App\Repricer\Console;

use App\Repricer\Models\Product;
use Illuminate\Console\Command;

class PauseCommand extends Command
{
    protected $signature = 'repricer:pause {sku} {--resume : Un-pause instead}';

    protected $description = 'Pause (or resume) repricing for one product.';

    public function handle(): int
    {
        $product = Product::query()->where('sku', $this->argument('sku'))->first();
        if ($product === null) {
            $this->error('Unknown SKU.');

            return self::FAILURE;
        }

        $product->forceFill(['paused' => ! $this->option('resume')])->save();
        $this->info("{$product->sku}: ".($product->paused ? 'paused' : 'active'));

        return self::SUCCESS;
    }
}
