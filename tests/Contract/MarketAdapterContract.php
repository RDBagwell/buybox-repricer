<?php

namespace Tests\Contract;

use App\Repricer\Market\MarketApiException;
use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\Operation;
use App\Repricer\Market\PriceUpdate;
use App\Support\Money;
use Closure;
use Illuminate\Support\Str;

/**
 * The MarketAdapter contract: every implementation must pass these.
 *
 * Usage, from a test file: MarketAdapterContract::register(fn () => new MyHarness);
 */
final class MarketAdapterContract
{
    /**
     * @param  Closure(): AdapterHarness  $harness
     */
    public static function register(Closure $harness): void
    {
        it('returns the offers for a known ASIN in integer cents, ours included', function () use ($harness) {
            $h = $harness();
            $offers = $h->adapter()->getItemOffers($h->knownAsin());

            expect($offers->asin)->toBe($h->knownAsin())
                ->and($offers->offers)->not->toBeEmpty()
                ->and(array_map(fn (MarketOffer $o) => $o->sellerId, $offers->offers))->toContain($h->ourSellerId());
            foreach ($offers->offers as $o) {
                expect($o->price->cents)->toBeInt()->toBeGreaterThan(0)
                    ->and($o->shipping->cents)->toBeInt()->toBeGreaterThanOrEqual(0)
                    ->and($o->rating)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
            }
        });

        it('flags at most one Buy Box winner, consistent with buyBoxSellerId', function () use ($harness) {
            $h = $harness();
            $offers = $h->adapter()->getItemOffers($h->knownAsin());
            $winners = array_values(array_filter($offers->offers, fn (MarketOffer $o) => $o->isBuyBoxWinner));

            expect(count($winners))->toBeLessThanOrEqual(1)
                ->and($winners[0]->sellerId ?? null)->toBe($offers->buyBoxSellerId);
        });

        it('reports an unknown ASIN as a 404 MarketApiException', function () use ($harness) {
            $h = $harness();
            try {
                $h->adapter()->getItemOffers('B0NOSUCHAS');
                test()->fail('expected MarketApiException');
            } catch (MarketApiException $e) {
                expect($e->status)->toBe(404)->and($e->isRetryable())->toBeFalse();
            }
        });

        it('applies a price update, visible in the next offers read', function () use ($harness) {
            $h = $harness();
            $current = MarketAdapterContract::ourOffer($h);
            $target = $current->price->plus(Money::cents(7));

            $result = $h->adapter()->updatePrice(new PriceUpdate($h->ourSku(), $target, (string) Str::uuid7()));

            expect($result->replayed)->toBeFalse()->and($result->submissionId)->not->toBe('')
                ->and(MarketAdapterContract::ourOffer($h)->price->cents)->toBe($target->cents);
        });

        it('is idempotent per idempotency key', function () use ($harness) {
            $h = $harness();
            $key = (string) Str::uuid7();
            $target = MarketAdapterContract::ourOffer($h)->price->plus(Money::cents(3));

            $first = $h->adapter()->updatePrice(new PriceUpdate($h->ourSku(), $target, $key));
            $h->adapter()->updatePrice(new PriceUpdate($h->ourSku(), $target->plus(Money::cents(1)), (string) Str::uuid7()));
            $replay = $h->adapter()->updatePrice(new PriceUpdate($h->ourSku(), $target, $key));

            expect($replay->submissionId)->toBe($first->submissionId)
                ->and($replay->replayed)->toBeTrue()
                // the replay did not re-apply the older price over the newer one
                ->and(MarketAdapterContract::ourOffer($h)->price->cents)->toBe($target->cents + 1);
        });

        it('rejects an unknown SKU as a 404', function () use ($harness) {
            $h = $harness();
            expect(fn () => $h->adapter()->updatePrice(new PriceUpdate('NO-SUCH-SKU', Money::cents(100), (string) Str::uuid7())))
                ->toThrow(MarketApiException::class);
        });

        it('delivers offer-change notifications at least once until acknowledged', function () use ($harness) {
            $h = $harness();
            $h->triggerOfferChange();

            $received = $h->adapter()->receiveNotifications(50, 1000);
            expect($received)->not->toBeEmpty();

            $ids = array_map(fn ($n) => $n->notificationId, $received);
            expect(array_unique($ids))->toHaveCount(count($ids));
            foreach ($received as $n) {
                expect($n->asin)->not->toBe('')
                    ->and($n->offers)->not->toBeEmpty()
                    ->and($n->lowestLanded['overall'])->toBeInt();
                $h->adapter()->acknowledge($n);
            }

            expect($h->adapter()->receiveNotifications(50, 0))->toBe([]);
        });

        it('notifies about our own price changes too', function () use ($harness) {
            $h = $harness();
            foreach ($h->adapter()->receiveNotifications(100, 0) as $n) {
                $h->adapter()->acknowledge($n);
            }
            $h->adapter()->updatePrice(new PriceUpdate($h->ourSku(), MarketAdapterContract::ourOffer($h)->price->plus(Money::cents(2)), (string) Str::uuid7()));

            $asins = array_map(fn ($n) => $n->asin, $h->adapter()->receiveNotifications(10, 1000));
            expect($asins)->toContain($h->knownAsin());
        });

        it('exposes a market clock that never runs backwards', function () use ($harness) {
            $h = $harness();
            $a = $h->adapter()->now();
            $h->triggerOfferChange();
            expect($h->adapter()->now() >= $a)->toBeTrue();
        });

        it('publishes a positive quota for every operation', function () use ($harness) {
            $h = $harness();
            foreach (Operation::cases() as $op) {
                $q = $h->adapter()->quota($op);
                expect($q->burst)->toBeGreaterThanOrEqual(1)->and($q->refillMs)->toBeGreaterThanOrEqual(1);
            }
        });
    }

    public static function ourOffer(AdapterHarness $h): MarketOffer
    {
        foreach ($h->adapter()->getItemOffers($h->knownAsin())->offers as $o) {
            if ($o->sellerId === $h->ourSellerId()) {
                return $o;
            }
        }
        throw new \RuntimeException('our offer is missing');
    }
}
