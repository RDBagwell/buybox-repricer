<?php

namespace App\Simulator\Engine;

use App\Support\Fulfillment;
use App\Support\Money;

/**
 * Emitted whenever an offer on a listing changes (price, or the Buy Box winner).
 *
 * Modelled on the spirit of the marketplace's ANY_OFFER_CHANGED notification (a summary of
 * lowest landed prices and the Buy Box, plus the current offers), but the shape is ours and
 * makes no claim of compatibility.
 *
 * The event id is assigned when the event is published (outside the deterministic engine),
 * so replaying a seed never produces colliding ids.
 */
final readonly class AnyOfferChanged
{
    /**
     * @param  list<array{seller: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int}>  $offers
     */
    public function __construct(
        public string $asin,
        public int $tick,
        public int $marketTimeMs,
        public string $triggerSellerId,
        public string $changeType,
        public array $offers,
        public ?string $buyBoxSellerId,
        public ?string $eventId = null,
    ) {}

    public static function fromListing(Listing $listing, int $tick, int $marketTimeMs, string $triggerSellerId, string $changeType): self
    {
        return new self(
            $listing->asin,
            $tick,
            $marketTimeMs,
            $triggerSellerId,
            $changeType,
            array_map(fn (SimOffer $o) => $o->toArray(), array_values($listing->offers)),
            $listing->buyBoxSellerId,
        );
    }

    public function withEventId(string $eventId): self
    {
        return new self($this->asin, $this->tick, $this->marketTimeMs, $this->triggerSellerId, $this->changeType, $this->offers, $this->buyBoxSellerId, $eventId);
    }

    /**
     * Lowest landed price per fulfilment channel and overall, in cents.
     *
     * @return array{overall: int|null, marketplace: int|null, merchant: int|null}
     */
    public function lowestLanded(): array
    {
        $lowest = ['overall' => null, 'marketplace' => null, 'merchant' => null];
        foreach ($this->offers as $o) {
            $landed = $o['price'] + $o['shipping'];
            $channel = Fulfillment::from($o['fulfillment'])->value;
            $lowest['overall'] = $lowest['overall'] === null ? $landed : min($lowest['overall'], $landed);
            $lowest[$channel] = $lowest[$channel] === null ? $landed : min($lowest[$channel], $landed);
        }

        return $lowest;
    }

    public function buyBoxLanded(): ?Money
    {
        foreach ($this->offers as $o) {
            if ($o['seller'] === $this->buyBoxSellerId) {
                return Money::cents($o['price'] + $o['shipping']);
            }
        }

        return null;
    }

    /**
     * Wire format (JSON-safe, integer cents).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $time = SimClock::fromMs($this->marketTimeMs)->format('Y-m-d\TH:i:s.v\Z');

        return [
            'notification_type' => 'ANY_OFFER_CHANGED',
            'notification_version' => '1.0',
            'notification_id' => $this->eventId,
            'event_time' => $time,
            'event_time_ms' => $this->marketTimeMs,
            'payload' => [
                'asin' => $this->asin,
                'change_trigger' => [
                    'seller_id' => $this->triggerSellerId,
                    'change_type' => $this->changeType,
                    'time_of_change' => $time,
                ],
                'summary' => [
                    'number_of_offers' => count($this->offers),
                    'lowest_landed' => $this->lowestLanded(),
                    'buybox' => [
                        'seller_id' => $this->buyBoxSellerId,
                        'landed' => $this->buyBoxLanded()?->cents,
                    ],
                ],
                'offers' => array_map(fn (array $o) => $o + [
                    'landed' => $o['price'] + $o['shipping'],
                    'is_buybox_winner' => $o['seller'] === $this->buyBoxSellerId,
                ], $this->offers),
            ],
        ];
    }
}
