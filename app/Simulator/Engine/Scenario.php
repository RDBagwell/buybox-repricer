<?php

namespace App\Simulator\Engine;

use App\Support\Fulfillment;
use App\Support\Money;

/**
 * Builds the initial World from the scenario definition in config/simulator.php.
 */
final class Scenario
{
    public const OUR_STORE_PLACEHOLDER = 'our_store';

    /**
     * @param  list<array{asin: string, title: string, offers: list<array<string, mixed>>}>  $listings
     */
    public static function build(array $listings, int $seed, string $ourSellerId, SimClock $clock, BuyBoxScorer $scorer): World
    {
        $built = [];
        foreach ($listings as $def) {
            $offers = [];
            foreach ($def['offers'] as $o) {
                $seller = $o['seller'] === self::OUR_STORE_PLACEHOLDER ? $ourSellerId : (string) $o['seller'];
                $offers[$seller] = new SimOffer(
                    sellerId: $seller,
                    price: Money::cents((int) $o['price']),
                    shipping: Money::cents((int) ($o['shipping'] ?? 0)),
                    fulfillment: Fulfillment::from((string) $o['fulfillment']),
                    rating: (int) $o['rating'],
                    handlingDays: (int) $o['handling_days'],
                    sku: isset($o['sku']) ? (string) $o['sku'] : null,
                    bot: isset($o['bot']) ? (string) $o['bot'] : null,
                    botParams: (array) ($o['params'] ?? []), // @phpstan-ignore argument.type
                );
            }
            $listing = new Listing($def['asin'], $def['title'], $offers);
            $listing->buyBoxSellerId = $scorer->decide($listing->offers, null)->winner;
            $built[$def['asin']] = $listing;
        }

        return new World($built, $clock, SeededRng::fromSeed($seed), 0, $seed);
    }
}
