<?php

namespace App\Repricer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired when a product's Buy Box winner changes (session 2: broadcast via Reverb). */
final class BuyBoxChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly bool $weWon,
    ) {}
}
