<?php

namespace App\Repricer\Market;

use DateTimeImmutable;

final readonly class ItemOffers
{
    /**
     * @param  list<MarketOffer>  $offers
     */
    public function __construct(
        public string $asin,
        public array $offers,
        public ?string $buyBoxSellerId,
        public DateTimeImmutable $observedAt,
    ) {}
}
