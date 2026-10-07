<?php

namespace App\Repricer\Safety;

use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;

/** Session-1 placeholder: always closed. */
final class NeverTrips implements CircuitBreaker
{
    public function openReason(Product $product): ?string
    {
        return null;
    }

    public function record(Product $product, PushStatus $status): void {}
}
