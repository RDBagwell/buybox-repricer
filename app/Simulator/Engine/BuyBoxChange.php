<?php

namespace App\Simulator\Engine;

final readonly class BuyBoxChange
{
    public function __construct(
        public string $asin,
        public int $tick,
        public int $marketTimeMs,
        public ?string $from,
        public ?string $to,
        public ?int $winningLanded,
        public string $reason,
    ) {}
}
