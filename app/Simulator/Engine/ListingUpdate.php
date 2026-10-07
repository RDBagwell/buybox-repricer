<?php

namespace App\Simulator\Engine;

final readonly class ListingUpdate
{
    public function __construct(
        public AnyOfferChanged $event,
        public ?BuyBoxChange $buyBoxChange,
    ) {}
}
