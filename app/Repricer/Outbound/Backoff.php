<?php

namespace App\Repricer\Outbound;

use Random\Randomizer;

/**
 * Exponential backoff with "equal jitter": half the exponential delay is fixed, half random.
 * A server-provided Retry-After is a lower bound, never shortened by jitter.
 */
final readonly class Backoff
{
    public function __construct(
        private int $baseMs = 500,
        private int $capMs = 30_000,
        private Randomizer $random = new Randomizer,
    ) {}

    public function delayMs(int $attempt, ?int $retryAfterMs): int
    {
        $exp = min($this->capMs, $this->baseMs * (2 ** max(0, $attempt - 1)));
        $half = intdiv($exp, 2);
        $jittered = $half + $this->random->getInt(0, $exp - $half);

        return max($jittered, $retryAfterMs ?? 0);
    }
}
