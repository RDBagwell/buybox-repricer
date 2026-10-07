<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Repricer\Rules\VetoCode;

/**
 * 7. Floor and ceiling clamp: the hard per-product minimum and maximum.
 */
final class FloorCeilingRule implements Rule
{
    public function name(): string
    {
        return 'floor_ceiling';
    }

    public function stage(): Stage
    {
        return Stage::Guardrail;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        $floor = $context->config->floor;
        $ceiling = $context->config->ceiling;

        if ($floor->greaterThan($ceiling)) {
            return Verdict::veto(VetoCode::ConfigError, "Configuration error: floor {$floor} is above ceiling {$ceiling}.");
        }

        if ($state->proposed->lessThan($floor)) {
            return Verdict::propose($floor, "Raised {$state->proposed} to floor {$floor}.");
        }

        if ($state->proposed->greaterThan($ceiling)) {
            return Verdict::propose($ceiling, "Lowered {$state->proposed} to ceiling {$ceiling}.");
        }

        return Verdict::pass("{$state->proposed} within floor {$floor} and ceiling {$ceiling}.");
    }
}
