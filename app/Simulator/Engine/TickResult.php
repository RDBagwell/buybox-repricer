<?php

namespace App\Simulator\Engine;

final readonly class TickResult
{
    /**
     * @param  list<AnyOfferChanged>  $events
     * @param  list<BuyBoxChange>  $buyBoxChanges
     * @param  list<array{asin: string, seller: string, from: int, to: int, reason: string}>  $moves
     * @param  list<string>  $changedAsins
     */
    public function __construct(
        public int $tick,
        public int $marketTimeMs,
        public array $events,
        public array $buyBoxChanges,
        public array $moves,
        public array $changedAsins,
    ) {}
}
