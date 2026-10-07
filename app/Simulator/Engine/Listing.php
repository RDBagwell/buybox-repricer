<?php

namespace App\Simulator\Engine;

final class Listing
{
    /**
     * @param  array<string, SimOffer>  $offers  keyed by seller id
     */
    public function __construct(
        public readonly string $asin,
        public readonly string $title,
        public array $offers,
        public ?string $buyBoxSellerId = null,
    ) {
        ksort($this->offers, SORT_STRING);
    }

    public function offer(string $sellerId): ?SimOffer
    {
        return $this->offers[$sellerId] ?? null;
    }

    public function put(SimOffer $offer): void
    {
        $this->offers[$offer->sellerId] = $offer;
        ksort($this->offers, SORT_STRING);
    }

    /**
     * Offers currently for sale (in stock). Only these compete for the Buy Box or appear in
     * notifications.
     *
     * @return array<string, SimOffer>
     */
    public function activeOffers(): array
    {
        return array_filter($this->offers, fn (SimOffer $o) => $o->inStock);
    }

    public function buyBoxOffer(): ?SimOffer
    {
        return $this->buyBoxSellerId === null ? null : $this->offer($this->buyBoxSellerId);
    }
}
