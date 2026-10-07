<?php

namespace App\Repricer\Rules;

use App\Support\Money;

/**
 * What the pipeline has worked out so far: the price proposed and the competitors still in play.
 */
final readonly class PipelineState
{
    /**
     * @param  list<Offer>  $competitors
     */
    public function __construct(
        public Money $proposed,
        public array $competitors,
    ) {}

    public function withProposed(Money $price): self
    {
        return new self($price, $this->competitors);
    }

    /**
     * @param  list<Offer>  $competitors
     */
    public function withCompetitors(array $competitors): self
    {
        return new self($this->proposed, $competitors);
    }
}
