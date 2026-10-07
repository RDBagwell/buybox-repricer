<?php

namespace App\Simulator\Engine\Bots;

/**
 * A competitor strategy that acts on simulation ticks.
 *
 * Bots see the whole listing (like a real seller watching the offer page), their own offer,
 * their params, market time, the tick number, the shared seeded RNG and a small persisted
 * memory. They must not use any other source of time or randomness.
 *
 * Planned bots for session 2 fit this interface: Matcher (match lowest), Sleeper (dormant
 * for N ticks, then aggressive; uses memory), Chaos (random moves; uses the RNG).
 */
interface CompetitorBot
{
    /** Registry key used in scenarios and the sim_offers.bot column. */
    public static function key(): string;

    public function act(BotContext $context): BotAction;
}
