<?php

namespace App\Repricer\Pricing;

use App\Repricer\Events\BuyBoxChanged;
use App\Repricer\Events\DecisionRecorded;
use App\Repricer\Jobs\PushPriceJob;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Models\BuyBoxHistory;
use App\Repricer\Models\DuplicateDelivery;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\Product;
use App\Repricer\Outbound\PushStatus;
use App\Repricer\Rules\Decision;
use App\Repricer\Rules\DecisionOutcome;
use App\Repricer\Rules\Flags;
use App\Repricer\Rules\Pipeline;
use App\Repricer\Settings\RepricerSettings;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles one notification for one product: the impure shell around the pure pipeline.
 *
 * Callers must hold the per-product "reprice" lock (RepriceJob does, via ProductLock).
 *
 *   duplicate event      -> recorded in duplicate_deliveries; resumes an unfinished push; no new decision
 *   stale event          -> decision row with outcome "stale"
 *   otherwise            -> pipeline decision + snapshot + trace, then a queued push if repricing
 */
final class RepricingService
{
    public function __construct(
        private readonly Pipeline $pipeline,
        private readonly ContextBuilder $contexts,
        private readonly MarketAdapter $market,
        private readonly RepricerSettings $settings,
        private readonly string $ourSellerId,
    ) {}

    public function handle(Product $product, OfferChangeNotification $notification): PriceDecision
    {
        $existing = $this->find($product, $notification->notificationId);
        if ($existing !== null) {
            return $this->duplicate($product, $existing);
        }

        $now = $this->market->now();
        $latest = $this->latestSnapshotTime($product);

        try {
            if ($latest !== null && $notification->eventTime < $latest) {
                $decision = $this->recordStale($product, $notification, $latest, $now);
            } else {
                $flags = new Flags($this->settings->killSwitch(), $this->settings->dryRun(), $product->paused);
                $result = $this->pipeline->decide($this->contexts->build($product, $notification, $now, $flags));
                $decision = $this->record($product, $notification, $result, $flags, $now);
            }
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another delivery of the same event: theirs is the decision.
            $existing = $this->find($product, $notification->notificationId);
            assert($existing !== null);

            return $this->duplicate($product, $existing);
        }

        DecisionRecorded::dispatch($decision->id, $product->id, $decision->outcome);

        if ($decision->outcome === DecisionStatus::Reprice->value) {
            PushPriceJob::dispatch($decision->id);
        }

        return $decision;
    }

    private function record(Product $product, OfferChangeNotification $n, Decision $result, Flags $flags, DateTimeImmutable $now): PriceDecision
    {
        $status = match ($result->outcome) {
            DecisionOutcome::Reprice => $flags->dryRun ? DecisionStatus::DryRun : DecisionStatus::Reprice,
            DecisionOutcome::NoChange => DecisionStatus::NoChange,
            DecisionOutcome::Skipped => DecisionStatus::Skipped,
            DecisionOutcome::ConfigError => DecisionStatus::ConfigError,
        };

        $reason = $status === DecisionStatus::DryRun ? 'Dry run: would '.lcfirst($result->reason) : $result->reason;

        /** @var array{0: PriceDecision, 1: BuyBoxChanged|null} $written */
        $written = DB::transaction(function () use ($product, $n, $result, $status, $reason, $now) {
            $decision = $this->insertDecision($product, $n, $status, $result->oldPrice->cents, $result->newPrice?->cents, $result->vetoCode?->value, $reason, $result->traceArray(), $now);
            $changed = $this->recordBuyBox($product, $n);

            $ours = $n->offerFrom($this->ourSellerId);
            if ($ours !== null && ! $ours->price->equals($product->current_price)) {
                $product->forceFill(['current_price' => $ours->price])->save();
            }

            return [$decision, $changed];
        });

        if ($written[1] !== null) {
            event($written[1]);
        }

        return $written[0];
    }

    private function recordStale(Product $product, OfferChangeNotification $n, CarbonImmutable $latest, DateTimeImmutable $now): PriceDecision
    {
        $reason = sprintf('Stale event: snapshot at %s is older than %s, the snapshot behind the latest decision.', $n->eventTime->format('H:i:s.v'), $latest->format('H:i:s.v'));

        return DB::transaction(fn () => $this->insertDecision(
            $product, $n, DecisionStatus::Stale, $product->current_price->cents, null, 'stale', $reason, [], $now,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $trace
     */
    private function insertDecision(Product $product, OfferChangeNotification $n, DecisionStatus $status, int $old, ?int $new, ?string $code, string $reason, array $trace, DateTimeImmutable $now): PriceDecision
    {
        $decision = PriceDecision::query()->create([
            'product_id' => $product->id,
            'event_id' => $n->notificationId,
            'event_time' => $n->eventTime,
            'old_price' => $old,
            'new_price' => $new,
            'outcome' => $status->value,
            'reason_code' => $code,
            'reason' => $reason,
            'rule_trace' => $trace,
            'decided_at' => $now,
        ]);

        $decision->snapshots()->createMany(array_map(fn (MarketOffer $o) => [
            'seller' => $o->sellerId,
            'price' => $o->price,
            'shipping' => $o->shipping,
            'fulfillment' => $o->fulfillment->value,
            'rating' => $o->rating,
            'handling_days' => $o->handlingDays,
            'is_buybox' => $o->isBuyBoxWinner,
            'is_ours' => $o->sellerId === $this->ourSellerId,
            'captured_at' => $n->eventTime,
        ], $n->offers));

        return $decision;
    }

    private function recordBuyBox(Product $product, OfferChangeNotification $n): ?BuyBoxChanged
    {
        $last = BuyBoxHistory::query()->where('product_id', $product->id)->latest('id')->first();
        if ($last !== null && $last->winner === $n->buyBoxSellerId) {
            return null;
        }

        BuyBoxHistory::query()->create([
            'product_id' => $product->id,
            'winner' => $n->buyBoxSellerId,
            'our_price' => $n->offerFrom($this->ourSellerId)?->price,
            'changed_at' => $n->eventTime,
        ]);

        return new BuyBoxChanged($product->id, $last?->winner, $n->buyBoxSellerId, $n->buyBoxSellerId === $this->ourSellerId);
    }

    private function duplicate(Product $product, PriceDecision $existing): PriceDecision
    {
        DuplicateDelivery::query()->create([
            'product_id' => $product->id,
            'event_id' => $existing->event_id,
            'decision_id' => $existing->id,
            'received_at' => now(),
        ]);

        // A crash between recording the decision and queueing its push leaves a reprice with no
        // finished push. The redelivery is our chance to resume it (pushes are idempotent).
        if ($existing->outcome === DecisionStatus::Reprice->value && ! $this->pushFinished($existing)) {
            PushPriceJob::dispatch($existing->id);
        }

        return $existing;
    }

    public function pushFinished(PriceDecision $decision): bool
    {
        return $decision->pushes()->get()->contains(fn ($p) => PushStatus::from($p->status)->isTerminal());
    }

    private function find(Product $product, string $eventId): ?PriceDecision
    {
        return PriceDecision::query()->where('product_id', $product->id)->where('event_id', $eventId)->first();
    }

    private function latestSnapshotTime(Product $product): ?CarbonImmutable
    {
        $latest = PriceDecision::query()->where('product_id', $product->id)->orderByDesc('event_time')->first(['event_time']);

        return $latest?->event_time;
    }
}
