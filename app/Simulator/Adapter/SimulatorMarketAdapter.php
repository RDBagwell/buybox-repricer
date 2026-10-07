<?php

namespace App\Simulator\Adapter;

use App\Repricer\Market\ItemOffers;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\MarketApiException;
use App\Repricer\Market\MarketOffer;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Market\Operation;
use App\Repricer\Market\PriceUpdate;
use App\Repricer\Market\PriceUpdateResult;
use App\Repricer\Market\Quota;
use App\Repricer\Market\ServiceUnavailableException;
use App\Repricer\Market\ThrottledException;
use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Delivery\RedisStreamNotifications;
use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SimClock;
use App\Simulator\Engine\Simulation;
use App\Simulator\Persistence\WorldRepository;
use App\Support\Fulfillment;
use App\Support\Money;
use App\Support\RateLimiting\TokenBucket;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * MarketAdapter backed by the simulator, with realism knobs: per-operation token-bucket quotas
 * (429 + Retry-After when exhausted) and seeded 429/503 fault injection.
 */
final class SimulatorMarketAdapter implements MarketAdapter
{
    /**
     * @param  array<string, array{burst: int, refill_ms: int}>  $quotas  keyed by Operation value
     */
    public function __construct(
        private readonly WorldRepository $worlds,
        private readonly Simulation $simulation,
        private readonly RedisStreamNotifications $notifications,
        private readonly NotificationPublisher $publisher,
        private readonly TokenBucket $buckets,
        private readonly FaultInjector $faults,
        private readonly string $ourSellerId,
        private readonly array $quotas,
        private readonly string $consumer = 'repricer-1',
    ) {}

    public function getItemOffers(string $asin): ItemOffers
    {
        $this->guard(Operation::GetItemOffers);

        $world = $this->worlds->load();
        $listing = $world->listing($asin)
            ?? throw new MarketApiException(Operation::GetItemOffers, 404, "ASIN {$asin} not found.");

        return new ItemOffers($asin, $this->offers($listing), $listing->buyBoxSellerId, $world->clock->now());
    }

    public function updatePrice(PriceUpdate $update): PriceUpdateResult
    {
        $this->guard(Operation::PatchListingsItem);

        if ($update->price->cents < 1) {
            throw new MarketApiException(Operation::PatchListingsItem, 400, 'Price must be positive.');
        }

        $replayed = $this->worlds->findPriceRequest($update->idempotencyKey);
        if ($replayed !== null) {
            return $this->result($replayed, true);
        }

        /** @var array{response: array<string, mixed>, event: AnyOfferChanged|null} $outcome */
        $outcome = $this->worlds->db()->transaction(function () use ($update) {
            $world = $this->worlds->load(lock: true);

            // Re-check under the lock: a concurrent retry may have applied it meanwhile.
            $replayed = $this->worlds->findPriceRequest($update->idempotencyKey);
            if ($replayed !== null) {
                return ['response' => $replayed, 'event' => null];
            }

            $asin = $this->worlds->findAsinForSku($this->ourSellerId, $update->sku)
                ?? throw new MarketApiException(Operation::PatchListingsItem, 404, "SKU {$update->sku} not found.");
            $listing = $world->listing($asin) ?? throw new MarketApiException(Operation::PatchListingsItem, 404, "Listing {$asin} not found.");

            $change = $this->simulation->applyPriceUpdate($listing, $this->ourSellerId, $update->price, $world->tick, $world->clock->nowMs);
            $offer = $listing->offer($this->ourSellerId);
            assert($offer !== null);
            $this->worlds->saveOffer($listing, $offer);

            $event = $change->event->withEventId((string) Str::uuid7());
            $this->worlds->recordEvent($event, $this->worlds->currentRun());

            $response = [
                'sku' => $update->sku,
                'status' => 'ACCEPTED',
                'submission_id' => (string) Str::uuid7(),
                'price' => $update->price->cents,
                'applied_at_ms' => $world->clock->nowMs,
                'buybox_seller_id' => $listing->buyBoxSellerId,
            ];
            $this->worlds->recordPriceRequest($update->idempotencyKey, $this->ourSellerId, $update->sku, $update->price, $world->clock->nowMs, $response);

            return ['response' => $response, 'event' => $event];
        });

        if ($outcome['event'] !== null) {
            $this->publisher->publish($outcome['event']);
        }

        return $this->result($outcome['response'], $outcome['event'] === null);
    }

    public function receiveNotifications(int $max = 10, int $waitMs = 0): array
    {
        $out = [];
        foreach ($this->notifications->read($this->consumer, $max, $waitMs) as $message) {
            $out[] = $this->toNotification($message['payload'], $message['id']);
        }

        return $out;
    }

    public function acknowledge(OfferChangeNotification $notification): void
    {
        $this->notifications->ack($notification->receiptHandle);
    }

    public function now(): DateTimeImmutable
    {
        return SimClock::fromMs($this->worlds->clockMs());
    }

    public function quota(Operation $operation): Quota
    {
        $q = $this->quotas[$operation->value] ?? ['burst' => 1, 'refill_ms' => 1000];

        return new Quota($q['burst'], $q['refill_ms']);
    }

    private function guard(Operation $operation): void
    {
        $quota = $this->quota($operation);
        $wait = $this->buckets->take('sim:quota:'.$operation->value, $quota->burst, $quota->refillMs);
        if ($wait > 0) {
            throw new ThrottledException($operation, 429, 'QuotaExceeded: You exceeded your quota for the requested resource.', $wait, ['errors' => [['code' => 'QuotaExceeded']]]);
        }

        $fault = $this->faults->roll();
        if ($fault !== null && $fault['status'] === 429) {
            throw new ThrottledException($operation, 429, 'QuotaExceeded (injected)', $fault['retry_after_ms'], ['errors' => [['code' => 'QuotaExceeded', 'injected' => true]]]);
        }
        if ($fault !== null) {
            throw new ServiceUnavailableException($operation, 503, 'ServiceUnavailable (injected)', $fault['retry_after_ms'], ['errors' => [['code' => 'ServiceUnavailable', 'injected' => true]]]);
        }
    }

    /**
     * @return list<MarketOffer>
     */
    private function offers(Listing $listing): array
    {
        $out = [];
        foreach ($listing->offers as $o) {
            $out[] = new MarketOffer($o->sellerId, $o->price, $o->shipping, $o->fulfillment, $o->rating, $o->handlingDays, $o->sellerId === $listing->buyBoxSellerId);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function result(array $response, bool $replayed): PriceUpdateResult
    {
        return new PriceUpdateResult(
            (string) $response['submission_id'],
            (string) $response['status'],
            SimClock::fromMs((int) $response['applied_at_ms']),
            $replayed,
            $response,
        );
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function toNotification(array $p, string $receipt): OfferChangeNotification
    {
        /** @var array{asin: string, change_trigger: array{seller_id: string, change_type: string}, summary: array{lowest_landed: array{overall: int|null, marketplace: int|null, merchant: int|null}, buybox: array{seller_id: string|null, landed: int|null}}, offers: list<array{seller: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int, is_buybox_winner: bool}>} $body */
        $body = $p['payload'];

        return new OfferChangeNotification(
            notificationId: (string) $p['notification_id'],
            asin: $body['asin'],
            eventTime: SimClock::fromMs((int) $p['event_time_ms']),
            offers: array_map(fn (array $o) => new MarketOffer(
                $o['seller'], Money::cents($o['price']), Money::cents($o['shipping']), Fulfillment::from($o['fulfillment']), $o['rating'], $o['handling_days'], $o['is_buybox_winner'],
            ), $body['offers']),
            lowestLanded: $body['summary']['lowest_landed'],
            buyBoxSellerId: $body['summary']['buybox']['seller_id'],
            buyBoxLanded: $body['summary']['buybox']['landed'] === null ? null : Money::cents($body['summary']['buybox']['landed']),
            triggerSellerId: $body['change_trigger']['seller_id'],
            changeType: $body['change_trigger']['change_type'],
            receiptHandle: $receipt,
        );
    }
}
