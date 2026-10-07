<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

/**
 * Holds a fixed price. Params: price (cents; defaults to the offer's starting price).
 */
final class Anchor implements CompetitorBot
{
    public static function key(): string
    {
        return 'anchor';
    }

    public function act(BotContext $context): BotAction
    {
        $anchor = Money::cents($context->intParam('price', $context->self->price->cents));

        return $anchor->equals($context->self->price)
            ? BotAction::hold("anchored at {$anchor}")
            : BotAction::setPrice($anchor, "returns to anchor {$anchor}");
    }
}
