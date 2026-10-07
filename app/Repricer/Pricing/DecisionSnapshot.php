<?php

namespace App\Repricer\Pricing;

use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\OfferSnapshot;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Support\Fulfillment;

/**
 * Rebuilds the market snapshot a decision was made from (the audited offer_snapshots) as an
 * OfferChangeNotification, so it can be decided again without spending a market request:
 * by the cooldown sweeper, and by the rule editor's preview.
 */
final class DecisionSnapshot
{
    public static function toNotification(PriceDecision $decision, Product $product, string $notificationId, string $changeType, string $triggerSellerId): OfferChangeNotification
    {
        $offers = $decision->snapshots()->orderBy('id')->get()->map(fn (OfferSnapshot $s) => new MarketOffer(
            $s->seller, $s->price, $s->shipping, Fulfillment::from($s->fulfillment), $s->rating, $s->handling_days, $s->is_buybox,
        ))->all();

        $lowest = ['overall' => null, 'marketplace' => null, 'merchant' => null];
        $buyBox = null;
        foreach ($offers as $o) {
            $landed = $o->landed()->cents;
            $lowest['overall'] = min($lowest['overall'] ?? $landed, $landed);
            $lowest[$o->fulfillment->value] = min($lowest[$o->fulfillment->value] ?? $landed, $landed);
            $buyBox = $o->isBuyBoxWinner ? $o : $buyBox;
        }

        return new OfferChangeNotification(
            notificationId: $notificationId,
            asin: $product->asin,
            eventTime: $decision->event_time,
            offers: array_values($offers),
            lowestLanded: $lowest,
            buyBoxSellerId: $buyBox?->sellerId,
            buyBoxLanded: $buyBox?->landed(),
            triggerSellerId: $triggerSellerId,
            changeType: $changeType,
        );
    }
}
