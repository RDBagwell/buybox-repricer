<?php

namespace App\Repricer\Market;

use DateTimeImmutable;

/**
 * The repricer's only view of a marketplace.
 *
 * Shaped after the relevant Selling Partner API operations, with DTOs of our own:
 *  - getItemOffers      ~ Product Pricing API `getItemOffers` (offers for an ASIN)
 *  - updatePrice        ~ Listings Items API `patchListingsItem` (set purchasable offer price)
 *  - receive/acknowledge ~ consuming ANY_OFFER_CHANGED notifications from a delivery queue
 *                          (SP-API delivers to SQS: receive, process, delete)
 *
 * Today the simulator implements it. A real SP-API client can implement it later and must
 * pass the same contract tests (tests/Contract).
 *
 * Errors: implementations throw MarketApiException (or a subclass) for non-2xx outcomes;
 * ThrottledException (429) and ServiceUnavailableException (503) carry Retry-After.
 */
interface MarketAdapter
{
    /**
     * @throws MarketApiException
     */
    public function getItemOffers(string $asin): ItemOffers;

    /**
     * Set our price for a SKU. Must be idempotent per PriceUpdate::$idempotencyKey: repeating a
     * request with the same key returns the original result and does not apply it again.
     *
     * @throws MarketApiException
     */
    public function updatePrice(PriceUpdate $update): PriceUpdateResult;

    /**
     * Pull up to $max pending offer-change notifications, waiting up to $waitMs for one to arrive.
     * Delivery is at-least-once: a notification not acknowledged within the visibility timeout
     * is delivered again.
     *
     * @return list<OfferChangeNotification>
     */
    public function receiveNotifications(int $max = 10, int $waitMs = 0): array;

    public function acknowledge(OfferChangeNotification $notification): void;

    /** Market time. Cooldowns and time windows in the repricer use this, not the wall clock. */
    public function now(): DateTimeImmutable;

    /** The request quota for an operation (token bucket: burst + one token per refill interval). */
    public function quota(Operation $operation): Quota;
}
