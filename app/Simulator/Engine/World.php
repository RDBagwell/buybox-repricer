<?php

namespace App\Simulator\Engine;

/**
 * The whole simulated marketplace in memory: listings, market clock, RNG, tick counter.
 */
final class World
{
    /**
     * @param  array<string, Listing>  $listings  keyed by ASIN
     */
    public function __construct(
        public array $listings,
        public SimClock $clock,
        public SeededRng $rng,
        public int $tick = 0,
        public int $seed = 0,
    ) {
        ksort($this->listings, SORT_STRING);
    }

    public function listing(string $asin): ?Listing
    {
        return $this->listings[$asin] ?? null;
    }

    /**
     * Canonical, comparable snapshot (used by determinism tests and debugging).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $listings = [];
        foreach ($this->listings as $asin => $listing) {
            $listings[$asin] = [
                'buybox' => $listing->buyBoxSellerId,
                'offers' => array_map(fn (SimOffer $o) => $o->toArray() + ['memory' => $o->botMemory], array_values($listing->offers)),
            ];
        }

        return ['tick' => $this->tick, 'now_ms' => $this->clock->nowMs, 'rng' => $this->rng->export(), 'listings' => $listings];
    }
}
