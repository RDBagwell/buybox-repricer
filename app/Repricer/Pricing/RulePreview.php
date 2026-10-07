<?php

namespace App\Repricer\Pricing;

use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricingRule;
use App\Repricer\Models\Product;
use App\Repricer\Rules\Flags;
use App\Repricer\Rules\Pipeline;
use App\Repricer\Settings\RepricerSettings;

/**
 * "What would these rules do right now?" for the rule editor: the pure pipeline run against the
 * product's latest audited market snapshot, with the DRAFT rule, our current price, and the
 * gates (cooldown, pause, kill switch, dry run) left open so the answer is always a price. The
 * gates come back as notes instead. Nothing is written: no decision, no push, no audit row.
 */
final class RulePreview
{
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly ContextBuilder $contexts,
        private readonly MarketAdapter $market,
        private readonly RepricerSettings $settings,
        private readonly string $ourSellerId,
    ) {}

    /**
     * @param  array<string, mixed>  $draftRule  validated rule fields (integer cents)
     * @return array<string, mixed>
     */
    public function preview(Product $product, array $draftRule): array
    {
        $latest = PriceDecision::query()->where('product_id', $product->id)
            ->where('outcome', '!=', DecisionStatus::Stale->value)
            ->whereHas('snapshots')->latest('id')->first();
        if ($latest === null) {
            return ['available' => false, 'message' => 'No market snapshot yet: the preview appears once the repricer has seen this listing.'];
        }

        $snapshot = $this->withOurCurrentPrice(
            DecisionSnapshot::toNotification($latest, $product, 'preview', 'preview', 'preview'),
            $product,
        );

        $draft = new PricingRule(['product_id' => $product->id] + ($product->rule?->only(['min_competitor_rating', 'max_competitor_handling_days', 'no_competition']) ?? []) + $draftRule);
        $candidate = clone $product;
        $candidate->setRelation('rule', $draft);
        $candidate->setAttribute('last_price_change_at', null); // the cooldown is reported as a note

        $now = $this->market->now();
        $result = $this->pipeline->decide($this->contexts->build($candidate, $snapshot, $now, new Flags(false, false, false)));

        return [
            'available' => true,
            'snapshot_time' => $snapshot->eventTime->format(DATE_ATOM),
            'outcome' => $result->outcome->value,
            'old_price' => $result->oldPrice->cents,
            'new_price' => $result->newPrice?->cents,
            'reason' => $result->reason,
            'trace' => $result->traceArray(),
            'notes' => $this->notes($product, $draft, $now),
        ];
    }

    /** The snapshot may predate our latest push: decide from the price we have now. */
    private function withOurCurrentPrice(OfferChangeNotification $n, Product $product): OfferChangeNotification
    {
        $offers = array_map(fn (MarketOffer $o) => $o->sellerId === $this->ourSellerId
            ? new MarketOffer($o->sellerId, $product->current_price, $o->shipping, $o->fulfillment, $o->rating, $o->handlingDays, $o->isBuyBoxWinner)
            : $o, $n->offers);

        return new OfferChangeNotification(
            notificationId: $n->notificationId,
            asin: $n->asin,
            eventTime: $n->eventTime,
            offers: $offers,
            lowestLanded: $n->lowestLanded,
            buyBoxSellerId: $n->buyBoxSellerId,
            buyBoxLanded: $n->buyBoxLanded,
            triggerSellerId: $n->triggerSellerId,
            changeType: $n->changeType,
        );
    }

    /**
     * @return list<string>
     */
    private function notes(Product $product, PricingRule $draft, \DateTimeImmutable $now): array
    {
        $notes = [];
        if ($this->settings->killSwitch()) {
            $notes[] = 'The kill switch is on: nothing will be repriced until it is turned off.';
        }
        if ($product->paused) {
            $notes[] = 'This product is paused: it will not be repriced until it is resumed.';
        }
        if ($this->settings->dryRun()) {
            $notes[] = 'Dry run is on: the price would be decided but not pushed.';
        }
        $last = $product->last_price_change_at;
        if ($last !== null && $last->getTimestamp() + $draft->cooldown_sec > $now->getTimestamp()) {
            $until = $last->toDateTimeImmutable()->modify("+{$draft->cooldown_sec} seconds");
            $notes[] = 'Still cooling down until '.$until->format('H:i:s').' market time; the repricer acts on this after that.';
        }

        return $notes;
    }
}
