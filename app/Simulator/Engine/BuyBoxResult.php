<?php

namespace App\Simulator\Engine;

final readonly class BuyBoxResult
{
    /**
     * @param  list<BuyBoxScore>  $scores
     */
    public function __construct(
        public ?string $winner,
        public array $scores,
        public string $reason,
    ) {}
}
