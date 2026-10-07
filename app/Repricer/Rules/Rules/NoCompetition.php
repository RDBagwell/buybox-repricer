<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\NoCompetitionAction;
use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Verdict;

/**
 * Shared behaviour for "nobody to price against": hold, or head for the ceiling.
 */
final class NoCompetition
{
    public static function verdict(PricingContext $context, PipelineState $state): Verdict
    {
        $why = $context->competitors() === []
            ? 'No competitors on the listing'
            : 'Every competitor was filtered out';

        return match ($context->config->noCompetition) {
            NoCompetitionAction::Hold => Verdict::pass("{$why}; holding current price (no_competition=hold)."),
            NoCompetitionAction::RaiseToCeiling => Verdict::propose(
                $context->config->ceiling,
                "{$why}; raising toward ceiling {$context->config->ceiling} (no_competition=raise_to_ceiling).",
            ),
        };
    }
}
