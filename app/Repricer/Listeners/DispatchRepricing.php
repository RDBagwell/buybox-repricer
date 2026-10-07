<?php

namespace App\Repricer\Listeners;

use App\Repricer\Events\OfferChangeReceived;
use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Models\Product;

/**
 * One RepriceJob per (product listed on the ASIN, event). The queue between the notification
 * and the decision absorbs bursts; the job itself is idempotent.
 */
final class DispatchRepricing
{
    public function handle(OfferChangeReceived $event): void
    {
        $n = $event->notification;
        $payload = $n->toArray();

        foreach (Product::query()->where('asin', $n->asin)->pluck('id') as $productId) {
            RepriceJob::dispatch((int) $productId, $payload);
        }
    }
}
