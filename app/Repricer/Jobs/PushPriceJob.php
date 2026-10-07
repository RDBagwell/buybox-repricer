<?php

namespace App\Repricer\Jobs;

use App\Repricer\Models\PriceDecision;
use App\Repricer\Outbound\PricePusher;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Push a decided price to the market, retrying 429/503 with backoff (honouring Retry-After)
 * up to the attempt cap. Every attempt is a price_pushes row.
 */
final class PushPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $decisionId)
    {
        $this->onQueue('push');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $productId = (int) DB::table('price_decisions')->where('id', $this->decisionId)->value('product_id');

        return [ProductLock::for(ProductLock::PUSH, $productId)];
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15)->toDateTime();
    }

    public function handle(PricePusher $pusher): void
    {
        $decision = PriceDecision::query()->with('product')->find($this->decisionId);
        if ($decision === null) {
            return;
        }

        $attempt = $pusher->attempt($decision);
        if (! $attempt->finished && $attempt->retryInMs !== null) {
            // Queue delays have one-second resolution: round up so Retry-After is never undercut.
            $this->release((int) ceil($attempt->retryInMs / 1000));
        }
    }
}
