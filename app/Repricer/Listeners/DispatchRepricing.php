<?php

namespace App\Repricer\Listeners;

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Models\Product;

/**
 * One RepriceJob per (active product listed on the ASIN, event); archived products are ignored. The queue between the notification
 * and the decision absorbs bursts; the job itself is idempotent.
 */
final class DispatchRepricing
{
    public function handle(OfferChangeReceived $event): void
    {
        $n = $event->notification;
        $payload = $n->toArray();

        foreach (Product::query()->active()->where('asin', $n->asin)->pluck('id') as $productId) {
            RepriceJob::dispatch((int) $productId, $payload);
        }
    }
}
