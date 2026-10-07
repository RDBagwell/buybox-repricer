<?php

namespace App\Repricer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired after every recorded push attempt. */
final class PricePushed
{
    use Dispatchable;

    public function __construct(
        public readonly int $decisionId,
        public readonly int $productId,
        public readonly string $status,
        public readonly int $attempt,
    ) {}
}
