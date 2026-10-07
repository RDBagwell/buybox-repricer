<?php

namespace App\Simulator\Engine;

use App\Support\Fulfillment;
use App\Support\Money;

final readonly class SimOffer
{
    /**
     * @param  array<string, int|string>  $botParams  static strategy parameters
     * @param  array<string, int|string>  $botMemory  state the bot carries between ticks
     * @param  bool  $inStock  out-of-stock offers are invisible: no Buy Box, not in notifications
     */
    public function __construct(
        public string $sellerId,
        public Money $price,
        public Money $shipping,
        public Fulfillment $fulfillment,
        public int $rating,
        public int $handlingDays,
        public ?string $sku = null,
        public ?string $bot = null,
        public array $botParams = [],
        public array $botMemory = [],
        public bool $inStock = true,
    ) {}

    public function landed(): Money
    {
        return $this->price->plus($this->shipping);
    }

    public function withPrice(Money $price): self
    {
        return new self($this->sellerId, $price, $this->shipping, $this->fulfillment, $this->rating, $this->handlingDays, $this->sku, $this->bot, $this->botParams, $this->botMemory, $this->inStock);
    }

    /**
     * @param  array<string, int|string>  $memory
     */
    public function withMemory(array $memory): self
    {
        return new self($this->sellerId, $this->price, $this->shipping, $this->fulfillment, $this->rating, $this->handlingDays, $this->sku, $this->bot, $this->botParams, $memory, $this->inStock);
    }

    public function withStock(bool $inStock): self
    {
        return new self($this->sellerId, $this->price, $this->shipping, $this->fulfillment, $this->rating, $this->handlingDays, $this->sku, $this->bot, $this->botParams, $this->botMemory, $inStock);
    }

    /**
     * @return array{seller: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int}
     */
    public function toArray(): array
    {
        return [
            'seller' => $this->sellerId,
            'price' => $this->price->cents,
            'shipping' => $this->shipping->cents,
            'fulfillment' => $this->fulfillment->value,
            'rating' => $this->rating,
            'handling_days' => $this->handlingDays,
        ];
    }
}
