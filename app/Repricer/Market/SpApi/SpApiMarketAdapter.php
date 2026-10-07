<?php

namespace App\Repricer\Market\SpApi;

use App\Repricer\Market\ItemOffers;
use App\Repricer\Market\MarketAdapter;
use App\Repricer\Market\OfferChangeNotification;
use App\Repricer\Market\Operation;
use App\Repricer\Market\PriceUpdate;
use App\Repricer\Market\PriceUpdateResult;
use App\Repricer\Market\Quota;
use DateTimeImmutable;
use LogicException;

/**
 * SKELETON ONLY. UNTESTED. NOT WIRED INTO THE CONTAINER. NEVER RUN AGAINST A REAL ACCOUNT.
 *
 * Where a real Selling Partner API client would plug in. It shows the shape of the work, not a
 * working integration. It was written without access to Amazon's current documentation, and
 * every operation name, field and limit below must be checked against it before any of this is
 * implemented. Not affiliated with or endorsed by Amazon.
 *
 * To make it real, every method needs implementing and the adapter must pass the same contract
 * tests as the simulator (tests/Contract) against a sandbox account, before being bound in
 * MarketServiceProvider.
 *
 * Configuration it would need, from the environment only (never the database or the repo):
 *  - the regional SP-API endpoint, marketplace id and our seller id;
 *  - an LWA client id, client secret and the selling partner's refresh token, exchanged for
 *    short-lived access tokens (cached until shortly before expiry);
 *  - the SQS queue URL the ANY_OFFER_CHANGED subscription delivers to, and AWS credentials
 *    allowed to read and delete from it.
 */
final class SpApiMarketAdapter implements MarketAdapter
{
    /** Product Pricing API, getItemOffers for the ASIN and our marketplace: map to ItemOffers. */
    public function getItemOffers(string $asin): ItemOffers
    {
        throw $this->notImplemented('getItemOffers');
    }

    /**
     * Listings Items API, patchListingsItem setting the purchasable offer price for the SKU.
     * The API has no idempotency key of its own: idempotency must come from our side (the
     * price_pushes row keyed by PriceUpdate::$idempotencyKey, checked before sending), and a
     * 2xx response here means "accepted", not "applied". Confirm via a later offers read.
     */
    public function updatePrice(PriceUpdate $update): PriceUpdateResult
    {
        throw $this->notImplemented('updatePrice');
    }

    /**
     * ANY_OFFER_CHANGED notifications arrive via an SQS queue: ReceiveMessage with long polling
     * ($waitMs), parse each body into an OfferChangeNotification, keep the receipt handle.
     *
     * @return list<OfferChangeNotification>
     */
    public function receiveNotifications(int $max = 10, int $waitMs = 0): array
    {
        throw $this->notImplemented('receiveNotifications');
    }

    /** SQS DeleteMessage with the receipt handle, only after the decision is durably recorded. */
    public function acknowledge(OfferChangeNotification $notification): void
    {
        throw $this->notImplemented('acknowledge');
    }

    /** For a real marketplace, market time is the wall clock (UTC). */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    /** Per-operation rate limits come from the docs and the x-amzn-RateLimit-Limit header. */
    public function quota(Operation $operation): Quota
    {
        throw $this->notImplemented('quota');
    }

    private function notImplemented(string $method): LogicException
    {
        return new LogicException("SpApiMarketAdapter::{$method} is an untested skeleton and is not implemented.");
    }
}
