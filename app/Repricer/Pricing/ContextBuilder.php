<?php

namespace App\Repricer\Pricing;

use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\Product;
use App\Repricer\Rules\Flags;
use App\Repricer\Rules\Offer;
use App\Repricer\Rules\PricingContext;
use DateTimeImmutable;
use LogicException;

/**
 * Turns a product, its rule and a notification snapshot into a pure PricingContext.
 *
 * The market is the source of truth for our current price and shipping when our offer is in
 * the snapshot; the products row is the fallback.
 */
final class ContextBuilder
{
    public function __construct(private readonly string $ourSellerId) {}

    public function build(Product $product, OfferChangeNotification $snapshot, DateTimeImmutable $now, Flags $flags): PricingContext
    {
        $rule = $product->rule ?? throw new LogicException("Product {$product->sku} has no pricing rule.");
        $ours = $snapshot->offerFrom($this->ourSellerId);

        return new PricingContext(
            sku: $product->sku,
            cost: $product->cost,
            fees: $product->fees,
            currentPrice: $ours !== null ? $ours->price : $product->current_price,
            ourShipping: $ours !== null ? $ours->shipping : $product->shipping,
            config: $rule->toConfig(),
            offers: array_map(fn (MarketOffer $o) => new Offer(
                $o->sellerId, $o->price, $o->shipping, $o->fulfillment, $o->rating, $o->handlingDays, $o->isBuyBoxWinner, $o->sellerId === $this->ourSellerId,
            ), $snapshot->offers),
            weHoldBuyBox: $snapshot->buyBoxSellerId === $this->ourSellerId,
            lastChangedAt: $product->last_price_change_at?->toDateTimeImmutable(),
            now: $now,
            flags: $flags,
        );
    }
}
