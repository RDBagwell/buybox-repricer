<?php

namespace App\Repricer\Rules;

use App\Support\Money;
use DateTimeImmutable;

/**
 * Immutable snapshot of everything one pricing decision needs. "Now" is passed in;
 * nothing in the Rules namespace reads a clock.
 */
final readonly class PricingContext
{
    /**
     * @param  list<Offer>  $offers  every offer on the listing, ours included (flagged isOurs)
     */
    public function __construct(
        public string $sku,
        public Money $cost,
        public Money $fees,
        public Money $currentPrice,
        public Money $ourShipping,
        public RuleConfig $config,
        public array $offers,
        public bool $weHoldBuyBox,
        public ?DateTimeImmutable $lastChangedAt,
        public DateTimeImmutable $now,
        public Flags $flags,
    ) {}

    /**
     * @return list<Offer>
     */
    public function competitors(): array
    {
        return array_values(array_filter($this->offers, fn (Offer $o) => ! $o->isOurs));
    }

    /** cost + fees + minimum margin: the lowest price that still makes our margin. */
    public function marginFloor(): Money
    {
        return $this->cost->plus($this->fees)->plus($this->config->minMargin);
    }

    /** The binding lower bound: the higher of the hard floor and the margin floor. */
    public function effectiveFloor(): Money
    {
        return Money::max($this->config->floor, $this->marginFloor());
    }

    /** Listing price that lands us at the given landed price. */
    public function priceForLanded(Money $landed): Money
    {
        return Money::max($landed->minus($this->ourShipping), Money::cents(1));
    }
}
