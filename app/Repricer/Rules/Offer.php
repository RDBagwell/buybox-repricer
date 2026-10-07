<?php

namespace App\Repricer\Rules;

use App\Support\Fulfillment;
use App\Support\Money;

/**
 * One offer on the listing as seen at decision time.
 */
final readonly class Offer
{
    /**
     * @param  int  $rating  seller positive-feedback percentage, 0–100
     */
    public function __construct(
        public string $sellerId,
        public Money $price,
        public Money $shipping,
        public Fulfillment $fulfillment,
        public int $rating,
        public int $handlingDays,
        public bool $isBuyBoxWinner,
        public bool $isOurs = false,
    ) {}

    public function landed(): Money
    {
        return $this->price->plus($this->shipping);
    }

    public function describe(): string
    {
        return "{$this->sellerId} @ {$this->landed()} landed";
    }

    /**
     * @return array{seller: string, price: int, shipping: int, landed: int, fulfillment: string, rating: int, handling_days: int, is_buybox: bool, is_ours: bool}
     */
    public function toArray(): array
    {
        return [
            'seller' => $this->sellerId,
            'price' => $this->price->cents,
            'shipping' => $this->shipping->cents,
            'landed' => $this->landed()->cents,
            'fulfillment' => $this->fulfillment->value,
            'rating' => $this->rating,
            'handling_days' => $this->handlingDays,
            'is_buybox' => $this->isBuyBoxWinner,
            'is_ours' => $this->isOurs,
        ];
    }
}
