<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\Offer;
use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;

/**
 * Ignore competitors that can't win anyway (low rating, slow handling) so we don't chase them down.
 *
 * Runs before any rule that reads competitors. The spec lists filters after the strategy; they
 * are moved earlier because a strategy that has already priced against an ineligible offer
 * cannot be "un-chased" by a later rule.
 */
final class CompetitorFilterRule implements Rule
{
    public function name(): string
    {
        return 'competitor_filter';
    }

    public function stage(): Stage
    {
        return Stage::Filter;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        $config = $context->config;
        $kept = [];
        $ignored = [];

        foreach ($state->competitors as $offer) {
            $why = $this->whyIgnored($offer, $config->minCompetitorRating, $config->maxCompetitorHandlingDays);
            if ($why === null) {
                $kept[] = $offer;
            } else {
                $ignored[] = "{$offer->sellerId} ({$why})";
            }
        }

        $total = count($state->competitors);
        if ($ignored === []) {
            return Verdict::narrow($kept, "All {$total} competitor(s) eligible.");
        }

        $reason = 'Ignored '.count($ignored)." of {$total}: ".implode(', ', $ignored).'.';
        if ($kept === []) {
            $reason .= ' Every competitor was filtered out.';
        }

        return Verdict::narrow($kept, $reason);
    }

    private function whyIgnored(Offer $offer, int $minRating, int $maxHandlingDays): ?string
    {
        if ($offer->rating < $minRating) {
            return "rating {$offer->rating} < {$minRating}";
        }
        if ($offer->handlingDays > $maxHandlingDays) {
            return "handling {$offer->handlingDays}d > {$maxHandlingDays}d";
        }

        return null;
    }
}
