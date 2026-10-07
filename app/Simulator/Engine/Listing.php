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

    public function buyBoxOffer(): ?SimOffer
    {
        return $this->buyBoxSellerId === null ? null : $this->offer($this->buyBoxSellerId);
    }
}
