<?php

namespace App\Support\RateLimiting;

interface TokenBucket
{
    /**
     * Try to take one token from the named bucket (burst capacity, one token per $refillMs).
     * Returns 0 when a token was taken, otherwise the milliseconds to wait before one is free.
     */
    public function take(string $bucket, int $burst, int $refillMs): int;
}
