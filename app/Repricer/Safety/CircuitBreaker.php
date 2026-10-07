<?php

namespace App\Repricer\Safety;

use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;

/**
 * Seam for the session-2 circuit breaker. Consulted before every outbound push and told the
 * outcome of every attempt. Windows must be measured in market time (MarketAdapter::now()).
 */
interface CircuitBreaker
{
    /** Null when pushing is allowed; otherwise the reason the breaker is open. */
    public function openReason(Product $product): ?string;

    public function record(Product $product, PushStatus $status): void;
}
