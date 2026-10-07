<?php

namespace App\Repricer\Jobs;

use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\Product;
use App\Repricer\Pricing\CooldownSweeper;
use App\Repricer\Pricing\RepricingService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Decide a new price for one product in response to one offer-change event.
 *
 * Idempotent: (product_id, event_id) is unique in price_decisions, so a redelivered or retried
 * job records a duplicate and never reprices twice. Serialised per product by ProductLock.
 */
final class RepriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    /**
     * @param  array<string, mixed>  $notification  OfferChangeNotification::toArray()
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $notification,
    ) {
        $this->onQueue('reprice');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [ProductLock::for(ProductLock::REPRICE, $this->productId)];
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(10)->toDateTime();
    }

    public function handle(RepricingService $service): void
    {
        $product = Product::query()->with('rule')->find($this->productId);
        if ($product === null) {
            return;
        }

        $notification = OfferChangeNotification::fromArray($this->notification);
        if (! CooldownSweeper::stillCurrent($product, $notification)) {
            return; // superseded re-check: a newer decision already covers it
        }

        $service->handle($product, $notification);
    }
}
