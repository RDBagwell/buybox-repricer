<?php

namespace App\Repricer\Market;

use App\Support\Money;
use DateTimeImmutable;

/**
 * An "any offer changed" notification as the repricer sees it.
 */
final readonly class OfferChangeNotification
{
    /**
     * @param  list<MarketOffer>  $offers
     * @param  array{overall: int|null, marketplace: int|null, merchant: int|null}  $lowestLanded  cents
     * @param  string  $receiptHandle  opaque token used to acknowledge this delivery
     */
    public function __construct(
        public string $notificationId,
        public string $asin,
        public DateTimeImmutable $eventTime,
        public array $offers,
        public array $lowestLanded,
        public ?string $buyBoxSellerId,
        public ?Money $buyBoxLanded,
        public string $triggerSellerId,
        public string $changeType,
        public string $receiptHandle = '',
    ) {}

    public function offerFrom(string $sellerId): ?MarketOffer
    {
        foreach ($this->offers as $offer) {
            if ($offer->sellerId === $sellerId) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * Serialisable form (for queued jobs and the audit log).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'asin' => $this->asin,
            'event_time' => $this->eventTime->format('Y-m-d\TH:i:s.vP'),
            'offers' => array_map(fn (MarketOffer $o) => $o->toArray(), $this->offers),
            'lowest_landed' => $this->lowestLanded,
            'buybox_seller_id' => $this->buyBoxSellerId,
            'buybox_landed' => $this->buyBoxLanded?->cents,
            'trigger_seller_id' => $this->triggerSellerId,
            'change_type' => $this->changeType,
            'receipt_handle' => $this->receiptHandle,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{seller_id: string, price: int, shipping: int, fulfillment: string, rating: int, handling_days: int, is_buybox_winner: bool}> $offers */
        $offers = $data['offers'];
        /** @var array{overall: int|null, marketplace: int|null, merchant: int|null} $lowest */
        $lowest = $data['lowest_landed'];

        return new self(
            (string) $data['notification_id'],
            (string) $data['asin'],
            new DateTimeImmutable((string) $data['event_time']),
            array_map(fn (array $o) => MarketOffer::fromArray($o), $offers),
            $lowest,
            isset($data['buybox_seller_id']) ? (string) $data['buybox_seller_id'] : null,
            isset($data['buybox_landed']) ? Money::cents((int) $data['buybox_landed']) : null,
            (string) $data['trigger_seller_id'],
            (string) $data['change_type'],
            (string) ($data['receipt_handle'] ?? ''),
        );
    }
}
