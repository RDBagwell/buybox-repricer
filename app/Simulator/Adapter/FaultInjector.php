<?php

namespace App\Simulator\Adapter;

use Illuminate\Redis\Connections\Connection;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Seeded, reproducible injection of 429/503 responses.
 *
 * Each request draws a shared sequence number (Redis INCR, so all workers agree) and the
 * outcome is a pure function of (seed, sequence number): the same seed and request order
 * produce the same faults.
 */
final class FaultInjector
{
    public function __construct(
        private readonly Connection $redis,
        private readonly int $seed,
        private readonly int $http429Bps,
        private readonly int $http503Bps,
        private readonly int $retryAfterMs,
        private readonly string $counterKey = 'sim:faults:seq',
    ) {}

    /**
     * @return array{status: int, retry_after_ms: int}|null
     */
    public function roll(): ?array
    {
        if ($this->http429Bps <= 0 && $this->http503Bps <= 0) {
            return null;
        }

        $seq = (int) $this->redis->command('incr', [$this->counterKey]);
        $roll = (new Randomizer(new Xoshiro256StarStar($this->seed * 1_000_003 + $seq)))->getInt(1, 10_000);

        if ($roll <= $this->http429Bps) {
            return ['status' => 429, 'retry_after_ms' => $this->retryAfterMs];
        }
        if ($roll <= $this->http429Bps + $this->http503Bps) {
            return ['status' => 503, 'retry_after_ms' => $this->retryAfterMs];
        }

        return null;
    }
}
