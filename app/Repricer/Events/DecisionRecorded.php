<?php

namespace App\Repricer\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired after every price_decisions row commits (session 2: broadcast via Reverb). */
final class DecisionRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly int $decisionId,
        public readonly int $productId,
        public readonly string $outcome,
    ) {}
}
