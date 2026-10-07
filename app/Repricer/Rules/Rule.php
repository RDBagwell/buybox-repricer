<?php

namespace App\Repricer\Rules;

/**
 * A single pricing rule. Implementations must be pure: no I/O, no clock, no randomness.
 */
interface Rule
{
    /** Stable identifier written to the rule trace. */
    public function name(): string;

    public function stage(): Stage;

    public function evaluate(PricingContext $context, PipelineState $state): Verdict;
}
