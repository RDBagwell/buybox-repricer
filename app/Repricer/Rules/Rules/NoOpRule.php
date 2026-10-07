<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Repricer\Rules\VetoCode;

/**
 * 8. No-op check: skip the push when the final price equals the current one.
 */
final class NoOpRule implements Rule
{
    public function name(): string
    {
        return 'no_op';
    }

    public function stage(): Stage
    {
        return Stage::Final;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        if ($state->proposed->equals($context->currentPrice)) {
            return Verdict::veto(VetoCode::NoChange, "Final price {$state->proposed} equals current price; nothing to push.");
        }

        return Verdict::pass("Price changes {$context->currentPrice} → {$state->proposed}.");
    }
}
