<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

/**
 * Undercuts the Buy Box holder's landed price by one cent, down to its own floor.
 *
 * Params: floor (cents, required), react_bps (chance of reacting on a tick it is
 * scheduled to act, default 8000 = 80%, drawn from the seeded RNG).
 */
final class PennyPincher implements CompetitorBot
{
    public static function key(): string
    {
        return 'penny_pincher';
    }

    public function act(BotContext $context): BotAction
    {
        $self = $context->self;
        $holder = $context->listing->buyBoxOffer();

        if ($holder !== null && $holder->sellerId === $self->sellerId) {
            return BotAction::hold('holds the Buy Box');
        }

        if (! $context->rng->chance($context->intParam('react_bps', 8000))) {
            return BotAction::hold('did not react this tick');
        }

        $targetLanded = $holder?->landed() ?? $context->lowestOtherLanded();
        if ($targetLanded === null) {
            return BotAction::hold('alone on the listing');
        }

        $floor = Money::cents($context->intParam('floor', 1));
        $target = Money::max($targetLanded->minus(Money::cents(1))->minus($self->shipping), $floor);

        if ($target->equals($self->price)) {
            return BotAction::hold($target->equals($floor) ? 'at floor' : 'already undercutting');
        }

        return BotAction::setPrice($target, $holder === null
            ? "undercut lowest landed {$targetLanded} by 0.01"
            : "undercut Buy Box holder {$holder->sellerId} ({$targetLanded}) by 0.01");
    }
}
