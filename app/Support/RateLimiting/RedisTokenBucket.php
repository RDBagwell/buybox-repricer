<?php

namespace App\Support\RateLimiting;

use Illuminate\Redis\Connections\Connection;

/**
 * Atomic token bucket in Redis, implemented as GCRA (generic cell rate algorithm): one key per
 * bucket holding the "theoretical arrival time". Equivalent to a token bucket with capacity
 * $burst refilling one token every $refillMs, but needs a single integer and no timers.
 *
 * Time comes from the Redis server (TIME) so every worker shares one clock.
 */
final class RedisTokenBucket implements TokenBucket
{
    private const LUA = <<<'LUA'
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
if ARGV[3] ~= '' then now = tonumber(ARGV[3]) end
local burst = tonumber(ARGV[1])
local interval = tonumber(ARGV[2])
local tat = tonumber(redis.call('GET', KEYS[1]) or '0')
if tat < now then tat = now end
local new_tat = tat + interval
local allow_at = new_tat - burst * interval
if now < allow_at then
  return allow_at - now
end
redis.call('SET', KEYS[1], new_tat, 'PX', new_tat - now + 1000)
return 0
LUA;

    /**
     * @param  (\Closure(): int)|null  $clock  test hook returning "now" in ms; null = Redis server time
     */
    public function __construct(
        private readonly Connection $redis,
        private readonly string $prefix = 'ratelimit:',
        private readonly ?\Closure $clock = null,
    ) {}

    public function take(string $bucket, int $burst, int $refillMs): int
    {
        $now = $this->clock === null ? '' : (string) ($this->clock)();

        return (int) $this->redis->eval(self::LUA, 1, $this->prefix.$bucket, $burst, $refillMs, $now);
    }
}
