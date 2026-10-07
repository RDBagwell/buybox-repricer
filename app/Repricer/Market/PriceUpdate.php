<?php

namespace App\Repricer\Market;

use App\Support\Money;

final readonly class PriceUpdate
{
    public function __construct(
        public string $sku,
        public Money $price,
        public string $idempotencyKey,
    ) {}
}
