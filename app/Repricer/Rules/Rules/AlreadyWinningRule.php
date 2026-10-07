<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\Offer;
use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Verdict;
use App\Support\Money;

/**
 * 2. Already winning? If we hold the Buy Box, never cut: consider raising toward the next
 * competitor's landed price (minus our undercut), and leave the ceiling to the guardrails.
 */
final class AlreadyWinningRule implements Rule
{
    public function name(): string
    {
        return 'already_winning';
    }

    public function stage(): Stage
    {
        return Stage::Shaping;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        if (! $context->weHoldBuyBox) {
            return Verdict::pass('We do not hold the Buy Box.');
        }

        if ($state->competitors === []) {
            return NoCompetition::verdict($context, $state);
        }

        $next = self::lowestLanded($state->competitors);
        $targetLanded = $next->landed()->minus($context->config->undercut());
        $target = $context->priceForLanded($targetLanded);

        if ($target->greaterThan($state->proposed)) {
            return Verdict::propose($target, "We hold the Buy Box; raising toward next competitor {$next->describe()} (target {$target}).");
        }

        return Verdict::pass("We hold the Buy Box; next competitor {$next->describe()} leaves no room to raise, holding {$state->proposed}.");
    }

    /**
     * Lowest landed price; ties broken by seller id so the result is deterministic.
     *
     * @param  non-empty-list<Offer>  $offers
     */
    public static function lowestLanded(array $offers): Offer
    {
        usort($offers, fn (Offer $a, Offer $b) => [$a->landed()->cents, $a->sellerId] <=> [$b->landed()->cents, $b->sellerId]);

        return $offers[0];
    }

    /**
     * @param  list<Offer>  $offers
     */
    public static function countAtLanded(array $offers, Money $landed): int
    {
        return count(array_filter($offers, fn (Offer $o) => $o->landed()->equals($landed)));
    }
}
