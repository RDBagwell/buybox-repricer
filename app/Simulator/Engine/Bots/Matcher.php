<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

/**
 * Matches the lowest landed price on the listing (never undercuts), down to its own floor.
 * Exercises tie handling: when it matches the Buy Box holder, the incumbent keeps the box.
 *
 * Params: floor (cents, default 1).
 */
final class Matcher implements CompetitorBot
{
    public static function key(): string
    {
        return 'matcher';
    }

    public function act(BotContext $context): BotAction
    {
        $lowest = $context->lowestOtherLanded();
        if ($lowest === null) {
            return BotAction::hold('alone on the listing');
        }

        $floor = Money::cents($context->intParam('floor', 1));
        $target = Money::max($lowest->minus($context->self->shipping), $floor);

        return $target->equals($context->self->price)
            ? BotAction::hold("already matching {$lowest} landed")
            : BotAction::setPrice($target, "match lowest landed {$lowest}");
    }
}
