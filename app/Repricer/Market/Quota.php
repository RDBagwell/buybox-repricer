<?php

namespace App\Repricer\Market;

/**
 * Token-bucket request quota: up to $burst requests at once, refilling one token every $refillMs.
 * (Integers on purpose: 0.5 req/s is refillMs = 2000.)
 */
final readonly class Quota
{
    public function __construct(
        public int $burst,
        public int $refillMs,
    ) {
        if ($burst < 1 || $refillMs < 1) {
            throw new \InvalidArgumentException('Quota burst and refill interval must be positive.');
        }
    }
}
