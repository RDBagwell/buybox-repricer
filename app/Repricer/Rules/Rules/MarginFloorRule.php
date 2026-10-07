<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Repricer\Rules\VetoCode;

/**
 * 6. Margin floor: never price below cost + fees + minimum margin.
 *
 * A margin floor above the ceiling makes every price illegal: that is a configuration
 * error, vetoed and flagged rather than "resolved" by picking one bound.
 */
final class MarginFloorRule implements Rule
{
    public function name(): string
    {
        return 'margin_floor';
    }

    public function stage(): Stage
    {
        return Stage::Guardrail;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        $marginFloor = $context->marginFloor();
        $ceiling = $context->config->ceiling;

        if ($marginFloor->greaterThan($ceiling)) {
            return Verdict::veto(VetoCode::ConfigError, "Configuration error: margin floor {$marginFloor} (cost {$context->cost} + fees {$context->fees} + min margin {$context->config->minMargin}) is above ceiling {$ceiling}.");
        }

        if ($state->proposed->lessThan($marginFloor)) {
            return Verdict::propose($marginFloor, "Raised {$state->proposed} to margin floor {$marginFloor} (cost + fees + min margin).");
        }

        return Verdict::pass("{$state->proposed} is at or above margin floor {$marginFloor}.");
    }
}
