<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

/**
 * Sells for a while at a fixed price, then goes out of stock, then comes back. Tests that the
 * repricer raises its price when competition disappears and fights again when it returns.
 *
 * Params: price (cents, default: the offer's starting price), awake_ticks (default 80),
 * asleep_ticks (default 60). Memory: awake_since (tick it last came back in stock).
 */
final class Sleeper implements CompetitorBot
{
    public static function key(): string
    {
        return 'sleeper';
    }

    public function act(BotContext $context): BotAction
    {
        $memory = $context->memory;
        $awakeSince = (int) ($memory['awake_since'] ?? $context->tick);
        $memory['awake_since'] = $awakeSince;

        if ($context->tick - $awakeSince >= $context->intParam('awake_ticks', 80)) {
            unset($memory['awake_since']); // reset when it comes back

            return BotAction::stockout($context->intParam('asleep_ticks', 60), 'sold out', $memory);
        }

        $price = Money::cents($context->intParam('price', $context->self->price->cents));

        return $price->equals($context->self->price)
            ? BotAction::hold("in stock at {$price}", $memory)
            : BotAction::setPrice($price, "back at {$price}", $memory);
    }
}
