<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

final readonly class BotAction
{
    /**
     * @param  array<string, int|string>|null  $memory  replacement memory to persist, null = unchanged
     */
    private function __construct(
        public ?Money $newPrice,
        public string $reason,
        public ?array $memory = null,
    ) {}

    /**
     * @param  array<string, int|string>|null  $memory
     */
    public static function hold(string $reason, ?array $memory = null): self
    {
        return new self(null, $reason, $memory);
    }

    /**
     * @param  array<string, int|string>|null  $memory
     */
    public static function setPrice(Money $price, string $reason, ?array $memory = null): self
    {
        return new self($price, $reason, $memory);
    }
}
