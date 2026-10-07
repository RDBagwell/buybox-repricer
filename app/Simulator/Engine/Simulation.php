<?php

namespace App\Simulator\Engine;

use App\Simulator\Engine\Bots\BotContext;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Support\Money;

/**
 * The deterministic core: advances a World one tick at a time.
 *
 * Per tick, in a fixed order (listings by ASIN, offers by seller id): each bot scheduled on this
 * tick acts; then the Buy Box is recomputed (ties to the incumbent); any listing whose offers or
 * Buy Box changed emits exactly one AnyOfferChanged.
 *
 * Same seed + same starting world + same external inputs ⇒ identical results.
 */
final readonly class Simulation
{
    public function __construct(
        private BuyBoxScorer $scorer,
        private BotRegistry $bots,
    ) {}

    public function tick(World $world): TickResult
    {
        $world->tick++;
        $world->clock->advance();
        $now = $world->clock->now();

        $events = [];
        $changes = [];
        $moves = [];
        $changed = [];

        foreach ($world->listings as $listing) {
            $trigger = null;

            $changeType = 'competitor_price';
            foreach ($listing->offers as $offer) {
                if ($offer->bot === null) {
                    continue;
                }

                // Out of stock: wait for the restock tick (set by a stockout), then come back.
                if (! $offer->inStock) {
                    $restockAt = (int) ($offer->botMemory['restock_at'] ?? 0);
                    if ($restockAt > 0 && $world->tick >= $restockAt) {
                        $memory = $offer->botMemory;
                        unset($memory['restock_at']);
                        $listing->put($offer->withStock(true)->withMemory($memory));
                        $moves[] = ['asin' => $listing->asin, 'seller' => $offer->sellerId, 'from' => $offer->price->cents, 'to' => $offer->price->cents, 'reason' => 'back in stock'];
                        $trigger ??= $offer->sellerId;
                        $changeType = 'competitor_stock';
                    }

                    continue;
                }

                $every = max(1, (int) ($offer->botParams['every'] ?? 1));
                if ($world->tick % $every !== 0) {
                    continue;
                }

                // Re-read: an earlier bot on this listing may have moved.
                $current = $listing->offer($offer->sellerId) ?? $offer;
                $action = $this->bots->get($offer->bot)->act(new BotContext(
                    $listing, $current, $current->botParams, $current->botMemory, $world->tick, $now, $world->rng,
                ));

                if ($action->memory !== null) {
                    $current = $current->withMemory($action->memory);
                    $listing->put($current);
                }

                if ($action->stockoutTicks !== null) {
                    $listing->put($this->outOfStock($current, $world->tick + $action->stockoutTicks));
                    $moves[] = ['asin' => $listing->asin, 'seller' => $current->sellerId, 'from' => $current->price->cents, 'to' => $current->price->cents, 'reason' => 'out of stock: '.$action->reason];
                    $trigger ??= $current->sellerId;
                    $changeType = 'competitor_stock';

                    continue;
                }

                if ($action->newPrice !== null && ! $action->newPrice->equals($current->price)) {
                    $moves[] = ['asin' => $listing->asin, 'seller' => $current->sellerId, 'from' => $current->price->cents, 'to' => $action->newPrice->cents, 'reason' => $action->reason];
                    $listing->put($current->withPrice($action->newPrice));
                    $trigger ??= $current->sellerId;
                }
            }

            $bbChange = $this->recomputeBuyBox($listing, $world->tick, $world->clock->nowMs);
            if ($bbChange !== null) {
                $changes[] = $bbChange;
            }

            if ($trigger !== null || $bbChange !== null) {
                $changed[] = $listing->asin;
                $events[] = AnyOfferChanged::fromListing($listing, $world->tick, $world->clock->nowMs, $trigger ?? (string) $listing->buyBoxSellerId, $trigger !== null ? $changeType : 'buybox');
            }
        }

        return new TickResult($world->tick, $world->clock->nowMs, $events, $changes, $moves, $changed);
    }

    /**
     * Apply a price set through the API (our own listing update) at the given market time.
     */
    public function applyPriceUpdate(Listing $listing, string $sellerId, Money $price, int $tick, int $marketTimeMs): ListingUpdate
    {
        $offer = $listing->offer($sellerId) ?? throw new \InvalidArgumentException("No offer from {$sellerId} on {$listing->asin}.");
        $listing->put($offer->withPrice($price));
        $bbChange = $this->recomputeBuyBox($listing, $tick, $marketTimeMs);

        return new ListingUpdate(AnyOfferChanged::fromListing($listing, $tick, $marketTimeMs, $sellerId, 'listing_update'), $bbChange);
    }

    /**
     * Take a bot's offer off sale until $restockAtTick (a manual stockout or a Sleeper going quiet).
     */
    public function outOfStock(SimOffer $offer, int $restockAtTick): SimOffer
    {
        return $offer->withStock(false)->withMemory(['restock_at' => $restockAtTick] + $offer->botMemory);
    }

    public function recomputeBuyBox(Listing $listing, int $tick, int $marketTimeMs): ?BuyBoxChange
    {
        $result = $this->scorer->decide($listing->activeOffers(), $listing->buyBoxSellerId);
        if ($result->winner === $listing->buyBoxSellerId) {
            return null;
        }

        $from = $listing->buyBoxSellerId;
        $listing->buyBoxSellerId = $result->winner;

        return new BuyBoxChange($listing->asin, $tick, $marketTimeMs, $from, $result->winner, $listing->buyBoxOffer()?->landed()->cents, $result->reason);
    }
}
