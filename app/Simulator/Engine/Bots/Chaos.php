<?php

namespace App\Simulator\Engine\Bots;

use App\Support\Money;

/**
 * Random moves within a band, drawn from the simulation's seeded RNG (so a seed still
 * reproduces the run). Generates bursts of events to exercise rate limits and backoff.
 *
 * Params: min, max (cents, required), react_bps (chance of moving when scheduled, default 5000).
 */
final class Chaos implements CompetitorBot
{
    public static function key(): string
    {
        return 'chaos';
    }

    public function act(BotContext $context): BotAction
    {
        if (! $context->rng->chance($context->intParam('react_bps', 5000))) {
            return BotAction::hold('idle this tick');
        }

        $min = $context->intParam('min', $context->self->price->cents);
        $max = max($min, $context->intParam('max', $context->self->price->cents));
        $target = Money::cents($context->rng->int($min, $max));

        return $target->equals($context->self->price)
            ? BotAction::hold('rolled its current price')
            : BotAction::setPrice($target, "random move within {$min}..{$max}");
    }
}
