<?php

namespace App\Repricer\Rules\Rules;

use App\Repricer\Rules\Offer;
use App\Repricer\Rules\PipelineState;
use App\Repricer\Rules\PricingContext;
use App\Repricer\Rules\Rule;
use App\Repricer\Rules\Stage;
use App\Repricer\Rules\Strategy;
use App\Repricer\Rules\Verdict;

/**
 * 3. Strategy: beat lowest by X, match lowest, or beat the Buy Box holder by X, on landed price.
 *
 * Only runs when we are NOT holding the Buy Box (AlreadyWinningRule owns that case).
 * Ties on landed price: the reference is that shared landed price; the trace notes the tie.
 */
final class StrategyRule implements Rule
{
    public function name(): string
    {
        return 'strategy';
    }

    public function stage(): Stage
    {
        return Stage::Shaping;
    }

    public function evaluate(PricingContext $context, PipelineState $state): Verdict
    {
        if ($context->weHoldBuyBox) {
            return Verdict::pass('We hold the Buy Box; strategy not applied (see already_winning).');
        }

        $competitors = $state->competitors;
        if ($competitors === []) {
            return NoCompetition::verdict($context, $state);
        }

        $config = $context->config;
        [$reference, $label] = $this->reference($config->strategy, $competitors);

        $refLanded = $reference->landed();
        $ties = AlreadyWinningRule::countAtLanded($competitors, $refLanded);
        $tieNote = $ties > 1 ? " ({$ties} offers tied at {$refLanded})" : '';

        $target = $context->priceForLanded($refLanded->minus($config->undercut()));

        $verb = match ($config->strategy) {
            Strategy::MatchLowest => 'match',
            default => "beat by {$config->offset}",
        };

        return Verdict::propose($target, "{$config->strategy->value}: {$verb} {$label} {$reference->describe()}{$tieNote} -> price {$target}.");
    }

    /**
     * @param  non-empty-list<Offer>  $competitors
     * @return array{Offer, string}
     */
    private function reference(Strategy $strategy, array $competitors): array
    {
        if ($strategy === Strategy::BeatBuyBox) {
            foreach ($competitors as $offer) {
                if ($offer->isBuyBoxWinner) {
                    return [$offer, 'Buy Box holder'];
                }
            }

            return [AlreadyWinningRule::lowestLanded($competitors), 'lowest (no eligible Buy Box holder)'];
        }

        return [AlreadyWinningRule::lowestLanded($competitors), 'lowest'];
    }
}
