<?php

namespace App\Repricer\Market;

use App\Support\Fulfillment;
use App\Support\Money;

final readonly class MarketOffer
{
    public function __construct(
        public string $sellerId,
        public Money $price,
        public Money $shipping,
        public Fulfillment $fulfillment,
        public int $rating,
        public int $handlingDays,
        public bool $isBuyBoxWinner,
    ) {}

    public function landed(): Money
    {
        return $this->price->plus($this->shipping);
    }

    /**
     * @param  array{seller_id: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int, is_buybox_winner: bool}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['seller_id'],
            Money::cents($row['price']),
            Money::cents($row['shipping']),
            Fulfillment::from($row['fulfillment']),
            $row['rating'],
            $row['handling_days'],
            $row['is_buybox_winner'],
        );
    }

    /**
     * @return array{seller_id: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int, is_buybox_winner: bool}
     */
    public function toArray(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'price' => $this->price->cents,
            'shipping' => $this->shipping->cents,
            'fulfillment' => $this->fulfillment->value,
            'rating' => $this->rating,
            'handling_days' => $this->handlingDays,
            'is_buybox_winner' => $this->isBuyBoxWinner,
        ];
    }
}
