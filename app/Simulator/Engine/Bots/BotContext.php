<?php

namespace App\Simulator\Engine\Bots;

use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SeededRng;
use App\Simulator\Engine\SimOffer;
use App\Support\Money;
use DateTimeImmutable;

final readonly class BotContext
{
    /**
     * @param  array<string, int|string>  $params
     * @param  array<string, int|string>  $memory
     */
    public function __construct(
        public Listing $listing,
        public SimOffer $self,
        public array $params,
        public array $memory,
        public int $tick,
        public DateTimeImmutable $now,
        public SeededRng $rng,
    ) {}

    public function intParam(string $key, int $default): int
    {
        return (int) ($this->params[$key] ?? $default);
    }

    /** Lowest landed price among the other offers, or null if alone. */
    public function lowestOtherLanded(): ?Money
    {
        $others = array_filter($this->listing->activeOffers(), fn (SimOffer $o) => $o->sellerId !== $this->self->sellerId);

        return $others === [] ? null : Money::min(...array_map(fn (SimOffer $o) => $o->landed(), array_values($others)));
    }
}
