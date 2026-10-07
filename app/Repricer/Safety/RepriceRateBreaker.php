<?php

namespace App\Repricer\Safety;

use App\Repricer\Events\ProductUpdated;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Models\PricePush;
use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;
use Illuminate\Support\Facades\DB;

/**
 * Trips when a product has already been repriced $maxPerHour times within the last MARKET hour:
 * it pauses the product with a reason, writes an audit row, and blocks the push. It never
 * resumes on its own; an operator must resume the product explicitly.
 */
final class RepriceRateBreaker implements CircuitBreaker
{
    public function __construct(
        private readonly MarketAdapter $market,
        private readonly AuditLog $audit,
        private readonly int $maxPerHour,
    ) {}

    public function openReason(Product $product): ?string
    {
        if ($product->paused) {
            return $product->paused_reason ?? 'Product is paused.';
        }

        $now = $this->market->now();
        $since = $now->modify('-1 hour');
        $recent = PricePush::query()
            ->join('price_decisions', 'price_decisions.id', '=', 'price_pushes.decision_id')
            ->where('price_decisions.product_id', $product->id)
            ->where('price_pushes.status', PushStatus::Succeeded->value)
            ->where('price_pushes.pushed_at', '>', $since)
            ->count();

        if ($recent < $this->maxPerHour) {
            return null;
        }

        $reason = "Circuit breaker: {$recent} reprices in the last market hour (limit {$this->maxPerHour}). Review the rules and resume manually.";

        DB::transaction(function () use ($product, $reason, $now, $recent) {
            $product->forceFill(['paused' => true, 'paused_reason' => $reason, 'paused_at' => $now])->save();
            $this->audit->record('breaker.tripped', AuditLog::SYSTEM, $product->id, ['paused' => false], ['paused' => true, 'reprices_last_hour' => $recent, 'limit' => $this->maxPerHour], $reason);
        });

        ProductUpdated::dispatch($product->id);

        return $reason;
    }

    public function record(Product $product, PushStatus $status): void
    {
        // Counting is done from price_pushes in openReason(); nothing to keep in memory.
    }

    public function limit(): int
    {
        return $this->maxPerHour;
    }
}
