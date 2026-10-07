<?php

namespace App\Repricer\Outbound;

use App\Repricer\Events\PricePushed;
use App\Repricer\Events\ProductUpdated;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\MarketApiException;
use App\Repricer\Market\Operation;
use App\Repricer\Market\PriceUpdate;
use App\Repricer\Models\PriceDecision;
use App\Repricer\Models\PricePush;
use App\Repricer\Models\Product;
use App\Repricer\Pricing\DecisionStatus;
use App\Repricer\Safety\CircuitBreaker;
use App\Repricer\Settings\RepricerSettings;
use App\Support\RateLimiting\TokenBucket;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Performs one push attempt for a decision. Callers must hold the per-product "push" lock.
 *
 * Idempotent at three levels:
 *  - a decision with a terminal push row is never pushed again;
 *  - the request carries the decision id as idempotency key, so the market applies it once;
 *  - attempt rows are unique per (decision, attempt number).
 */
final class PricePusher
{
    public function __construct(
        private readonly MarketAdapter $market,
        private readonly TokenBucket $limiter,
        private readonly Backoff $backoff,
        private readonly RepricerSettings $settings,
        private readonly CircuitBreaker $breaker,
        private readonly int $maxAttempts = 5,
    ) {}

    public static function idempotencyKey(PriceDecision $decision): string
    {
        return 'decision-'.$decision->id;
    }

    public function attempt(PriceDecision $decision): PushAttempt
    {
        $pushes = $decision->pushes()->get();
        if ($pushes->contains(fn (PricePush $p) => PushStatus::from($p->status)->isTerminal())) {
            return PushAttempt::done('already finished');
        }

        if ($decision->outcome !== DecisionStatus::Reprice->value || $decision->new_price === null) {
            return PushAttempt::done('nothing to push');
        }

        $product = $decision->product;
        $nextAttempt = $pushes->count() + 1;

        $newer = PriceDecision::query()->where('product_id', $decision->product_id)
            ->where('id', '>', $decision->id)
            ->whereIn('outcome', [DecisionStatus::Reprice->value, DecisionStatus::DryRun->value])
            ->exists();
        if ($newer) {
            return $this->terminal($decision, $product, PushStatus::Superseded, $nextAttempt, ['reason' => 'A newer decision exists for this product.']);
        }

        if ($this->settings->killSwitch()) {
            return $this->terminal($decision, $product, PushStatus::Cancelled, $nextAttempt, ['reason' => 'Kill switch turned on before the push.']);
        }
        if ($this->settings->dryRun()) {
            return $this->terminal($decision, $product, PushStatus::Cancelled, $nextAttempt, ['reason' => 'Dry run turned on before the push.']);
        }
        // The breaker also reports a product paused since the decision was made.
        if (($why = $this->breaker->openReason($product)) !== null) {
            return $this->terminal($decision, $product, PushStatus::Blocked, $nextAttempt, ['reason' => $why]);
        }

        // Stay under the market's quota: no token, no request (and no quota burned on a 429).
        $quota = $this->market->quota(Operation::PatchListingsItem);
        $wait = $this->limiter->take('repricer:'.Operation::PatchListingsItem->value, $quota->burst, $quota->refillMs);
        if ($wait > 0) {
            return PushAttempt::retryIn($wait, 'local rate limit');
        }

        try {
            $result = $this->market->updatePrice(new PriceUpdate($product->sku, $decision->new_price, self::idempotencyKey($decision)));
        } catch (MarketApiException $e) {
            $giveUp = ! $e->isRetryable() || $nextAttempt >= $this->maxAttempts;
            $status = $giveUp ? PushStatus::Failed : PushStatus::Retrying;
            $this->recordAttempt($decision, $product, $status, $nextAttempt, [
                'http_status' => $e->status,
                'message' => $e->getMessage(),
                'retry_after_ms' => $e->retryAfterMs,
                'body' => $e->body,
            ], null);

            return $giveUp
                ? PushAttempt::done("gave up after attempt {$nextAttempt}: HTTP {$e->status}")
                : PushAttempt::retryIn($this->backoff->delayMs($nextAttempt, $e->retryAfterMs), "HTTP {$e->status}");
        }

        DB::transaction(function () use ($decision, $product, $nextAttempt, $result) {
            $this->recordAttempt($decision, $product, PushStatus::Succeeded, $nextAttempt, $result->raw + ['replayed' => $result->replayed], $result->appliedAt);
            $product->forceFill([
                'current_price' => $decision->new_price,
                'last_price_change_at' => $result->appliedAt,
            ])->save();
        });

        ProductUpdated::dispatch($product->id);

        return PushAttempt::done('pushed');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function terminal(PriceDecision $decision, Product $product, PushStatus $status, int $attempt, array $response): PushAttempt
    {
        $this->recordAttempt($decision, $product, $status, $attempt, $response, null);

        return PushAttempt::done($status->value);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function recordAttempt(PriceDecision $decision, Product $product, PushStatus $status, int $attempt, array $response, ?DateTimeImmutable $pushedAt): void
    {
        PricePush::query()->create([
            'decision_id' => $decision->id,
            'status' => $status->value,
            'attempts' => $attempt,
            'api_response' => $response,
            'pushed_at' => $pushedAt,
        ]);

        $this->breaker->record($product, $status);
        PricePushed::dispatch($decision->id, $product->id, $status->value, $attempt);
    }
}
