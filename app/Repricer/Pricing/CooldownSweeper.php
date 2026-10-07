<?php

namespace App\Repricer\Pricing;

use App\Repricer\Jobs\RepriceJob;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\OfferSnapshot;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Support\Fulfillment;
use Illuminate\Support\Facades\Cache;

/**
 * Closes the gap in a purely push-driven design: an event skipped because of the cooldown is
 * never revisited if the market then goes quiet (no change, no notification).
 *
 * For every product whose latest decision was a cooldown skip and whose cooldown has now
 * expired in MARKET time, re-run the decision from that decision's own recorded input snapshot
 * (the audit log has it), as event "<event id>#recheck". No market request is spent: if
 * nothing has been notified since, nothing has changed since.
 */
final class CooldownSweeper
{
    public const CHANGE_TYPE = 'cooldown_recheck';

    private const SUFFIX = '#recheck';

    public function __construct(private readonly MarketAdapter $market) {}

    /** @return int number of re-checks queued */
    public function sweep(): int
    {
        $latestIds = PriceDecision::query()->selectRaw('max(id)')->groupBy('product_id');
        $candidates = PriceDecision::query()
            ->whereIn('id', $latestIds)
            ->where('reason_code', 'cooldown')
            ->with('product.rule')
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $now = $this->market->now();
        $queued = 0;

        foreach ($candidates as $decision) {
            $product = $decision->product;
            $rule = $product->rule;
            $last = $product->last_price_change_at;
            if ($rule === null || $last === null || $product->paused) {
                continue;
            }
            if ($last->getTimestamp() + $rule->cooldown_sec > $now->getTimestamp()) {
                continue; // still cooling down
            }
            if (! Cache::add("repricer:recheck:{$decision->id}", 1, 60)) {
                continue; // already queued
            }

            RepriceJob::dispatch($product->id, $this->notificationFrom($decision, $product)->toArray());
            $queued++;
        }

        return $queued;
    }

    /**
     * A queued re-check is only worth running while the cooldown skip it re-checks is still the
     * product's latest decision. If a newer notification has been decided meanwhile, that newer
     * decision already reflects the market and the re-check would only be recorded as stale noise.
     */
    public static function stillCurrent(Product $product, OfferChangeNotification $recheck): bool
    {
        if ($recheck->changeType !== self::CHANGE_TYPE || ! str_ends_with($recheck->notificationId, self::SUFFIX)) {
            return true;
        }

        $latest = PriceDecision::query()->where('product_id', $product->id)->latest('id')->value('event_id');

        return $latest === substr($recheck->notificationId, 0, -strlen(self::SUFFIX));
    }

    private function notificationFrom(PriceDecision $decision, Product $product): OfferChangeNotification
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
            notificationId: $decision->event_id.self::SUFFIX,
            asin: $product->asin,
            eventTime: $decision->event_time,
            offers: array_values($offers),
            lowestLanded: $lowest,
            buyBoxSellerId: $buyBox?->sellerId,
            buyBoxLanded: $buyBox?->landed(),
            triggerSellerId: 'cooldown-recheck',
            changeType: self::CHANGE_TYPE,
        );
    }
}
