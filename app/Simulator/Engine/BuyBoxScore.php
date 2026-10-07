<?php

namespace App\Simulator\Engine;

use App\Support\Money;

final readonly class BuyBoxScore
{
    public function __construct(
        public string $sellerId,
        public Money $landed,
        public ?Money $effective,   // null when disqualified
        public string $note,
    ) {}
}
